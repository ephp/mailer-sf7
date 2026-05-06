<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\MailList;
use App\Form\MailListType;
use App\Repository\MailListRepository;
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
        Request             $request,
        MailListRepository  $mailListRepository,
        PaginatorInterface  $paginator,
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

        $search = $request->query->get('search');
        $sort = $request->query->get('sort', 'name');
        $direction = $request->query->get('direction', 'asc');

        $qb = $mailListRepository->findByAccountQuery($account, $search, $sort, $direction);

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 20),
        );

        return new Response($serializer->serialize(new PaginatedList($pagination), 'json', ['groups' => ['list:read']]));
    }

    #[Route('/lists/{id}', name: 'maillist_find', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function find(
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($id, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        return new Response($serializer->serialize(new ItemDetail($mailList), 'json'));
    }

    #[Route('/lists-new', name: 'maillist_new', methods: ['POST'])]
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
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{id}/edit', name: 'maillist_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        int                 $id,
        Request             $request,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($id, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $form = $this->createForm(MailListType::class, $mailList);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            $detail = new ItemDetail($mailList, $translator->trans('maillist.success.updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            $mailList,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{id}/delete', name: 'maillist_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($id, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $em = $doctrine->getManager();
        $em->remove($mailList);
        $em->flush();

        $detail = new ItemDetail(null, $translator->trans('maillist.success.deleted'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    private function findMailListForUser(
        int                 $id,
        ManagerRegistry     $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): MailList|Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $mailList = $doctrine->getManager()->find(MailList::class, $id);

        if ($mailList === null || $mailList->getAccountId() !== $account?->getId()) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $mailList;
    }
}
