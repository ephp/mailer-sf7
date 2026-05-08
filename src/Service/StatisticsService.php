<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Campaign;
use App\Entity\MailList;
use App\Repository\CampaignEmailRepository;
use App\Repository\CampaignRepository;
use App\Repository\ContactRepository;
use App\Repository\LinkStatRepository;
use App\Repository\MailListRepository;
use App\Repository\OpenStatRepository;
use App\Repository\UnsubscribeRequestRepository;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;

class StatisticsService
{
    public function __construct(
        private readonly CampaignEmailRepository $campaignEmailRepository,
        private readonly OpenStatRepository $openStatRepository,
        private readonly LinkStatRepository $linkStatRepository,
        private readonly UnsubscribeRequestRepository $unsubscribeRepository,
        private readonly ContactRepository $contactRepository,
        private readonly MailListRepository $mailListRepository,
        private readonly CampaignRepository $campaignRepository,
    ) {}

    public function getCampaignStats(Campaign $campaign): array
    {
        $sent = $this->campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Sent);
        $failed = $this->campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Failed);
        $bounced = $this->campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Bounced);
        $total = $this->campaignEmailRepository->countByCampaign($campaign);

        $uniqueOpens = $this->openStatRepository->countUniqueByCampaign($campaign);
        $totalOpens = $this->openStatRepository->totalOpensByCampaign($campaign);
        $uniqueClicks = $this->linkStatRepository->countUniqueByCampaign($campaign);
        $totalClicks = $this->linkStatRepository->totalClicksByCampaign($campaign);
        $unsubscribes = $this->unsubscribeRepository->countByCampaign($campaign);

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'bounced' => $bounced,
            'unique_opens' => $uniqueOpens,
            'total_opens' => $totalOpens,
            'unique_clicks' => $uniqueClicks,
            'total_clicks' => $totalClicks,
            'unsubscribes' => $unsubscribes,
            'open_rate' => $sent > 0 ? round($uniqueOpens / $sent * 100, 2) : 0.0,
            'click_rate' => $sent > 0 ? round($uniqueClicks / $sent * 100, 2) : 0.0,
            'click_to_open_rate' => $uniqueOpens > 0 ? round($uniqueClicks / $uniqueOpens * 100, 2) : 0.0,
            'unsubscribe_rate' => $sent > 0 ? round($unsubscribes / $sent * 100, 2) : 0.0,
        ];
    }

    /**
     * @return list<array{timestamp: string, value: int}>
     */
    public function getCampaignTimeline(Campaign $campaign, string $metric = 'opens', int $hours = 72, bool $cumulative = false): array
    {
        $sentAt = $campaign->getSentAt();
        if ($sentAt === null) {
            return [];
        }

        $fromBase = \DateTimeImmutable::createFromInterface($sentAt);
        $from = $fromBase->setTime((int) $fromBase->format('G'), 0, 0);
        $to = $from->modify("+{$hours} hours");

        $rawData = $metric === 'clicks'
            ? $this->linkStatRepository->timelineByCampaign($campaign, $from, $to)
            : $this->openStatRepository->timelineByCampaign($campaign, $from, $to);

        $bucketMap = [];
        foreach ($rawData as $row) {
            $bucketKey = (new \DateTimeImmutable($row['bucket']))->format('Y-m-d H:00');
            $bucketMap[$bucketKey] = (int) $row['cnt'];
        }

        $timeline = [];
        $current = $from;
        while ($current < $to) {
            $bucketKey = $current->format('Y-m-d H:00');
            $timeline[] = [
                'timestamp' => $current->format(\DateTimeInterface::ATOM),
                'value' => $bucketMap[$bucketKey] ?? 0,
            ];
            $current = $current->modify('+1 hour');
        }

        if ($cumulative) {
            $running = 0;
            foreach ($timeline as &$point) {
                $running += $point['value'];
                $point['value'] = $running;
            }
            unset($point);
        }

        return $timeline;
    }

    /**
     * @return list<array{original_url: string, unique_clicks: int, total_clicks: int, click_rate: float}>
     */
    public function getCampaignLinks(Campaign $campaign): array
    {
        $sent = $this->campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Sent);
        $rows = $this->linkStatRepository->linksByCampaign($campaign);

        $links = [];
        foreach ($rows as $row) {
            $uniqueClicks = (int) $row['unique_clicks'];
            $links[] = [
                'original_url' => $row['original_url'],
                'unique_clicks' => $uniqueClicks,
                'total_clicks' => (int) $row['total_clicks'],
                'click_rate' => $sent > 0 ? round($uniqueClicks / $sent * 100, 2) : 0.0,
            ];
        }

        return $links;
    }

    public function getAccountStats(Account $account): array
    {
        $totalSent = $this->campaignEmailRepository->countByAccountAndStatus($account, CampaignEmailStatus::Sent);
        $totalFailed = $this->campaignEmailRepository->countByAccountAndStatus($account, CampaignEmailStatus::Failed);
        $totalPending = $this->campaignEmailRepository->countByAccountAndStatus($account, CampaignEmailStatus::Pending);
        $totalBounced = $this->campaignEmailRepository->countByAccountAndStatus($account, CampaignEmailStatus::Bounced);
        $totalOpens = $this->openStatRepository->countUniqueByAccount($account);
        $totalClicks = $this->linkStatRepository->countUniqueByAccount($account);
        $totalUnsubscribes = $this->unsubscribeRepository->countByAccount($account);
        $totalContacts = $this->contactRepository->countByAccount($account);
        $totalSubscribed = $this->contactRepository->countSubscribedByAccount($account);
        $totalLists = $this->mailListRepository->countByAccount($account);
        $totalCampaigns = $this->campaignRepository->countNonTemplateByAccount($account);

        return [
            'total_campaigns' => $totalCampaigns,
            'total_lists' => $totalLists,
            'total_contacts' => $totalContacts,
            'total_subscribed' => $totalSubscribed,
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'total_pending' => $totalPending,
            'total_bounced' => $totalBounced,
            'total_opens' => $totalOpens,
            'total_clicks' => $totalClicks,
            'total_unsubscribes' => $totalUnsubscribes,
            'open_rate' => $totalSent > 0 ? round($totalOpens / $totalSent * 100, 1) : 0.0,
            'click_rate' => $totalSent > 0 ? round($totalClicks / $totalSent * 100, 1) : 0.0,
            'unsubscribe_rate' => $totalSent > 0 ? round($totalUnsubscribes / $totalSent * 100, 1) : 0.0,
        ];
    }

    public function getMailListStats(MailList $mailList): array
    {
        $totalSent = $this->campaignEmailRepository->countByMailListAndStatus($mailList, CampaignEmailStatus::Sent);
        $totalFailed = $this->campaignEmailRepository->countByMailListAndStatus($mailList, CampaignEmailStatus::Failed);
        $totalPending = $this->campaignEmailRepository->countByMailListAndStatus($mailList, CampaignEmailStatus::Pending);
        $totalBounced = $this->campaignEmailRepository->countByMailListAndStatus($mailList, CampaignEmailStatus::Bounced);
        $totalOpens = $this->openStatRepository->countUniqueByMailList($mailList);
        $totalClicks = $this->linkStatRepository->countUniqueByMailList($mailList);
        $totalUnsubscribes = $this->unsubscribeRepository->countByMailList($mailList);
        $totalContacts = $this->contactRepository->countByMailList($mailList);
        $totalSubscribed = $this->contactRepository->countSubscribedByMailList($mailList);

        return [
            'total_contacts' => $totalContacts,
            'total_subscribed' => $totalSubscribed,
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'total_pending' => $totalPending,
            'total_bounced' => $totalBounced,
            'total_opens' => $totalOpens,
            'total_clicks' => $totalClicks,
            'total_unsubscribes' => $totalUnsubscribes,
            'open_rate' => $totalSent > 0 ? round($totalOpens / $totalSent * 100, 1) : 0.0,
            'click_rate' => $totalSent > 0 ? round($totalClicks / $totalSent * 100, 1) : 0.0,
            'unsubscribe_rate' => $totalSent > 0 ? round($totalUnsubscribes / $totalSent * 100, 1) : 0.0,
        ];
    }
}
