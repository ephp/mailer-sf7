<?php

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\LinkStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LinkStat>
 */
class LinkStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkStat::class);
    }

    public function findOneByToken(string $token): ?LinkStat
    {
        return $this->findOneBy(['trackingToken' => $token]);
    }

    public function countUniqueByCampaign(Campaign $campaign): int
    {
        // Only count link_stat rows that were *actually clicked* (count > 0).
        // A LinkStat is created at send time for every <a href> in the email
        // body, so without this filter we would count every recipient who
        // received a link, not those who clicked it.
        return (int) $this->createQueryBuilder('ls')
            ->select('COUNT(DISTINCT IDENTITY(ls.campaignEmail))')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->where('ce.campaign = :campaign')
            ->andWhere('ls.count > 0')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $contactIds
     * @return array<int, int> map contactId => count of campaign_email rows with at least one link click
     */
    public function countClickedByContactIds(array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('ls')
            ->select('IDENTITY(ce.contact) AS cid, COUNT(DISTINCT IDENTITY(ls.campaignEmail)) AS c')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->where('ce.contact IN (:ids)')
            ->andWhere('ls.count > 0')
            ->setParameter('ids', $contactIds)
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
     * @param int[] $mailListIds
     * @return array<int, int> map mailListId => unique clicks count (count>0)
     */
    public function countUniqueByMailListIds(array $mailListIds): array
    {
        if ($mailListIds === []) {
            return [];
        }
        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, count($mailListIds), '?'));
        $sql = "SELECT cml.mail_list_id AS lid, COUNT(DISTINCT ls.campaign_email_id) AS c
                FROM link_stat ls
                INNER JOIN campaign_email ce ON ce.id = ls.campaign_email_id
                INNER JOIN campaign_mail_list cml ON cml.campaign_id = ce.campaign_id
                WHERE cml.mail_list_id IN ($placeholders)
                  AND ls.count > 0
                GROUP BY cml.mail_list_id";
        $rows = $conn->executeQuery($sql, array_values($mailListIds))->fetchAllAssociative();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['lid']] = (int) $r['c'];
        }
        return $map;
    }

    /**
     * @param int[] $campaignIds
     * @return array<int, int> map campaignId => unique clicks count
     */
    public function countUniqueByCampaignIds(array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('ls')
            ->select('IDENTITY(ce.campaign) AS cid, COUNT(DISTINCT IDENTITY(ls.campaignEmail)) AS c')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->where('ce.campaign IN (:ids)')
            ->andWhere('ls.count > 0')
            ->setParameter('ids', $campaignIds)
            ->groupBy('ce.campaign')
            ->getQuery()
            ->getArrayResult();
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['cid']] = (int) $r['c'];
        }
        return $map;
    }

    public function countUniqueByAccount(\App\Entity\Account $account): int
    {
        return (int) $this->createQueryBuilder('ls')
            ->select('COUNT(DISTINCT IDENTITY(ls.campaignEmail))')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->innerJoin('ce.campaign', 'c')
            ->where('c.account = :account')
            ->andWhere('ls.count > 0')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueByMailList(\App\Entity\MailList $mailList): int
    {
        return (int) $this->createQueryBuilder('ls')
            ->select('COUNT(DISTINCT IDENTITY(ls.campaignEmail))')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->where('ce.mailList = :mailList')
            ->andWhere('ls.count > 0')
            ->setParameter('mailList', $mailList)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function totalClicksByCampaign(Campaign $campaign): int
    {
        return (int) $this->createQueryBuilder('ls')
            ->select('SUM(ls.count)')
            ->innerJoin('ls.campaignEmail', 'ce')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $ids
     * @return array<int, int>
     */
    public function sumClicksByCampaignEmailIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $inList = implode(',', array_map('intval', $ids));
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery(
            "SELECT campaign_email_id, SUM(count) AS total_clicks
             FROM link_stat
             WHERE campaign_email_id IN ($inList)
             GROUP BY campaign_email_id"
        );

        $data = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $data[(int) $row['campaign_email_id']] = (int) $row['total_clicks'];
        }

        return $data;
    }

    /**
     * @param int[] $ids
     * @return array<int, list<string>>
     */
    public function urlsByCampaignEmailIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $inList = implode(',', array_map('intval', $ids));
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery(
            "SELECT campaign_email_id, url
             FROM link_stat
             WHERE campaign_email_id IN ($inList)
               AND count > 0
             ORDER BY campaign_email_id, id"
        );

        $data = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $ceId = (int) $row['campaign_email_id'];
            if (!isset($data[$ceId])) {
                $data[$ceId] = [];
            }
            $data[$ceId][] = $row['url'];
        }

        return $data;
    }

    /**
     * @return list<array{original_url: string, unique_clicks: string, total_clicks: string}>
     */
    public function linksByCampaign(Campaign $campaign): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery(
            'SELECT ls.url AS original_url,
                    COUNT(DISTINCT ls.campaign_email_id) AS unique_clicks,
                    SUM(ls.count) AS total_clicks
             FROM link_stat ls
             INNER JOIN campaign_email ce ON ls.campaign_email_id = ce.id
             WHERE ce.campaign_id = :campaign_id
               AND ls.count > 0
             GROUP BY ls.url
             ORDER BY total_clicks DESC',
            ['campaign_id' => $campaign->getId()]
        );

        /** @var list<array{original_url: string, unique_clicks: string, total_clicks: string}> */
        return $result->fetchAllAssociative();
    }

    /**
     * @return list<array{bucket: string, cnt: string}>
     */
    public function timelineByCampaign(Campaign $campaign, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery(
            "SELECT DATE_TRUNC('hour', ls.first_clicked_at) AS bucket, COUNT(ls.id) AS cnt
             FROM link_stat ls
             INNER JOIN campaign_email ce ON ls.campaign_email_id = ce.id
             WHERE ce.campaign_id = :campaign_id
               AND ls.first_clicked_at IS NOT NULL
               AND ls.first_clicked_at >= :from
               AND ls.first_clicked_at < :to
             GROUP BY bucket
             ORDER BY bucket ASC",
            [
                'campaign_id' => $campaign->getId(),
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ]
        );

        /** @var list<array{bucket: string, cnt: string}> */
        return $result->fetchAllAssociative();
    }
}
