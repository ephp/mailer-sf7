<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Entity\MailList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;

/**
 * @extends ServiceEntityRepository<CampaignEmail>
 */
class CampaignEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampaignEmail::class);
    }

    /** @return CampaignEmail[] */
    public function findByCampaignAndStatus(Campaign $campaign, CampaignEmailStatus $status): array
    {
        return $this->findBy(['campaign' => $campaign, 'status' => $status]);
    }

    public function countByCampaign(Campaign $campaign): int
    {
        return $this->count(['campaign' => $campaign]);
    }

    public function countByCampaignAndStatus(Campaign $campaign, CampaignEmailStatus $status): int
    {
        return $this->count(['campaign' => $campaign, 'status' => $status]);
    }

    public function countByAccountAndStatus(Account $account, CampaignEmailStatus $status): int
    {
        return (int) $this->createQueryBuilder('ce')
            ->select('COUNT(ce.id)')
            ->innerJoin('ce.campaign', 'c')
            ->where('c.account = :account')
            ->andWhere('ce.status = :status')
            ->setParameter('account', $account)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByMailListAndStatus(MailList $mailList, CampaignEmailStatus $status): int
    {
        return (int) $this->createQueryBuilder('ce')
            ->select('COUNT(ce.id)')
            ->where('ce.mailList = :mailList')
            ->andWhere('ce.status = :status')
            ->setParameter('mailList', $mailList)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
