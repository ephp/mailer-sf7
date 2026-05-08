<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Campaign;
use App\Entity\CampaignEmail;
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
