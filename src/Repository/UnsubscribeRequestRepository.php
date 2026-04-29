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
}
