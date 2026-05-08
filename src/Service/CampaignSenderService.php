<?php

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Repository\CampaignEmailRepository;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;

class CampaignSenderService
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
        private readonly CampaignEmailRepository $campaignEmailRepository,
        private readonly EntityManagerInterface $em,
    ) {}

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
