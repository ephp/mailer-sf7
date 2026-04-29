<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\MailList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        return $this->findBy(['account' => $account], ['name' => 'ASC']);
    }
}
