<?php

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Entity\OpenStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpenStat>
 */
class OpenStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpenStat::class);
    }

    public function findOneByCampaignEmail(CampaignEmail $campaignEmail): ?OpenStat
    {
        return $this->findOneBy(['campaignEmail' => $campaignEmail]);
    }

    public function countUniqueByCampaign(Campaign $campaign): int
    {
        return (int) $this->createQueryBuilder('os')
            ->select('COUNT(DISTINCT IDENTITY(os.campaignEmail))')
            ->innerJoin('os.campaignEmail', 'ce')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueByAccount(\App\Entity\Account $account): int
    {
        return (int) $this->createQueryBuilder('os')
            ->select('COUNT(DISTINCT IDENTITY(os.campaignEmail))')
            ->innerJoin('os.campaignEmail', 'ce')
            ->innerJoin('ce.campaign', 'c')
            ->where('c.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueByMailList(\App\Entity\MailList $mailList): int
    {
        return (int) $this->createQueryBuilder('os')
            ->select('COUNT(DISTINCT IDENTITY(os.campaignEmail))')
            ->innerJoin('os.campaignEmail', 'ce')
            ->where('ce.mailList = :mailList')
            ->setParameter('mailList', $mailList)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
