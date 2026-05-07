<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Campaign;
use App\Entity\MailList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Oi\HelperBundle\Repository\Traits\ORMRepositoryTrait;

class CampaignRepository extends ServiceEntityRepository
{
    use ORMRepositoryTrait;

    private const SORT_WHITELIST = ['name', 'status', 'scheduledAt', 'sentAt', 'createdAt', 'updatedAt'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campaign::class);
    }

    public function findOneByIdAndAccount(int $id, Account $account): ?Campaign
    {
        return $this->createQueryBuilder('q')
            ->where('q.id = :id')
            ->andWhere('q.account = :account')
            ->setParameter('id', $id)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByAccountQuery(
        Account $account,
        ?string $status = null,
        ?bool $template = false,
        string $sort = 'createdAt',
        string $direction = 'desc',
    ): QueryBuilder {
        if (!in_array($sort, self::SORT_WHITELIST, true)) {
            $sort = 'createdAt';
        }

        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        $qb = $this->createQueryBuilder('q')
            ->where('q.account = :account')
            ->andWhere('q.template = :template')
            ->setParameter('account', $account)
            ->setParameter('template', $template ?? false)
            ->orderBy('q.'.$sort, $direction);

        if ($status !== null) {
            $qb->andWhere('q.status = :status')
               ->setParameter('status', $status);
        }

        return $qb;
    }

    /** @return Campaign[] */
    public function findTemplates(Account $account): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.account = :account')
            ->andWhere('q.template = true')
            ->setParameter('account', $account)
            ->orderBy('q.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countNonTemplateByAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.account = :account')
            ->andWhere('c.template = false')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveCampaignsByMailList(MailList $mailList): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.mailLists', 'ml')
            ->where('ml = :mailList')
            ->andWhere('c.scheduledAt IS NOT NULL')
            ->andWhere('c.sentAt IS NULL')
            ->setParameter('mailList', $mailList)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
