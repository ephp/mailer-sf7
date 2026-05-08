<?php

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Entity\UnsubscribeRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UnsubscribeRequest>
 */
class UnsubscribeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnsubscribeRequest::class);
    }

    public function findOneByCampaignEmail(CampaignEmail $campaignEmail): ?UnsubscribeRequest
    {
        return $this->findOneBy(['campaignEmail' => $campaignEmail]);
    }

    /**
     * @param int[] $ids
     * @return array<int, true>
     */
    public function findSetByCampaignEmailIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $requests = $this->findBy(['campaignEmail' => $ids]);

        $set = [];
        foreach ($requests as $ur) {
            $ceId = $ur->getCampaignEmailId();
            if ($ceId !== null) {
                $set[$ceId] = true;
            }
        }

        return $set;
    }

    public function countByCampaign(Campaign $campaign): int
    {
        return (int) $this->createQueryBuilder('ur')
            ->select('COUNT(ur.id)')
            ->innerJoin('ur.campaignEmail', 'ce')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByAccount(\App\Entity\Account $account): int
    {
        return (int) $this->createQueryBuilder('ur')
            ->select('COUNT(ur.id)')
            ->innerJoin('ur.campaignEmail', 'ce')
            ->innerJoin('ce.campaign', 'c')
            ->where('c.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByMailList(\App\Entity\MailList $mailList): int
    {
        return (int) $this->createQueryBuilder('ur')
            ->select('COUNT(ur.id)')
            ->innerJoin('ur.campaignEmail', 'ce')
            ->where('ce.mailList = :mailList')
            ->setParameter('mailList', $mailList)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
