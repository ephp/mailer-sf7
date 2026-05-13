<?php

namespace App\MessageHandler;

use App\Entity\CampaignAttachment;
use App\Entity\CampaignEmail;
use App\Entity\LinkStat;
use App\Message\SendCampaignEmailMessage;
use App\Service\AccountMailerFactory;
use App\Service\EmailTemplateRenderer;
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
        private readonly EmailTemplateRenderer $renderer,
        #[Autowire('%ephp_mailflow.max_retry_count%')]
        private readonly int $maxRetryCount,
        #[Autowire('%app_url%')]
        private readonly string $appUrl,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir = '',
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

        $unsubUrl = null;
        if ($mailList?->isPermettiDisiscrizione() !== false) {
            $unsubUrl = $this->appUrl . '/unsubscribe/' . $campaignEmail->getUnsubscribeToken();
        }

        $webViewUrl = $this->appUrl . '/v/' . $campaignEmail->getTrackingOpenId();
        $openTrackingUrl = $this->appUrl . '/t/open/' . $campaignEmail->getTrackingOpenId();

        // Render the full email through the same template pipeline as the wizard
        // preview (Twig + inlined CSS). Without this, the worker would only send
        // the raw body field, with no layout. The open-tracking pixel is now
        // embedded inside the "Aprila nel browser" anchor in base.html.twig
        // rather than as a separate <img> before </body>.
        $rendered = $this->renderer->render($campaign, $contact, $unsubUrl, $webViewUrl, $openTrackingUrl);
        $body = $rendered->html;

        $body = $this->injectLinkTracking($body, $campaignEmail);

        $campaignEmail->setHtml($body);

        // Persist LinkStat records (and the rendered HTML) before sending so
        // click links resolve and the audit copy is durable even on send failure.
        $this->em->flush();

        $fromAddress = $mailList?->getEffectiveMailFrom() ?? $account->getMailFrom();
        $fromName = $mailList?->getEffectiveMailFromName() ?? $account->getMailFromName();

        $email = (new Email())
            ->from(new Address($fromAddress, $fromName))
            ->to($campaignEmail->getEmail())
            ->subject($campaign->getEmailSubject())
            ->html($body);

        // Attach every CampaignAttachment of the campaign as a real file part.
        // The Upload URL (relative path like /uploads/campaign_attachment/...)
        // is resolved against the public/ directory on disk.
        $attachments = $this->em->getRepository(CampaignAttachment::class)
            ->findBy(['campaign' => $campaign]);
        foreach ($attachments as $att) {
            $relativeUrl = $att->getUpload()?->getUrl();
            if ($relativeUrl === null || $relativeUrl === '') {
                continue;
            }
            $absPath = $this->projectDir . '/public' . $relativeUrl;
            if (!is_file($absPath)) {
                continue;
            }
            $email->attachFromPath($absPath, $att->getFilename(), $att->getMimetype() ?? 'application/octet-stream');
        }

        // Tell mail clients (Gmail in particular) the message is in Italian, so
        // they don't show the "this message appears to be in English" banner.
        $email->getHeaders()->addTextHeader('Content-Language', 'it');

        if ($unsubUrl !== null) {
            $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubUrl . '>');
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        try {
            $mailer->send($email);
            $campaignEmail->setStatus(CampaignEmailStatus::Sent);
            $campaignEmail->setSentAt(new \DateTime());
            $this->em->flush();
        } catch (TransportExceptionInterface $e) {
            $errorMessage = substr($e->getMessage(), 0, 500);
            $campaignEmail->incrementRetryCount();
            $campaignEmail->setErrorMessage($errorMessage);

            if ($campaignEmail->getRetryCount() >= $this->maxRetryCount) {
                $campaignEmail->setStatus(CampaignEmailStatus::Bounced);
                if ($contact !== null) {
                    $contact->incrementBounceCount();
                    if ($contact->getBounceCount() >= 3) {
                        $contact->unsubscribe(sprintf(
                            'auto:bounce_threshold (%d bounces) — ultimo errore: %s',
                            $contact->getBounceCount(),
                            $errorMessage,
                        ));
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

    private function injectLinkTracking(string $body, CampaignEmail $campaignEmail): string
    {
        $pattern = '#(<a\b[^>]*\bhref=)(["\'])((https?://)[^"\']+)\2#i';

        // Internal links (unsubscribe page, web-view, tracking endpoints) must
        // not be wrapped by /t/click — they are technical links, not real
        // content, and link scanners hit them as part of preview/security
        // checks (inflating the click count, and easily pushing click rate
        // above 100%).
        $internalPrefixes = [
            $this->appUrl . '/unsubscribe/',
            $this->appUrl . '/v/',
            $this->appUrl . '/t/',
        ];

        $result = preg_replace_callback(
            $pattern,
            function (array $matches) use ($campaignEmail, $internalPrefixes): string {
                $originalUrl = $matches[3];
                foreach ($internalPrefixes as $prefix) {
                    if (str_starts_with($originalUrl, $prefix)) {
                        return $matches[0];
                    }
                }
                $token = $this->generateUuid();
                $linkStat = new LinkStat($originalUrl, $token, new \DateTime());
                $linkStat->setCampaignEmail($campaignEmail);
                $this->em->persist($linkStat);
                return $matches[1] . $matches[2] . $this->appUrl . '/t/click/' . $token . $matches[2];
            },
            $body
        );

        return $result ?? $body;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
