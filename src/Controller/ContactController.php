<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\MailList;
use App\Form\ContactType;
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
class ContactController extends AbstractController
{
    public function __construct(
        private readonly FormErrorMessageHandlerInterface $formErrorMessageHandler,
    ) {}

    #[Route('/lists/{listId}/contacts', name: 'contact_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        int                 $listId,
        Request             $request,
        ManagerRegistry     $doctrine,
        PaginatorInterface  $paginator,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        \App\Repository\CampaignEmailRepository $campaignEmailRepository,
        \App\Repository\OpenStatRepository $openStatRepository,
        \App\Repository\LinkStatRepository $linkStatRepository,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $qb = $doctrine->getManager()->getRepository(Contact::class)->createQueryBuilder('c')
            ->where('c.mailList = :mailList')
            ->setParameter('mailList', $mailList)
            ->orderBy('c.email', 'ASC');

        $fts = $request->query->get('fts');
        if ($fts) {
            $qb->andWhere('c.email LIKE :fts OR c.nome LIKE :fts OR c.cognome LIKE :fts')
               ->setParameter('fts', '%' . $fts . '%');
        }

        // Filter by taxonomy terms: contacts must match at least one term
        // for each distinct category present among the selected term ids.
        $rawTermIds = $request->query->all('taxonomy_term_ids');
        $termIds = is_array($rawTermIds) ? array_values(array_filter(array_map('intval', $rawTermIds))) : [];
        if ($termIds !== []) {
            $terms = $doctrine->getRepository(\App\Entity\TaxonomyTerm::class)
                ->findBy(['id' => $termIds]);
            $byCategory = [];
            foreach ($terms as $term) {
                $catId = (int) $term->getCategory()?->getId();
                if ($catId === 0) {
                    continue;
                }
                $byCategory[$catId][] = (int) $term->getId();
            }
            $i = 0;
            foreach ($byCategory as $catTermIds) {
                $alias = 'ct_' . $i;
                $param = 'tx_terms_' . $i;
                $qb->andWhere(sprintf(
                    'EXISTS (SELECT 1 FROM %s %s WHERE %s.contact = c AND %s.term IN (:%s))',
                    \App\Entity\ContactTaxonomy::class, $alias, $alias, $alias, $param,
                ))->setParameter($param, $catTermIds);
                $i++;
            }
        }

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 20),
        );

        /** @var Contact[] $items */
        $items = iterator_to_array($pagination);
        $ids = array_values(array_filter(array_map(fn(Contact $c) => $c->getId(), $items)));
        if ($ids !== []) {
            $bfMap = $campaignEmailRepository->countBouncedOrFailedByContactIds($ids);
            $sentMap = $campaignEmailRepository->countSentByContactIds($ids);
            $openedMap = $openStatRepository->countOpenedByContactIds($ids);
            $clickedMap = $linkStatRepository->countClickedByContactIds($ids);
            foreach ($items as $c) {
                $cid = (int) $c->getId();
                $c->setBouncedFailedCount($bfMap[$cid] ?? 0);
                $c->setSentCount($sentMap[$cid] ?? 0);
                $c->setOpenedCount($openedMap[$cid] ?? 0);
                $c->setClickedCount($clickedMap[$cid] ?? 0);
            }
        }

        return new Response($serializer->serialize(new PaginatedList($pagination), 'json'));
    }

    #[Route('/lists/{listId}/contacts/{id}/bounces', name: 'contact_bounces', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function bounces(
        int                 $listId,
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        \App\Repository\CampaignEmailRepository $campaignEmailRepository,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        $items = $campaignEmailRepository->findBouncedOrFailedByContact($contact);
        return new Response($serializer->serialize(new ItemDetail($items), 'json'));
    }

    #[Route('/lists/{listId}/contacts/{id}', name: 'contact_find', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function find(
        int                 $listId,
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        return new Response($serializer->serialize(new ItemDetail($contact), 'json'));
    }

    #[Route('/lists/{listId}/contacts-new', name: 'contact_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(
        int                 $listId,
        Request             $request,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = new Contact();
        $contact->setMailList($mailList);

        $form = $this->createForm(ContactType::class, $contact);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($contact);
            $em->flush();

            $detail = new ItemDetail($contact, $translator->trans('contact.success.created'));
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{listId}/contacts/{id}/edit', name: 'contact_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        int                 $listId,
        int                 $id,
        Request             $request,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        $form = $this->createForm(ContactType::class, $contact);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            $detail = new ItemDetail($contact, $translator->trans('contact.success.updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            $contact,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{listId}/contacts/{id}/delete', name: 'contact_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int                 $listId,
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        $em = $doctrine->getManager();
        $em->remove($contact);
        $em->flush();

        $detail = new ItemDetail(null, $translator->trans('contact.success.deleted'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    #[Route('/lists/{listId}/contacts/{id}/subscribe', name: 'contact_subscribe', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function subscribe(
        int                 $listId,
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        $contact->setIscritto(true);
        $doctrine->getManager()->flush();

        return new Response($serializer->serialize(new ItemDetail($contact, $translator->trans('contact.success.subscribed')), 'json'));
    }

    #[Route('/lists/{listId}/contacts/{id}/unsubscribe', name: 'contact_unsubscribe', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unsubscribe(
        int                 $listId,
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        $contact->setIscritto(false);
        $doctrine->getManager()->flush();

        return new Response($serializer->serialize(new ItemDetail($contact, $translator->trans('contact.success.unsubscribed')), 'json'));
    }

    private function findMailListForUser(
        int                 $listId,
        ManagerRegistry     $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): MailList|Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $mailList = $doctrine->getManager()->find(MailList::class, $listId);

        if ($mailList === null || $mailList->getAccountId() !== $account?->getId()) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $mailList;
    }

    private function findContactForMailList(
        int                 $id,
        MailList            $mailList,
        ManagerRegistry     $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): Contact|Response {
        $contact = $doctrine->getManager()->find(Contact::class, $id);

        if ($contact === null || $contact->getMailList()?->getId() !== $mailList->getId()) {
            $detail = new ItemDetail(null, $translator->trans('contact.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $contact;
    }
}
