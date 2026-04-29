<?php

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\MailList;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    public function findByEmailAndMailList(string $email, MailList $mailList): ?Contact
    {
        return $this->findOneBy(['email' => $email, 'mailList' => $mailList]);
    }
}
