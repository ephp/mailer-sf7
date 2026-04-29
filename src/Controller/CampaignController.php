<?php

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\MailList;
use App\Form\CampaignType;
use App\Repository\CampaignRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Oi\ApiBundle\Model\ItemDetail;
use Oi\ApiBundle\Model\PaginatedList;
use Oi\ApiBundle\Service\Form\Interfaces\FormErrorMessageHandlerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1')]
class CampaignController extends AbstractController
{
    public function __construct(
        private readonly FormErrorMessageHandlerInterface $formErrorMessageHandler,
    ) {}

    #[Route('/campaigns', name: 'campaign_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        Request $request,
        CampaignRepository $campaignRepository,
        PaginatorInterface $paginator,
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

        $qb = $campaignRepository->createQueryBuilder('c')
            ->where('c.account = :account')
            ->andWhere('c.template = false')
            ->setParameter('account', $account)
            ->orderBy('c.createdAt', 'DESC');

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 20),
        );

        return new Response($serializer->serialize(new PaginatedList($pagination), 'json'));
    }

    #[Route('/campaigns/{id}', name: 'campaign_find', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function find(
        int $id,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        return new Response($serializer->serialize(new ItemDetail($campaign), 'json'));
    }

    #[Route('/campaigns-new', name: 'campaign_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(
        Request $request,
        ManagerRegistry $doctrine,
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

        $campaign = new Campaign();
        $campaign->setAccount($account);

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $this->syncMailLists($campaign, $request->request->all()['mail_list_ids'] ?? [], $account, $em);

            $em->persist($campaign);
            $em->flush();

            $detail = new ItemDetail($campaign, $translator->trans('campaign.success.created'));
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/campaigns/{id}/edit', name: 'campaign_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $this->syncMailLists($campaign, $request->request->all()['mail_list_ids'] ?? [], $account, $em);
            $em->flush();

            $detail = new ItemDetail($campaign, $translator->trans('campaign.success.updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            $campaign,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/campaigns/{id}/delete', name: 'campaign_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int $id,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        $em = $doctrine->getManager();
        $em->remove($campaign);
        $em->flush();

        $detail = new ItemDetail(null, $translator->trans('campaign.success.deleted'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    private function findCampaignForUser(
        int $id,
        ManagerRegistry $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): Campaign|Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $campaign = $doctrine->getManager()->find(Campaign::class, $id);

        if ($campaign === null || $campaign->getAccountId() !== $account?->getId()) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $campaign;
    }

    private function syncMailLists(
        Campaign $campaign,
        array $listIds,
        ?\App\Entity\Account $account,
        EntityManagerInterface $em,
    ): void {
        $collection = $campaign->getMailLists();
        $collection->clear();

        foreach ($listIds as $listId) {
            $mailList = $em->find(MailList::class, (int) $listId);
            if ($mailList !== null && $mailList->getAccountId() === $account?->getId()) {
                $collection->add($mailList);
            }
        }
    }
}
