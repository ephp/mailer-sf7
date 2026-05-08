<?php

namespace App\Controller;

use App\Entity\MailList;
use App\Repository\CampaignEmailRepository;
use App\Repository\CampaignRepository;
use App\Service\StatisticsService;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Oi\ApiBundle\Model\ItemDetail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1')]
class StatisticsController extends AbstractController
{
    public function __construct(
        private readonly StatisticsService $statisticsService,
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignEmailRepository $campaignEmailRepository,
    ) {}

    #[Route('/campaigns/{id}/stats', name: 'statistics_campaign', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function campaignStats(
        int $id,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $campaign = $this->campaignRepository->findOneByIdAndAccount($id, $account);
        if ($campaign === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $stats = $this->statisticsService->getCampaignStats($campaign);
        return new Response($serializer->serialize(new ItemDetail($stats), 'json'));
    }

    #[Route('/campaigns/{id}/timeline', name: 'statistics_campaign_timeline', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function campaignTimeline(
        int $id,
        Request $request,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $campaign = $this->campaignRepository->findOneByIdAndAccount($id, $account);
        if ($campaign === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $metric = $request->query->getString('metric', 'opens');
        if (!in_array($metric, ['opens', 'clicks'], true)) {
            $metric = 'opens';
        }
        $hours = max(1, min(720, $request->query->getInt('hours', 72)));
        $cumulative = $request->query->getBoolean('cumulative', false);

        $timeline = $this->statisticsService->getCampaignTimeline($campaign, $metric, $hours, $cumulative);
        return new Response($serializer->serialize(new ItemDetail($timeline), 'json'));
    }

    #[Route('/campaigns/{id}/links', name: 'statistics_campaign_links', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function campaignLinks(
        int $id,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $campaign = $this->campaignRepository->findOneByIdAndAccount($id, $account);
        if ($campaign === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $links = $this->statisticsService->getCampaignLinks($campaign);
        return new Response($serializer->serialize(new ItemDetail($links), 'json'));
    }

    #[Route('/campaigns/{id}/recipients', name: 'statistics_campaign_recipients', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function campaignRecipients(
        int $id,
        Request $request,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        PaginatorInterface $paginator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $campaign = $this->campaignRepository->findOneByIdAndAccount($id, $account);
        if ($campaign === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $statusFilter = $request->query->getString('status', '');
        if (!in_array($statusFilter, ['sent', 'opened', 'clicked', 'unsubscribed'], true)) {
            $statusFilter = null;
        }

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = max(1, min(200, $request->query->getInt('per_page', 50)));

        $qb = $this->campaignEmailRepository->findByCampaignQueryForRecipients($campaign, $statusFilter);
        $pagination = $paginator->paginate($qb, $page, $perPage, ['sortFieldParameterName' => '_disabled_sort']);

        /** @var \App\Entity\CampaignEmail[] $pageItems */
        $pageItems = iterator_to_array($pagination);
        $items = $this->statisticsService->buildRecipientsFromItems($pageItems);

        $data = [
            'items' => $items,
            'currentPage' => $pagination->getCurrentPageNumber(),
            'itemCount' => $pagination->getTotalItemCount(),
            'itemsPerPage' => $pagination->getItemNumberPerPage(),
        ];

        return new Response($serializer->serialize($data, 'json'));
    }

    #[Route('/campaigns/{id}/recipients/csv', name: 'statistics_campaign_recipients_csv', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function campaignRecipientsCsv(
        int $id,
        Request $request,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $campaign = $this->campaignRepository->findOneByIdAndAccount($id, $account);
        if ($campaign === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $statusFilter = $request->query->getString('status', '');
        if (!in_array($statusFilter, ['sent', 'opened', 'clicked', 'unsubscribed'], true)) {
            $statusFilter = null;
        }

        $qb = $this->campaignEmailRepository->findByCampaignQueryForRecipients($campaign, $statusFilter);
        /** @var \App\Entity\CampaignEmail[] $allItems */
        $allItems = $qb->getQuery()->getResult();
        $recipients = $this->statisticsService->buildRecipientsFromItems($allItems);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        fputcsv($handle, ['id', 'email', 'nome', 'cognome', 'mail_list', 'status', 'opened', 'opened_at', 'open_count', 'clicked', 'click_count', 'unsubscribed']);

        foreach ($allItems as $i => $ce) {
            $row = $recipients[$i];
            fputcsv($handle, [
                (int) $ce->getId(),
                $row['email'],
                $row['contact_nome'] ?? '',
                $row['contact_cognome'] ?? '',
                $row['mail_list_name'] ?? '',
                $row['status'],
                $row['opened'] ? '1' : '0',
                $row['opened_at'] ?? '',
                $row['open_count'],
                $row['clicked'] ? '1' : '0',
                $row['click_count'],
                $row['unsubscribed'] ? '1' : '0',
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        $response = new Response("\xEF\xBB\xBF" . $csvContent);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', "attachment; filename=campaign-{$id}-recipients.csv");

        return $response;
    }

    #[Route('/statistics', name: 'statistics_account', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function accountStats(
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $stats = $this->statisticsService->getAccountStats($account);
        return new Response($serializer->serialize(new ItemDetail($stats), 'json'));
    }

    #[Route('/statistics/monthly', name: 'statistics_monthly', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function accountMonthly(
        Request $request,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $months = max(1, min(60, $request->query->getInt('months', 12)));

        $monthly = $this->statisticsService->getAccountMonthly($account, $months);
        return new Response($serializer->serialize(new ItemDetail($monthly), 'json'));
    }

    #[Route('/statistics/lists/{id}', name: 'statistics_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function listStats(
        int $id,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $mailList = $doctrine->getManager()->find(MailList::class, $id);
        if ($mailList === null || $mailList->getAccountId() !== $account?->getId()) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $stats = $this->statisticsService->getMailListStats($mailList);
        return new Response($serializer->serialize(new ItemDetail($stats), 'json'));
    }
}
