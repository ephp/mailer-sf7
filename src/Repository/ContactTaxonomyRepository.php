<?php

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\ContactTaxonomy;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactTaxonomy>
 */
class ContactTaxonomyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactTaxonomy::class);
    }

    /** @return ContactTaxonomy[] */
    public function findByContact(Contact $contact): array
    {
        return $this->findBy(['contact' => $contact]);
    }

    /** @return int[] term IDs assigned to this contact */
    public function findTermIdsByContact(Contact $contact): array
    {
        $rows = $this->createQueryBuilder('ct')
            ->select('IDENTITY(ct.term) as term_id')
            ->where('ct.contact = :contact')
            ->setParameter('contact', $contact)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'term_id');
    }
}
