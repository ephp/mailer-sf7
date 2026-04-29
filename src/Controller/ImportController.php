<?php

namespace App\Controller;

use App\Entity\MailList;
use App\Service\ImportService;
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
class ImportController extends AbstractController
{
    public function __construct(
        private readonly ImportService $importService,
    ) {}

    #[Route('/lists/{listId}/contacts-import', name: 'contact_import', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function import(
        int                 $listId,
        Request             $request,
        ManagerRegistry     $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user    = $this->getUser();
        $account = $user->getAccount();

        $mailList = $doctrine->getManager()->find(MailList::class, $listId);
        if ($mailList === null || $mailList->getAccountId() !== $account?->getId()) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('csv_file');
        if ($file === null) {
            $detail = new ItemDetail(null, $translator->trans('import.error.no_file'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $importResult = $this->importService->importFromCsv($file, $mailList);
            $detail = new ItemDetail($importResult, $translator->trans('import.success'));
            return new Response($serializer->serialize($detail, 'json'));
        } catch (\InvalidArgumentException $e) {
            $detail = new ItemDetail(null, $translator->trans('import.error.' . $e->getMessage()), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
