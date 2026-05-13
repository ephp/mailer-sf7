<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Entity\Contact;
use App\Entity\LinkStat;
use App\Entity\MailList;
use App\Entity\OpenStat;
use App\Entity\UnsubscribeRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
    public function findByCampaignAndStatus(Campaign $campaign, CampaignEmailStatus $status, ?int $limit = null): array
    {
        return $this->findBy(['campaign' => $campaign, 'status' => $status], null, $limit);
    }

    /** @return array<string, int> */
    public function countsByStatus(Campaign $campaign): array
    {
        $rows = $this->createQueryBuilder('ce')
            ->select('ce.status, COUNT(ce.id) as cnt')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->groupBy('ce.status')
            ->getQuery()
            ->getResult();

        $counts = array_fill_keys(
            array_map(fn(CampaignEmailStatus $s) => $s->value, CampaignEmailStatus::cases()),
            0
        );
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function hasUnfinished(Campaign $campaign): bool
    {
        return (int) $this->createQueryBuilder('ce')
            ->select('COUNT(ce.id)')
            ->where('ce.campaign = :campaign')
            ->andWhere('ce.status IN (:statuses)')
            ->setParameter('campaign', $campaign)
            ->setParameter('statuses', [CampaignEmailStatus::Pending, CampaignEmailStatus::Sending])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countByCampaign(Campaign $campaign): int
    {
        return $this->count(['campaign' => $campaign]);
    }

    public function countByCampaignAndStatus(Campaign $campaign, CampaignEmailStatus $status): int
    {
        return $this->count(['campaign' => $campaign, 'status' => $status]);
    }

    /**
     * @param int[] $contactIds
     * @return array<int, int> map contactId => count of campaign_email rows with status='sent'
     */
    public function countSentByContactIds(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('ce')
            ->select('IDENTITY(ce.contact) AS cid, COUNT(ce.id) AS c')
            ->where('ce.contact IN (:ids)')
            ->andWhere('ce.status = :status')
            ->setParameter('ids', $contactIds)
            ->setParameter('status', CampaignEmailStatus::Sent)
            ->groupBy('ce.contact')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['cid']] = (int) $r['c'];
        }
        return $map;
    }

    /**
     * @param int[] $contactIds
     * @return array<int, int> map contactId => count of campaign_email rows with status in (Bounced, Failed)
     */
    public function countBouncedOrFailedByContactIds(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('ce')
            ->select('IDENTITY(ce.contact) AS cid, COUNT(ce.id) AS c')
            ->where('ce.contact IN (:ids)')
            ->andWhere('ce.status IN (:statuses)')
            ->setParameter('ids', $contactIds)
            ->setParameter('statuses', [CampaignEmailStatus::Bounced, CampaignEmailStatus::Failed])
            ->groupBy('ce.contact')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['cid']] = (int) $r['c'];
        }
        return $map;
    }

    /**
     * @return list<array{id:int, campaign_id:int, campaign_name:?string, status:string, error_message:?string, retry_count:int, sent_at:?string, created_at:?string}>
     */
    public function findBouncedOrFailedByContact(Contact $contact): array
    {
        $rows = $this->createQueryBuilder('ce')
            ->select(
                'ce.id AS id',
                'IDENTITY(ce.campaign) AS campaign_id',
                'c.name AS campaign_name',
                'ce.status AS status',
                'ce.errorMessage AS error_message',
                'ce.retryCount AS retry_count',
                'ce.sentAt AS sent_at',
            )
            ->innerJoin('ce.campaign', 'c')
            ->where('ce.contact = :contact')
            ->andWhere('ce.status IN (:statuses)')
            ->setParameter('contact', $contact)
            ->setParameter('statuses', [CampaignEmailStatus::Bounced, CampaignEmailStatus::Failed])
            ->orderBy('ce.id', 'DESC')
            ->getQuery()
            ->getArrayResult();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'campaign_id' => (int) $r['campaign_id'],
                'campaign_name' => $r['campaign_name'] ?? null,
                'status' => is_object($r['status']) ? $r['status']->value : (string) $r['status'],
                'error_message' => $r['error_message'] ?? null,
                'retry_count' => (int) $r['retry_count'],
                'sent_at' => $r['sent_at'] instanceof \DateTimeInterface ? $r['sent_at']->format(\DateTimeInterface::ATOM) : null,
            ];
        }
        return $out;
    }

    /**
     * @param int[] $mailListIds
     * @return array<int, int> map mailListId => count of campaign_email rows matching $status
     *   that belong to a campaign associated to that list.
     *   Note: we group via the campaign↔list junction rather than
     *   campaign_email.mail_list_id, so multi-list campaigns are credited to
     *   every list they were sent to (and single-list runs are unchanged).
     */
    public function countByMailListIdsAndStatus(array $mailListIds, CampaignEmailStatus $status): array
    {
        if ($mailListIds === []) {
            return [];
        }
        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($mailListIds), '?'));
        $sql = "SELECT cml.mail_list_id AS lid, COUNT(ce.id) AS c
                FROM campaign_email ce
                INNER JOIN campaign_mail_list cml ON cml.campaign_id = ce.campaign_id
                WHERE cml.mail_list_id IN ($placeholders)
                  AND ce.status = ?
                GROUP BY cml.mail_list_id";
        $params = array_merge(array_values($mailListIds), [$status->value]);
        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['lid']] = (int) $r['c'];
        }
        return $map;
    }

    /**
     * @param int[] $campaignIds
     * @return array<int, int> map campaignId => count of campaign_email rows matching $status
     */
    public function countByCampaignIdsAndStatus(array $campaignIds, CampaignEmailStatus $status): array
    {
        if ($campaignIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('ce')
            ->select('IDENTITY(ce.campaign) AS cid, COUNT(ce.id) AS c')
            ->where('ce.campaign IN (:ids)')
            ->andWhere('ce.status = :status')
            ->setParameter('ids', $campaignIds)
            ->setParameter('status', $status)
            ->groupBy('ce.campaign')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['cid']] = (int) $r['c'];
        }
        return $map;
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

    public function findByCampaignQueryForRecipients(Campaign $campaign, ?string $statusFilter = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('ce')
            ->leftJoin('ce.contact', 'con')
            ->leftJoin('ce.mailList', 'ml')
            ->addSelect('con', 'ml')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->orderBy('ce.id', 'ASC');

        return match ($statusFilter) {
            'sent' => $qb->andWhere('ce.status = :status')
                ->setParameter('status', CampaignEmailStatus::Sent),
            'opened' => $qb->andWhere(
                'EXISTS (SELECT 1 FROM ' . OpenStat::class . ' os WHERE os.campaignEmail = ce)'
            ),
            'clicked' => $qb->andWhere(
                'EXISTS (SELECT 1 FROM ' . LinkStat::class . ' ls WHERE ls.campaignEmail = ce AND ls.count > 0)'
            ),
            'unsubscribed' => $qb->andWhere(
                'EXISTS (SELECT 1 FROM ' . UnsubscribeRequest::class . ' ur WHERE ur.campaignEmail = ce)'
            ),
            default => $qb,
        };
    }

    /**
     * Returns monthly aggregated stats for all sent campaign emails belonging to an account,
     * starting from $from (inclusive). Rows are ordered by month ASC.
     *
     * @return list<array{month: string, emails_sent: string, unique_opens: string, unique_clicks: string}>
     */
    public function monthlyStatsByAccount(Account $account, \DateTimeInterface $from): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery(
            "SELECT
                TO_CHAR(DATE_TRUNC('month', c.sent_at), 'YYYY-MM') AS month,
                COUNT(DISTINCT ce.id) AS emails_sent,
                COUNT(DISTINCT ce.id) FILTER (WHERE os.campaign_email_id IS NOT NULL) AS unique_opens,
                COUNT(DISTINCT ce.id) FILTER (WHERE lsc.campaign_email_id IS NOT NULL) AS unique_clicks
             FROM campaign_email ce
             INNER JOIN campaign c ON c.id = ce.campaign_id
             LEFT JOIN open_stat os ON os.campaign_email_id = ce.id
             LEFT JOIN (
                 SELECT DISTINCT campaign_email_id
                 FROM link_stat
                 WHERE count > 0
             ) lsc ON lsc.campaign_email_id = ce.id
             WHERE c.account_id = :account_id
               AND ce.status = 'sent'
               AND c.sent_at IS NOT NULL
               AND c.sent_at >= :from
             GROUP BY DATE_TRUNC('month', c.sent_at)
             ORDER BY month ASC",
            [
                'account_id' => $account->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
            ]
        );

        /** @var list<array{month: string, emails_sent: string, unique_opens: string, unique_clicks: string}> */
        return $result->fetchAllAssociative();
    }

    /**
     * @return CampaignEmail[]
     */
    public function findByContactOrderedBySentAt(\App\Entity\Contact $contact): array
    {
        /** @var CampaignEmail[] */
        return $this->createQueryBuilder('ce')
            ->innerJoin('ce.campaign', 'c')
            ->addSelect('c')
            ->where('ce.contact = :contact')
            ->setParameter('contact', $contact)
            ->orderBy('c.sentAt', 'DESC')
            ->addOrderBy('ce.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return string[] */
    public function findExistingEmailsByCampaign(Campaign $campaign): array
    {
        $rows = $this->createQueryBuilder('ce')
            ->select('ce.email')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'email');
    }
}
