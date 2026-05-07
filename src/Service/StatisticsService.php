<?php

namespace App\Service;

use App\Entity\Account;
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
