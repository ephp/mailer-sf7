<?php

namespace App\MessageHandler;

use App\Entity\CampaignEmail;
use App\Entity\Contact;
use App\Entity\LinkStat;
use App\Message\SendCampaignEmailMessage;
use App\Service\AccountMailerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SendCampaignEmailMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountMailerFactory $mailerFactory,
        #[Autowire('%ephp_mailflow.max_retry_count%')]
        private readonly int $maxRetryCount,
        #[Autowire('%app_url%')]
        private readonly string $appUrl,
    ) {}

    public function __invoke(SendCampaignEmailMessage $message): void
    {
        $campaignEmail = $this->em->find(CampaignEmail::class, $message->getCampaignEmailId());

        if ($campaignEmail === null || $campaignEmail->getStatus() === CampaignEmailStatus::Sent) {
            return;
        }

        // pending → sending at pickup
        $campaignEmail->setStatus(CampaignEmailStatus::Sending);
        $this->em->flush();

        $campaign = $campaignEmail->getCampaign();
        $contact = $campaignEmail->getContact();
        $account = $campaign->getAccount();
        $mailList = $campaignEmail->getMailList();

        $mailer = $this->mailerFactory->createMailer($account, $mailList?->getEffectiveDsn());

        $body = $this->buildBody(
            $campaign->getBody() ?? '',
            $contact,
            $campaignEmail->getEmail(),
        );

        $body = $this->injectLinkTracking($body, $campaignEmail);
        $body = $this->injectOpenPixel($body, $campaignEmail);

        $unsubUrl = null;
        if ($mailList?->isPermettiDisiscrizione() !== false) {
            $unsubUrl = $this->appUrl . '/unsubscribe/' . $campaignEmail->getUnsubscribeToken();
            $body = $this->injectUnsubscribeLink($body, $unsubUrl);
        }

        // Persist LinkStat records before sending so click links resolve
        $this->em->flush();

        $fromAddress = $mailList?->getEffectiveMailFrom() ?? $account->getMailFrom();
        $fromName = $mailList?->getEffectiveMailFromName() ?? $account->getMailFromName();

        $email = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to($campaignEmail->getEmail())
            ->subject($campaign->getEmailSubject())
            ->html($body);

        if ($unsubUrl !== null) {
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubUrl . '>');
        }

        try {
            $mailer->send($email);
            $campaignEmail->setStatus(CampaignEmailStatus::Sent);
            $campaignEmail->setSentAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (TransportExceptionInterface $e) {
            $campaignEmail->incrementRetryCount();
            $campaignEmail->setErrorMessage(substr($e->getMessage(), 0, 500));

            if ($campaignEmail->getRetryCount() >= $this->maxRetryCount) {
                $campaignEmail->setStatus(CampaignEmailStatus::Bounced);
                if ($contact !== null) {
                    $contact->incrementBounceCount();
                    if ($contact->getBounceCount() >= 3) {
                        $contact->setIscritto(false);
                    }
                }
                $this->em->flush();
                return;
            }

            $campaignEmail->setStatus(CampaignEmailStatus::Failed);
            $this->em->flush();
            throw $e;
        }
    }

    private function buildBody(string $template, ?Contact $contact, string $recipientEmail): string
    {
        return str_replace(
            ['[[nome]]', '[[cognome]]', '[[email]]'],
            [$contact?->getNome() ?? '', $contact?->getCognome() ?? '', $recipientEmail],
            $template,
        );
    }

    private function injectLinkTracking(string $body, CampaignEmail $campaignEmail): string
    {
        $pattern = '#(<a\b[^>]*\bhref=)(["\'])((https?://)[^"\']+)\2#i';

        $result = preg_replace_callback(
            $pattern,
            function (array $matches) use ($campaignEmail): string {
                $originalUrl = $matches[3];
                $token = $this->generateUuid();
                $linkStat = new LinkStat($originalUrl, $token, new \DateTimeImmutable());
                $linkStat->setCampaignEmail($campaignEmail);
                $this->em->persist($linkStat);
                return $matches[1] . $matches[2] . $this->appUrl . '/t/click/' . $token . $matches[2];
            },
            $body
        );

        return $result ?? $body;
    }

    private function injectOpenPixel(string $body, CampaignEmail $campaignEmail): string
    {
        $pixelUrl = $this->appUrl . '/t/open/' . $campaignEmail->getTrackingOpenId();
        $pixel = '<img src="' . $pixelUrl . '" width="1" height="1" style="display:none;" />';

        if (str_contains($body, '</body>')) {
            return str_replace('</body>', $pixel . '</body>', $body);
        }

        return $body . $pixel;
    }

    private function injectUnsubscribeLink(string $body, string $unsubUrl): string
    {
        return str_replace('{{unsubscribe_url}}', $unsubUrl, $body);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
