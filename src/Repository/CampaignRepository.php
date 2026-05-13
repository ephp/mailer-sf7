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
        ?string $search = null,
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

        if ($search !== null && trim($search) !== '') {
            $qb->andWhere('LOWER(q.name) LIKE :search')
               ->setParameter('search', '%' . mb_strtolower(trim($search)) . '%');
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

    /**
     * @return Campaign[]
     */
    public function findScheduledDue(\DateTimeInterface $now): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.status = :status')
            ->andWhere('q.scheduledAt <= :now')
            ->setParameter('status', 'scheduled')
            ->setParameter('now', $now)
            ->orderBy('q.scheduledAt', 'ASC')
            ->getQuery()
            ->getResult();
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

    /**
     * @param int[] $mailListIds
     * @return array<int, int> map mailListId => count of distinct campaigns
     *   associated to that list that have produced at least one sent email.
     *   We base the count on actual sent campaign_email rows rather than on
     *   Campaign.status, because the latter is only flipped to 'sent' once
     *   every queued message has been handled — campaigns half-sent or with
     *   leftover Pending rows would otherwise count as 0.
     */
    public function countSentByMailListIds(array $mailListIds): array
    {
        if ($mailListIds === []) {
            return [];
        }
        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($mailListIds), '?'));
        $sql = "SELECT cml.mail_list_id AS lid, COUNT(DISTINCT c.id) AS cnt
                FROM campaign c
                INNER JOIN campaign_mail_list cml ON cml.campaign_id = c.id
                INNER JOIN campaign_email ce ON ce.campaign_id = c.id
                WHERE cml.mail_list_id IN ($placeholders)
                  AND ce.status = 'sent'
                GROUP BY cml.mail_list_id";
        $rows = $conn->executeQuery($sql, array_values($mailListIds))->fetchAllAssociative();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['lid']] = (int) $r['cnt'];
        }
        return $map;
    }
}
