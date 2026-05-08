<?php

namespace App\Service;

use App\Entity\Campaign;
use App\Repository\CampaignEmailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class CampaignCompletionNotifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CampaignEmailRepository $campaignEmailRepository,
        private readonly AccountMailerFactory $mailerFactory,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire('%app_url%')]
        private readonly string $appUrl,
    ) {}

    public function notifyIfComplete(Campaign $campaign): bool
    {
        if ($campaign->getStatus() !== 'sending') {
            return false;
        }

        if ($this->campaignEmailRepository->hasUnfinished($campaign)) {
            return false;
        }

        $campaign->setStatus('sent');
        $campaign->setSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $account = $campaign->getAccount();
        if ($account === null || $account->getEmailContatto() === null || $account->getMailFrom() === null) {
            return true;
        }

        $counts = $this->campaignEmailRepository->countsByStatus($campaign);
        $total = $this->campaignEmailRepository->countByCampaign($campaign);

        $html = $this->twig->render('email/campaign-summary.html.twig', [
            'campaign' => $campaign,
            'account' => $account,
            'total' => $total,
            'counts' => $counts,
            'statsUrl' => $this->appUrl . '/campaigns/' . $campaign->getId() . '/stats',
        ]);

        $subject = 'Campagna ' . ($campaign->getName() ?? $campaign->getEmailSubject()) . ' completata';

        $email = (new Email())
            ->from(new Address($account->getMailFrom(), $account->getMailFromName() ?? ''))
            ->to($account->getEmailContatto())
            ->subject($subject)
            ->html($html);

        try {
            $mailer = $this->mailerFactory->createMailer($account);
            $mailer->send($email);
            $this->logger->info('Campaign completion notification sent', [
                'campaignId' => $campaign->getId(),
                'to' => $account->getEmailContatto(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send campaign completion notification', [
                'campaignId' => $campaign->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
