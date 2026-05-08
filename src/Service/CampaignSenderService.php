<?php

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Message\SendCampaignEmailMessage;
use App\Repository\CampaignEmailRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class CampaignSenderService
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly CampaignEmailRepository $campaignEmailRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {}

    public function dispatchAll(Campaign $campaign): int
    {
        $account = $campaign->getAccount();
        $batchSize = $account?->getBatchSize() ?? 50;
        $sendInterval = $account?->getSendInterval() ?? 30;

        $pending = $this->campaignEmailRepository->findByCampaignAndStatus(
            $campaign,
            CampaignEmailStatus::Pending
        );

        $dispatched = 0;

        foreach ($pending as $i => $campaignEmail) {
            $delaySeconds = (int) floor($i / $batchSize) * $sendInterval;
            $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
            $this->bus->dispatch(new SendCampaignEmailMessage($campaignEmail->getId()), $stamps);
            ++$dispatched;
        }

        $this->logger->info('Dispatched {n} messages for campaign {id} with batchSize={bs} sendInterval={si}', [
            'n' => $dispatched,
            'id' => $campaign->getId(),
            'bs' => $batchSize,
            'si' => $sendInterval,
        ]);

        return $dispatched;
    }

    public function prepareCampaign(Campaign $campaign): int
    {
        $mailLists = $campaign->getMailLists()->toArray();
        $filter = $campaign->getFilter() ?? [];
        $taxonomyTermIds = array_map('intval', (array) ($filter['taxonomyTermIds'] ?? []));

        $contacts = $this->contactRepository->findRecipientsForLists($mailLists, $taxonomyTermIds);

        $existingEmails = array_flip(
            $this->campaignEmailRepository->findExistingEmailsByCampaign($campaign)
        );

        $created = 0;
        $batchSize = 100;

        foreach ($contacts as $contact) {
            $email = (string) $contact->getEmail();

            if (isset($existingEmails[$email])) {
                continue;
            }

            $campaignEmail = new CampaignEmail();
            $campaignEmail->setEmail($email);
            $campaignEmail->setContact($contact);
            $campaignEmail->setMailList($contact->getMailList());
            $campaignEmail->setCampaign($campaign);
            $campaignEmail->setStatus(CampaignEmailStatus::Pending);

            $this->em->persist($campaignEmail);
            $existingEmails[$email] = true;
            ++$created;

            if ($created % $batchSize === 0) {
                $this->em->flush();
            }
        }

        if ($created > 0 && $created % $batchSize !== 0) {
            $this->em->flush();
        }

        return $created;
    }

    public function countRecipients(Campaign $campaign): int
    {
        $mailLists = $campaign->getMailLists()->toArray();
        $filter = $campaign->getFilter() ?? [];
        $taxonomyTermIds = array_map('intval', (array) ($filter['taxonomyTermIds'] ?? []));

        return $this->contactRepository->countRecipientsForLists($mailLists, $taxonomyTermIds);
    }
}
