<?php

namespace App\Controller;

use App\Entity\MailList;
use App\Repository\CampaignRepository;
use App\Service\StatisticsService;
use Doctrine\Persistence\ManagerRegistry;
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
