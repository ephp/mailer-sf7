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

        $campaign = $campaignEmail->getCampaign();
        $contact = $campaignEmail->getContact();
        $account = $campaign->getAccount();
        $mailList = $campaignEmail->getMailList();

        $mailer = $this->mailerFactory->createMailer($account, $mailList?->getMailerDsnOverride());

        $body = $this->buildBody(
            $campaign->getBody() ?? '',
            $contact,
            $campaignEmail->getEmail(),
        );

        $body = $this->injectLinkTracking($body, $campaignEmail);
        $body = $this->injectOpenPixel($body, $campaignEmail);

        if ($mailList?->isPermettiDisiscrizione() !== false) {
            $body = $this->injectUnsubscribeLink($body, $campaignEmail);
        }

        // Persist LinkStat records before sending so click links resolve
        $this->em->flush();

        $fromAddress = $account->getSmtpUser() ?? 'noreply@example.com';
        $fromName = $account->getRagioneSociale() ?? $fromAddress;

        $email = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to($campaignEmail->getEmail())
            ->subject($campaign->getEmailSubject())
            ->html($body);

        try {
            $mailer->send($email);
            $campaignEmail->setStatus(CampaignEmailStatus::Sent);
            $campaignEmail->setSentAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (TransportExceptionInterface $e) {
            $campaignEmail->incrementRetryCount();
            $campaignEmail->setErrorMessage(substr($e->getMessage(), 0, 500));
            $this->em->flush();

            if ($campaignEmail->getRetryCount() >= $this->maxRetryCount) {
                $campaignEmail->setStatus(CampaignEmailStatus::Failed);
                $this->em->flush();
                return;
            }

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
        $pixel = '<img src="' . $pixelUrl . '" width="1" height="1" alt="" style="display:none">';

        if (str_contains($body, '</body>')) {
            return str_replace('</body>', $pixel . '</body>', $body);
        }

        return $body . $pixel;
    }

    private function injectUnsubscribeLink(string $body, CampaignEmail $campaignEmail): string
    {
        $unsubUrl = $this->appUrl . '/unsubscribe/' . $campaignEmail->getTrackingOpenId();
        $unsubHtml = '<p style="text-align:center;font-size:11px;color:#aaa;margin-top:24px">'
            . '<a href="' . $unsubUrl . '" style="color:#aaa">Clicca qui per disiscriverti</a>'
            . '</p>';

        if (str_contains($body, '</body>')) {
            return str_replace('</body>', $unsubHtml . '</body>', $body);
        }

        return $body . $unsubHtml;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
