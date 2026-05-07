<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\ContactTaxonomy;
use App\Entity\MailList;
use App\Entity\TaxonomyCategory;
use App\Entity\TaxonomyTerm;
use App\Repository\ContactRepository;
use App\Repository\TaxonomyCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactRepository $contactRepository,
        private readonly TaxonomyCategoryRepository $taxonomyCategoryRepository,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * @return array{imported: int, skipped: int, errors: list<array{row: int, email?: string, error: string}>}
     */
    public function importFromCsv(UploadedFile $file, MailList $mailList): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        $handle = fopen($file->getPathname(), 'r');
        if ($handle === false) {
            return $result;
        }

        try {
            $header = fgetcsv($handle, 0, ';');
            if ($header === false) {
                return $result;
            }

            $header = $this->normalizeHeader($header);
            $columnMap = $this->buildColumnMap($header, $mailList);

            $seenEmails = [];
            $row        = 1;
            $batchSize  = 50;
            $i          = 0;

            while (($data = fgetcsv($handle, 0, ';')) !== false) {
                $row++;
                $rowData = $this->extractRowData($data, $columnMap);

                $error = $this->processRow($rowData, $row, $mailList, $columnMap['categories'], $seenEmails, $result);
                if (!$error) {
                    $i++;
                    if ($i % $batchSize === 0) {
                        $this->em->flush();
                    }
                }
            }

            $this->em->flush();
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * @return array{imported: int, skipped: int, errors: list<array{row: int, email?: string, error: string}>}
     */
    public function importFromXlsx(UploadedFile $file, MailList $mailList): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];

        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet       = $spreadsheet->getActiveSheet();
        $rows        = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return $result;
        }

        $header = $this->normalizeHeader(array_shift($rows));
        $columnMap = $this->buildColumnMap($header, $mailList);

        $seenEmails = [];
        $batchSize  = 50;
        $i          = 0;
        $rowNum     = 1;

        foreach ($rows as $data) {
            $rowNum++;
            $rowData = $this->extractRowData(array_values($data), $columnMap);

            $error = $this->processRow($rowData, $rowNum, $mailList, $columnMap['categories'], $seenEmails, $result);
            if (!$error) {
                $i++;
                if ($i % $batchSize === 0) {
                    $this->em->flush();
                }
            }
        }

        $this->em->flush();

        return $result;
    }

    /**
     * @param list<string> $header
     * @return list<string>
     */
    private function normalizeHeader(array $header): array
    {
        return array_map(
            fn($h) => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\xEF\xBB\xBF/u', '', (string) $h)),
            $header,
        );
    }

    /**
     * Maps fixed and taxonomy-category column indexes from the header row.
     *
     * @param list<string> $header
     * @return array{
     *     email: int,
     *     nome: int|false,
     *     cognome: int|false,
     *     telefono: int|false,
     *     categories: list<array{index: int, category: TaxonomyCategory}>
     * }
     */
    private function buildColumnMap(array $header, MailList $mailList): array
    {
        $lowerHeader = array_map(fn($h) => strtolower((string) $h), $header);

        $emailIdx = array_search('email', $lowerHeader, true);
        if ($emailIdx === false) {
            throw new \InvalidArgumentException('missing_email_column');
        }

        $categories = $this->taxonomyCategoryRepository->findByMailList($mailList);
        $categoryColumns = [];
        foreach ($categories as $category) {
            $idx = array_search(strtolower((string) $category->getName()), $lowerHeader, true);
            if ($idx !== false) {
                $categoryColumns[] = ['index' => $idx, 'category' => $category];
            }
        }

        return [
            'email'      => $emailIdx,
            'nome'       => array_search('nome', $lowerHeader, true),
            'cognome'    => array_search('cognome', $lowerHeader, true),
            'telefono'   => array_search('telefono', $lowerHeader, true),
            'categories' => $categoryColumns,
        ];
    }

    /**
     * @param list<string|null> $data
     * @param array{email: int, nome: int|false, cognome: int|false, telefono: int|false, categories: list<array{index: int, category: TaxonomyCategory}>} $columnMap
     * @return array{email: string, nome: ?string, cognome: ?string, telefono: ?string, taxonomies: array<int, list<string>>}
     */
    private function extractRowData(array $data, array $columnMap): array
    {
        $taxonomies = [];
        foreach ($columnMap['categories'] as $cat) {
            $raw = isset($data[$cat['index']]) ? trim((string) $data[$cat['index']]) : '';
            if ($raw === '') {
                continue;
            }
            $terms = array_filter(array_map('trim', explode('|', $raw)), fn($t) => $t !== '');
            if (!empty($terms)) {
                $taxonomies[(int) $cat['category']->getId()] = array_values($terms);
            }
        }

        return [
            'email'      => isset($data[$columnMap['email']]) ? trim((string) $data[$columnMap['email']]) : '',
            'nome'       => $columnMap['nome'] !== false && isset($data[$columnMap['nome']]) ? trim((string) $data[$columnMap['nome']]) : null,
            'cognome'    => $columnMap['cognome'] !== false && isset($data[$columnMap['cognome']]) ? trim((string) $data[$columnMap['cognome']]) : null,
            'telefono'   => $columnMap['telefono'] !== false && isset($data[$columnMap['telefono']]) ? trim((string) $data[$columnMap['telefono']]) : null,
            'taxonomies' => $taxonomies,
        ];
    }

    /**
     * @param array{email: string, nome: ?string, cognome: ?string, telefono: ?string, taxonomies: array<int, list<string>>} $rowData
     * @param list<array{index: int, category: TaxonomyCategory}> $categoryColumns
     * @param array<string, bool> $seenEmails
     * @param array{imported: int, skipped: int, errors: list<array{row: int, email?: string, error: string}>} $result
     */
    private function processRow(
        array $rowData,
        int $row,
        MailList $mailList,
        array $categoryColumns,
        array &$seenEmails,
        array &$result,
    ): bool {
        $email = $rowData['email'];

        if ($email === '') {
            $result['errors'][] = ['row' => $row, 'error' => 'email_required'];
            return true;
        }

        $violations = $this->validator->validate($email, [new Assert\Email()]);
        if (count($violations) > 0) {
            $result['errors'][] = ['row' => $row, 'email' => $email, 'error' => 'email_invalid'];
            return true;
        }

        if (
            isset($seenEmails[$email])
            || $this->contactRepository->findByEmailAndMailList($email, $mailList) !== null
        ) {
            $result['skipped']++;
            return true;
        }

        $seenEmails[$email] = true;

        $contact = new Contact();
        $contact->setEmail($email);
        $contact->setMailList($mailList);

        $nome = $rowData['nome'];
        if ($nome !== null && $nome !== '') {
            $contact->setNome($nome);
        }

        $cognome = $rowData['cognome'];
        if ($cognome !== null && $cognome !== '') {
            $contact->setCognome($cognome);
        }

        $telefono = $rowData['telefono'];
        if ($telefono !== null && $telefono !== '') {
            $contact->setTelefono($telefono);
        }

        $this->em->persist($contact);

        // Attach taxonomy terms (auto-create missing ones in the matching category).
        foreach ($categoryColumns as $cat) {
            $categoryId = (int) $cat['category']->getId();
            $termNames = $rowData['taxonomies'][$categoryId] ?? [];
            foreach ($termNames as $termName) {
                $term = $this->findOrCreateTerm($cat['category'], $termName);
                $assignment = new ContactTaxonomy();
                $assignment->setContact($contact);
                $assignment->setTerm($term);
                $this->em->persist($assignment);
            }
        }

        $result['imported']++;

        return false;
    }

    private function findOrCreateTerm(TaxonomyCategory $category, string $name): TaxonomyTerm
    {
        $name = trim($name);
        foreach ($category->getTerms() as $existing) {
            if (strcasecmp((string) $existing->getLabel(), $name) === 0) {
                return $existing;
            }
        }

        $term = new TaxonomyTerm();
        $term->setLabel($name);
        $term->setCategory($category);
        $term->setColor('#888888');
        $category->addTerm($term);
        $this->em->persist($term);

        return $term;
    }
}
