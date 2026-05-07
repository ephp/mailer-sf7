<?php

namespace App\Repository;

use App\Entity\MailList;
use App\Entity\TaxonomyCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxonomyCategory>
 */
class TaxonomyCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxonomyCategory::class);
    }

    /** @return TaxonomyCategory[] */
    public function findByMailList(MailList $mailList): array
    {
        return $this->findBy(['mailList' => $mailList], ['name' => 'ASC']);
    }
}
