<?php

namespace App\MessageHandler;

use App\Entity\CampaignEmail;
use App\Entity\Contact;
use App\Message\SendCampaignEmailMessage;
use App\Service\AccountMailerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Oi\MailflowBundle\Enum\CampaignEmailStatus;
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
        #[Autowire('%oi_mailflow.max_retry_count%')]
        private readonly int $maxRetryCount,
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

        $dsn = $campaignEmail->getMailList()?->getMailerDsnOverride() ?? $account->getSmtpDsn();
        $mailer = $this->mailerFactory->createMailer($dsn);

        $body = $this->buildBody(
            $campaign->getBody() ?? '',
            $contact,
            $campaignEmail->getEmail(),
        );

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
}
