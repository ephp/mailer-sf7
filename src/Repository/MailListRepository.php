<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\MailList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MailList>
 */
class MailListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailList::class);
    }

    /**
     * @return MailList[]
     */
    public function findByAccount(Account $account): array
    {
        return $this->createQueryBuilder('ml')
            ->andWhere('ml.account = :account')
            ->andWhere('ml.deletedAt IS NULL')
            ->setParameter('account', $account)
            ->orderBy('ml.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByIdAndAccount(int $id, Account $account): ?MailList
    {
        return $this->createQueryBuilder('ml')
            ->andWhere('ml.id = :id')
            ->andWhere('ml.account = :account')
            ->andWhere('ml.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByAccountQuery(Account $account, ?string $search = null, string $sort = 'name', string $direction = 'asc'): QueryBuilder
    {
        $allowedSorts = ['name', 'createdAt', 'updatedAt'];
        $sortField = in_array($sort, $allowedSorts, true) ? $sort : 'name';
        $sortDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $qb = $this->createQueryBuilder('ml')
            ->andWhere('ml.account = :account')
            ->andWhere('ml.deletedAt IS NULL')
            ->setParameter('account', $account)
            ->orderBy('ml.' . $sortField, $sortDirection);

        if ($search !== null && $search !== '') {
            $qb->andWhere('ILIKE(ml.name, :search) = TRUE')
               ->setParameter('search', '%' . $search . '%');
        }

        return $qb;
    }

    /**
     * @return array<int, array{0: MailList, contactCount: string, activeCount: string}>
     */
    public function findByAccountWithContactCount(Account $account): array
    {
        return $this->createQueryBuilder('ml')
            ->select('ml', 'COUNT(c.id) AS contactCount', 'SUM(CASE WHEN c.iscritto = true THEN 1 ELSE 0 END) AS activeCount')
            ->leftJoin('ml.contacts', 'c')
            ->andWhere('ml.account = :account')
            ->andWhere('ml.deletedAt IS NULL')
            ->setParameter('account', $account)
            ->groupBy('ml.id')
            ->orderBy('ml.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('ml')
            ->select('COUNT(ml.id)')
            ->andWhere('ml.account = :account')
            ->andWhere('ml.deletedAt IS NULL')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneBySubscribeToken(string $token): ?MailList
    {
        return $this->createQueryBuilder('ml')
            ->where('ml.subscribeToken = :token')
            ->andWhere('ml.deletedAt IS NULL')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
