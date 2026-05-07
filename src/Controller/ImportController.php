<?php

namespace App\Controller;

use App\Entity\MailList;
use App\Repository\TaxonomyCategoryRepository;
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

#[Route('/api/v1', requirements: ['listId' => '\d+'])]
class ImportController extends AbstractController
{
    public function __construct(
        private readonly ImportService $importService,
        private readonly TaxonomyCategoryRepository $taxonomyCategoryRepository,
    ) {}

    #[Route('/lists/{listId}/contacts-import-template', name: 'contact_import_template', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function template(
        int                 $listId,
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

        $categories = $this->taxonomyCategoryRepository->findByMailList($mailList);

        $headers = ['email', 'nome', 'cognome', 'telefono'];
        foreach ($categories as $category) {
            $headers[] = (string) $category->getName();
        }

        $exampleRow = ['mario.rossi@example.com', 'Mario', 'Rossi', '+39 333 1234567'];
        foreach ($categories as $category) {
            // Pre-fill the example with the first existing term, or a placeholder.
            $firstTerm = $category->getTerms()->first();
            $exampleRow[] = $firstTerm ? (string) $firstTerm->getName() : 'Termine|Altro Termine';
        }

        $csv = $this->buildCsv([$headers, $exampleRow]);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="import-template-%s.csv"', $mailList->getId()),
        ]);
    }

    /** @param list<list<string>> $rows */
    private function buildCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        // BOM so Excel opens it as UTF-8.
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fh, $row, ';');
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }

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

        $extension = strtolower((string) $file->getClientOriginalExtension());

        try {
            if ($extension === 'xlsx') {
                $importResult = $this->importService->importFromXlsx($file, $mailList);
            } else {
                $importResult = $this->importService->importFromCsv($file, $mailList);
            }
            $detail = new ItemDetail($importResult, $translator->trans('import.success'));
            return new Response($serializer->serialize($detail, 'json'));
        } catch (\InvalidArgumentException $e) {
            $detail = new ItemDetail(null, $translator->trans('import.error.' . $e->getMessage()), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
