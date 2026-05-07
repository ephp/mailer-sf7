<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\ContactTaxonomy;
use App\Entity\MailList;
use App\Entity\TaxonomyCategory;
use App\Entity\TaxonomyTerm;
use App\Form\TaxonomyCategoryType;
use App\Form\TaxonomyTermType;
use App\Repository\ContactTaxonomyRepository;
use App\Repository\TaxonomyCategoryRepository;
use App\Repository\TaxonomyTermRepository;
use Doctrine\Persistence\ManagerRegistry;
use Oi\ApiBundle\Model\ItemDetail;
use Oi\ApiBundle\Service\Form\Interfaces\FormErrorMessageHandlerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1', requirements: ['listId' => '\d+', 'id' => '\d+', 'categoryId' => '\d+', 'contactId' => '\d+'])]
class TaxonomyController extends AbstractController
{
    public function __construct(
        private readonly FormErrorMessageHandlerInterface $formErrorMessageHandler,
        private readonly TaxonomyCategoryRepository $categoryRepository,
        private readonly TaxonomyTermRepository $termRepository,
        private readonly ContactTaxonomyRepository $contactTaxonomyRepository,
    ) {}

    // ─── Categories ──────────────────────────────────────────────────────────

    #[Route('/lists/{listId}/taxonomy-categories', name: 'taxonomy_category_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function categoryIndex(
        int                 $listId,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $categories = $this->categoryRepository->findByMailList($mailList);
        return new Response($serializer->serialize(new ItemDetail($categories), 'json'));
    }

    #[Route('/lists/{listId}/taxonomy-categories/{id}', name: 'taxonomy_category_find', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function categoryFind(
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

        $category = $this->findCategoryForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($category instanceof Response) {
            return $category;
        }

        return new Response($serializer->serialize(new ItemDetail($category), 'json'));
    }

    #[Route('/lists/{listId}/taxonomy-categories-new', name: 'taxonomy_category_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function categoryNew(
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

        $category = new TaxonomyCategory();
        $category->setMailList($mailList);

        $form = $this->createForm(TaxonomyCategoryType::class, $category);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($category);
            $em->flush();

            $detail = new ItemDetail($category, $translator->trans('taxonomy.success.category_created'));
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{listId}/taxonomy-categories/{id}/edit', name: 'taxonomy_category_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function categoryEdit(
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

        $category = $this->findCategoryForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($category instanceof Response) {
            return $category;
        }

        $form = $this->createForm(TaxonomyCategoryType::class, $category);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            $detail = new ItemDetail($category, $translator->trans('taxonomy.success.category_updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{listId}/taxonomy-categories/{id}/delete', name: 'taxonomy_category_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function categoryDelete(
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

        $category = $this->findCategoryForMailList($id, $mailList, $doctrine, $translator, $serializer);
        if ($category instanceof Response) {
            return $category;
        }

        $em = $doctrine->getManager();
        $em->remove($category);
        $em->flush();

        $detail = new ItemDetail(null, $translator->trans('taxonomy.success.category_deleted'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    // ─── Terms ────────────────────────────────────────────────────────────────

    #[Route('/lists/{listId}/taxonomy-categories/{categoryId}/terms', name: 'old_taxonomy_term_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function termIndex(
        int                 $listId,
        int                 $categoryId,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $category = $this->findCategoryForMailList($categoryId, $mailList, $doctrine, $translator, $serializer);
        if ($category instanceof Response) {
            return $category;
        }

        $terms = $this->termRepository->findByCategory($category);
        return new Response($serializer->serialize(new ItemDetail($terms), 'json'));
    }

    #[Route('/lists/{listId}/taxonomy-categories/{categoryId}/terms-new', name: 'old_taxonomy_term_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function termNew(
        int                 $listId,
        int                 $categoryId,
        Request             $request,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $category = $this->findCategoryForMailList($categoryId, $mailList, $doctrine, $translator, $serializer);
        if ($category instanceof Response) {
            return $category;
        }

        $term = new TaxonomyTerm();
        $term->setCategory($category);

        $form = $this->createForm(TaxonomyTermType::class, $term);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($term);
            $em->flush();

            $detail = new ItemDetail($term, $translator->trans('taxonomy.success.term_created'));
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{listId}/taxonomy-categories/{categoryId}/terms/{id}/edit', name: 'old_taxonomy_term_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function termEdit(
        int                 $listId,
        int                 $categoryId,
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

        $category = $this->findCategoryForMailList($categoryId, $mailList, $doctrine, $translator, $serializer);
        if ($category instanceof Response) {
            return $category;
        }

        $term = $this->findTermForCategory($id, $category, $doctrine, $translator, $serializer);
        if ($term instanceof Response) {
            return $term;
        }

        $form = $this->createForm(TaxonomyTermType::class, $term);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();

            $detail = new ItemDetail($term, $translator->trans('taxonomy.success.term_updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/lists/{listId}/taxonomy-categories/{categoryId}/terms/{id}/delete', name: 'old_taxonomy_term_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function termDelete(
        int                 $listId,
        int                 $categoryId,
        int                 $id,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $category = $this->findCategoryForMailList($categoryId, $mailList, $doctrine, $translator, $serializer);
        if ($category instanceof Response) {
            return $category;
        }

        $term = $this->findTermForCategory($id, $category, $doctrine, $translator, $serializer);
        if ($term instanceof Response) {
            return $term;
        }

        $em = $doctrine->getManager();
        $em->remove($term);
        $em->flush();

        $detail = new ItemDetail(null, $translator->trans('taxonomy.success.term_deleted'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    // ─── Contact taxonomy assignments ────────────────────────────────────────

    #[Route('/lists/{listId}/contacts/{contactId}/taxonomy', name: 'contact_taxonomy_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function contactTaxonomyGet(
        int                 $listId,
        int                 $contactId,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($contactId, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        $termIds = $this->contactTaxonomyRepository->findTermIdsByContact($contact);
        return new Response($serializer->serialize(new ItemDetail(['term_ids' => $termIds]), 'json'));
    }

    #[Route('/lists/{listId}/contacts/{contactId}/taxonomy-sync', name: 'contact_taxonomy_sync', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function contactTaxonomySync(
        int                 $listId,
        int                 $contactId,
        Request             $request,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $this->findMailListForUser($listId, $doctrine, $translator, $serializer);
        if ($mailList instanceof Response) {
            return $mailList;
        }

        $contact = $this->findContactForMailList($contactId, $mailList, $doctrine, $translator, $serializer);
        if ($contact instanceof Response) {
            return $contact;
        }

        $payload = $request->toArray();
        $termIds = array_unique(array_map('intval', (array) ($payload['term_ids'] ?? [])));

        $em = $doctrine->getManager();

        // Diff existing vs new to avoid re-inserting an unchanged (contact, term)
        // pair, which would violate the contact_term_unique constraint when
        // Doctrine schedules the INSERT before the matching DELETE.
        $existing = $this->contactTaxonomyRepository->findByContact($contact);
        $existingByTermId = [];
        foreach ($existing as $ct) {
            $existingByTermId[$ct->getTermId()] = $ct;
        }

        $newTermIdsSet = array_flip($termIds);

        // 1. Delete assignments that are no longer in the new set.
        foreach ($existingByTermId as $termId => $ct) {
            if (!isset($newTermIdsSet[$termId])) {
                $em->remove($ct);
            }
        }

        // 2. Add assignments that are new, verifying each term belongs to a category of this list.
        foreach ($termIds as $termId) {
            if (isset($existingByTermId[$termId])) {
                continue;
            }
            $term = $em->find(TaxonomyTerm::class, $termId);
            if ($term === null || $term->getCategory()?->getMailListId() !== $mailList->getId()) {
                continue;
            }
            $ct = new ContactTaxonomy();
            $ct->setContact($contact);
            $ct->setTerm($term);
            $em->persist($ct);
        }

        $em->flush();

        $newTermIds = $this->contactTaxonomyRepository->findTermIdsByContact($contact);
        $detail = new ItemDetail(['term_ids' => $newTermIds], $translator->trans('taxonomy.success.contact_synced'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function findMailListForUser(
        int                 $listId,
        ManagerRegistry     $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): MailList|Response {
        /** @var \App\Entity\User $user */
        $user    = $this->getUser();
        $account = $user->getAccount();

        $mailList = $doctrine->getManager()->find(MailList::class, $listId);

        if ($mailList === null || $mailList->getAccountId() !== $account?->getId()) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $mailList;
    }

    private function findCategoryForMailList(
        int                 $id,
        MailList            $mailList,
        ManagerRegistry     $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): TaxonomyCategory|Response {
        $category = $doctrine->getManager()->find(TaxonomyCategory::class, $id);

        if ($category === null || $category->getMailListId() !== $mailList->getId()) {
            $detail = new ItemDetail(null, $translator->trans('taxonomy.error.category_not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $category;
    }

    private function findTermForCategory(
        int                 $id,
        TaxonomyCategory    $category,
        ManagerRegistry     $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): TaxonomyTerm|Response {
        $term = $doctrine->getManager()->find(TaxonomyTerm::class, $id);

        if ($term === null || $term->getCategoryId() !== $category->getId()) {
            $detail = new ItemDetail(null, $translator->trans('taxonomy.error.term_not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $term;
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
