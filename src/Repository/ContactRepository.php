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

    /** @return Contact[] */
    public function findSubscribedByMailList(MailList $mailList): array
    {
        return $this->findBy(['mailList' => $mailList, 'iscritto' => true]);
    }

    public function countByMailList(MailList $mailList): int
    {
        return $this->count(['mailList' => $mailList]);
    }

    public function countSubscribedByMailList(MailList $mailList): int
    {
        return $this->count(['mailList' => $mailList, 'iscritto' => true]);
    }

    public function countByAccount(\App\Entity\Account $account): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->innerJoin('c.mailList', 'ml')
            ->where('ml.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSubscribedByAccount(\App\Entity\Account $account): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->innerJoin('c.mailList', 'ml')
            ->where('ml.account = :account')
            ->andWhere('c.iscritto = true')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param \App\Entity\MailList[] $mailLists
     * @param int[] $taxonomyTermIds
     */
    public function countRecipientsForLists(array $mailLists, array $taxonomyTermIds = []): int
    {
        if (empty($mailLists)) {
            return 0;
        }

        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(DISTINCT c.email)')
            ->where('c.mailList IN (:lists)')
            ->andWhere('c.iscritto = true')
            ->andWhere('c.bounceCount < 3')
            ->setParameter('lists', $mailLists);

        if (!empty($taxonomyTermIds)) {
            $subDql = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from(\App\Entity\ContactTaxonomy::class, 'ct')
                ->where('ct.contact = c')
                ->andWhere('ct.term IN (:termIds)')
                ->getDQL();

            $qb->andWhere($qb->expr()->exists($subDql))
               ->setParameter('termIds', $taxonomyTermIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
