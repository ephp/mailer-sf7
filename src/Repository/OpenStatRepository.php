<?php

namespace App\Repository;

use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Entity\OpenStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OpenStat>
 */
class OpenStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OpenStat::class);
    }

    public function findOneByCampaignEmail(CampaignEmail $campaignEmail): ?OpenStat
    {
        return $this->findOneBy(['campaignEmail' => $campaignEmail]);
    }

    public function countUniqueByCampaign(Campaign $campaign): int
    {
        return (int) $this->createQueryBuilder('os')
            ->select('COUNT(DISTINCT IDENTITY(os.campaignEmail))')
            ->innerJoin('os.campaignEmail', 'ce')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueByAccount(\App\Entity\Account $account): int
    {
        return (int) $this->createQueryBuilder('os')
            ->select('COUNT(DISTINCT IDENTITY(os.campaignEmail))')
            ->innerJoin('os.campaignEmail', 'ce')
            ->innerJoin('ce.campaign', 'c')
            ->where('c.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUniqueByMailList(\App\Entity\MailList $mailList): int
    {
        return (int) $this->createQueryBuilder('os')
            ->select('COUNT(DISTINCT IDENTITY(os.campaignEmail))')
            ->innerJoin('os.campaignEmail', 'ce')
            ->where('ce.mailList = :mailList')
            ->setParameter('mailList', $mailList)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function totalOpensByCampaign(Campaign $campaign): int
    {
        return (int) $this->createQueryBuilder('os')
            ->select('SUM(os.count)')
            ->innerJoin('os.campaignEmail', 'ce')
            ->where('ce.campaign = :campaign')
            ->setParameter('campaign', $campaign)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param int[] $ids
     * @return array<int, OpenStat>
     */
    public function findByCampaignEmailIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $stats = $this->findBy(['campaignEmail' => $ids]);

        $indexed = [];
        foreach ($stats as $os) {
            $ceId = $os->getCampaignEmailId();
            if ($ceId !== null) {
                $indexed[$ceId] = $os;
            }
        }

        return $indexed;
    }

    /**
     * @return list<array{bucket: string, cnt: string}>
     */
    public function timelineByCampaign(Campaign $campaign, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->executeQuery(
            "SELECT DATE_TRUNC('hour', os.first_opened_at) AS bucket, COUNT(os.id) AS cnt
             FROM open_stat os
             INNER JOIN campaign_email ce ON os.campaign_email_id = ce.id
             WHERE ce.campaign_id = :campaign_id
               AND os.first_opened_at IS NOT NULL
               AND os.first_opened_at >= :from
               AND os.first_opened_at < :to
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
