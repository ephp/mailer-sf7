<?php

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\LinkStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LinkStat>
 */
class LinkStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkStat::class);
    }

    public function findOneByToken(string $token): ?LinkStat
    {
        return $this->findOneBy(['trackingToken' => $token]);
    }

    public function countUniqueByCampaign(Campaign $campaign): int
    {
        return (int) $this->createQueryBuilder('ls')
            ->select('COUNT(DISTINCT IDENTITY(ls.campaignEmail))')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueByAccount(\App\Entity\Account $account): int
    {
        return (int) $this->createQueryBuilder('ls')
            ->select('COUNT(DISTINCT IDENTITY(ls.campaignEmail))')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->innerJoin('ce.campaign', 'c')
            ->where('c.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueByMailList(\App\Entity\MailList $mailList): int
    {
        return (int) $this->createQueryBuilder('ls')
            ->select('COUNT(DISTINCT IDENTITY(ls.campaignEmail))')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->where('ce.mailList = :mailList')
            ->setParameter('mailList', $mailList)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
