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

    /**
     * @param int[] $mailListIds
     * @return array{0: array<int,int>, 1: array<int,int>} [totalByListId, activeByListId]
     */
    public function countsByMailListIds(array $mailListIds): array
    {
        if ($mailListIds === []) {
            return [[], []];
        }
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.mailList) AS lid, c.iscritto AS iscritto, COUNT(c.id) AS cnt')
            ->where('c.mailList IN (:ids)')
            ->setParameter('ids', $mailListIds)
            ->groupBy('c.mailList', 'c.iscritto')
            ->getQuery()
            ->getArrayResult();
        $total = [];
        $active = [];
        foreach ($rows as $r) {
            $lid = (int) $r['lid'];
            $cnt = (int) $r['cnt'];
            $total[$lid] = ($total[$lid] ?? 0) + $cnt;
            if ($r['iscritto']) {
                $active[$lid] = ($active[$lid] ?? 0) + $cnt;
            }
        }
        return [$total, $active];
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
     * Counts unique recipients across the given mail lists, applying taxonomy filters with
     * AND across categories and OR within the same category.
     *
     * Example: termIds=[1=Livorno, 2=Pisa, 3=Sito internet] where 1,2 are in "Area Geografica"
     * and 3 is in "Canale Acquisizione" → contacts in (Livorno OR Pisa) AND (Sito internet).
     *
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
            $termRepo = $this->getEntityManager()->getRepository(\App\Entity\TaxonomyTerm::class);
            /** @var \App\Entity\TaxonomyTerm[] $terms */
            $terms = $termRepo->findBy(['id' => $taxonomyTermIds]);

            // Group term IDs by their category to apply OR within / AND across.
            $idsByCategory = [];
            foreach ($terms as $term) {
                $catId = $term->getCategoryId();
                if ($catId === null) {
                    continue;
                }
                $idsByCategory[$catId][] = $term->getId();
            }

            $idx = 0;
            foreach ($idsByCategory as $idsInCategory) {
                $alias = 'ct' . $idx;
                $param = 'termIds' . $idx;
                $subDql = $this->getEntityManager()->createQueryBuilder()
                    ->select('1')
                    ->from(\App\Entity\ContactTaxonomy::class, $alias)
                    ->where("{$alias}.contact = c")
                    ->andWhere("{$alias}.term IN (:{$param})")
                    ->getDQL();

                $qb->andWhere($qb->expr()->exists($subDql))
                   ->setParameter($param, $idsInCategory);
                $idx++;
            }
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns unique Contact recipients across the given mail lists, applying the same
     * taxonomy filters as countRecipientsForLists (AND across categories, OR within).
     * When multiple contacts share the same email, only the one with the lowest ID is returned.
     * Results are ordered by email for stability.
     *
     * @param \App\Entity\MailList[] $mailLists
     * @param int[] $taxonomyTermIds
     * @return Contact[]
     */
    public function findRecipientsForLists(array $mailLists, array $taxonomyTermIds = []): array
    {
        if (empty($mailLists)) {
            return [];
        }

        $qb = $this->createQueryBuilder('c')
            ->where('c.mailList IN (:lists)')
            ->andWhere('c.iscritto = true')
            ->andWhere('c.bounceCount < 3')
            ->orderBy('c.email', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->setParameter('lists', $mailLists);

        if (!empty($taxonomyTermIds)) {
            $termRepo = $this->getEntityManager()->getRepository(\App\Entity\TaxonomyTerm::class);
            /** @var \App\Entity\TaxonomyTerm[] $terms */
            $terms = $termRepo->findBy(['id' => $taxonomyTermIds]);

            $idsByCategory = [];
            foreach ($terms as $term) {
                $catId = $term->getCategoryId();
                if ($catId === null) {
                    continue;
                }
                $idsByCategory[$catId][] = $term->getId();
            }

            $idx = 0;
            foreach ($idsByCategory as $idsInCategory) {
                $alias = 'ct' . $idx;
                $param = 'termIds' . $idx;
                $subDql = $this->getEntityManager()->createQueryBuilder()
                    ->select('1')
                    ->from(\App\Entity\ContactTaxonomy::class, $alias)
                    ->where("{$alias}.contact = c")
                    ->andWhere("{$alias}.term IN (:{$param})")
                    ->getDQL();

                $qb->andWhere($qb->expr()->exists($subDql))
                   ->setParameter($param, $idsInCategory);
                $idx++;
            }
        }

        /** @var Contact[] $contacts */
        $contacts = $qb->getQuery()->getResult();

        // Deduplicate by email: keep the first contact per email (lowest ID due to ORDER BY id ASC).
        $seen = [];
        $result = [];
        foreach ($contacts as $contact) {
            $email = $contact->getEmail();
            if ($email !== null && !isset($seen[$email])) {
                $seen[$email] = true;
                $result[] = $contact;
            }
        }

        return $result;
    }
}
