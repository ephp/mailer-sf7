<?php

namespace App\Controller;

use App\Entity\MailList;
use App\Form\MailListType;
use App\Repository\CampaignEmailRepository;
use App\Repository\CampaignRepository;
use App\Repository\ContactRepository;
use App\Repository\LinkStatRepository;
use App\Repository\MailListRepository;
use App\Repository\OpenStatRepository;
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
class MailListController extends AbstractController
{
    public function __construct(
        private readonly FormErrorMessageHandlerInterface $formErrorMessageHandler,
    ) {}

    #[Route('/lists', name: 'maillist_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        Request                  $request,
        MailListRepository       $mailListRepository,
        CampaignRepository       $campaignRepository,
        CampaignEmailRepository  $campaignEmailRepository,
        ContactRepository        $contactRepository,
        OpenStatRepository       $openStatRepository,
        LinkStatRepository       $linkStatRepository,
        PaginatorInterface       $paginator,
        SerializerInterface      $serializer,
        TranslatorInterface      $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $search = $request->query->get('search');
        $sort = $request->query->get('sort', 'name');
        $direction = $request->query->get('direction', 'asc');

        $qb = $mailListRepository->findByAccountQuery($account, $search, $sort, $direction);

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 20),
            ['sortFieldParameterName' => '_disabled_sort'],
        );

        /** @var MailList[] $items */
        $items = iterator_to_array($pagination);
        $ids = array_values(array_filter(array_map(fn(MailList $ml) => $ml->getId(), $items)));
        if ($ids !== []) {
            $campaignsSentMap = $campaignRepository->countSentByMailListIds($ids);
            $emailsSentMap = $campaignEmailRepository->countByMailListIdsAndStatus($ids, \Ephp\MailflowBundle\Enum\CampaignEmailStatus::Sent);
            $opensMap = $openStatRepository->countUniqueByMailListIds($ids);
            $clicksMap = $linkStatRepository->countUniqueByMailListIds($ids);
            [$totalMap, $activeMap] = $contactRepository->countsByMailListIds($ids);
            foreach ($items as $ml) {
                $lid = (int) $ml->getId();
                $ml->setStats(
                    $campaignsSentMap[$lid] ?? 0,
                    $totalMap[$lid] ?? 0,
                    $activeMap[$lid] ?? 0,
                    $emailsSentMap[$lid] ?? 0,
                    $opensMap[$lid] ?? 0,
                    $clicksMap[$lid] ?? 0,
                );
            }
        }

        return new Response($serializer->serialize(new PaginatedList($pagination), 'json', ['groups' => ['list:read']]));
    }

    #[Route('/lists/{id}', name: 'maillist_find', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function find(
        int                 $id,
        MailListRepository  $mailListRepository,
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

        $mailList = $mailListRepository->findOneByIdAndAccount($id, $account);

        if ($mailList === null) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return new Response($serializer->serialize(new ItemDetail($mailList), 'json', ['groups' => ['list:read', 'list:detail']]));
    }

    #[Route('/lists', name: 'maillist_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(
        Request             $request,
        ManagerRegistry     $doctrine,
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

        $mailList = new MailList();
        $mailList->setAccount($account);

        $form = $this->createForm(MailListType::class, $mailList);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($mailList);
            $em->flush();

            $detail = new ItemDetail($mailList, $translator->trans('maillist.success.created'));
            return new Response($serializer->serialize($detail, 'json', ['groups' => ['list:read']]), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{id}', name: 'maillist_update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function update(
        int                 $id,
        Request             $request,
        MailListRepository  $mailListRepository,
        ManagerRegistry     $doctrine,
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

        $mailList = $mailListRepository->findOneByIdAndAccount($id, $account);

        if ($mailList === null) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(MailListType::class, $mailList);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            $detail = new ItemDetail($mailList, $translator->trans('maillist.success.updated'));
            return new Response($serializer->serialize($detail, 'json', ['groups' => ['list:read']]), Response::HTTP_OK);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{id}', name: 'maillist_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int                  $id,
        MailListRepository   $mailListRepository,
        CampaignRepository   $campaignRepository,
        ManagerRegistry      $doctrine,
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $mailList = $mailListRepository->findOneByIdAndAccount($id, $account);

        if ($mailList === null) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        if ($campaignRepository->countActiveCampaignsByMailList($mailList) > 0) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.has_active_campaigns'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CONFLICT);
        }

        $mailList->setDeletedAt(new \DateTime());
        $doctrine->getManager()->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
