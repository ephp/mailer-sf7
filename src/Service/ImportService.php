<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\MailList;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ImportService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactRepository $contactRepository,
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

            // Strip BOM and non-printable characters from headers.
            $header = array_map(
                fn($h) => trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\xEF\xBB\xBF/u', '', (string) $h)),
                $header,
            );

            $emailIdx = array_search('email', $header, true);
            if ($emailIdx === false) {
                throw new \InvalidArgumentException('missing_email_column');
            }

            $nomeIdx     = array_search('nome', $header, true);
            $cognomeIdx  = array_search('cognome', $header, true);
            $telefonoIdx = array_search('telefono', $header, true);
            $iscrittoIdx = array_search('iscritto', $header, true);

            // In-memory set to catch within-file duplicates before flush.
            $seenEmails = [];

            $row       = 1;
            $batchSize = 50;
            $i         = 0;

            while (($data = fgetcsv($handle, 0, ';')) !== false) {
                $row++;

                $email = isset($data[$emailIdx]) ? trim((string) $data[$emailIdx]) : '';
                if ($email === '') {
                    $result['errors'][] = ['row' => $row, 'error' => 'email_required'];
                    continue;
                }

                $violations = $this->validator->validate($email, [new Assert\Email()]);
                if (count($violations) > 0) {
                    $result['errors'][] = ['row' => $row, 'email' => $email, 'error' => 'email_invalid'];
                    continue;
                }

                if (
                    isset($seenEmails[$email])
                    || $this->contactRepository->findByEmailAndMailList($email, $mailList) !== null
                ) {
                    $result['skipped']++;
                    continue;
                }

                $seenEmails[$email] = true;

                $contact = new Contact();
                $contact->setEmail($email);
                $contact->setMailList($mailList);

                if ($nomeIdx !== false && isset($data[$nomeIdx])) {
                    $nome = trim((string) $data[$nomeIdx]);
                    $contact->setNome($nome !== '' ? $nome : null);
                }
                if ($cognomeIdx !== false && isset($data[$cognomeIdx])) {
                    $cognome = trim((string) $data[$cognomeIdx]);
                    $contact->setCognome($cognome !== '' ? $cognome : null);
                }
                if ($telefonoIdx !== false && isset($data[$telefonoIdx])) {
                    $telefono = trim((string) $data[$telefonoIdx]);
                    $contact->setTelefono($telefono !== '' ? $telefono : null);
                }
                if ($iscrittoIdx !== false && isset($data[$iscrittoIdx])) {
                    $iscritto = strtolower(trim((string) $data[$iscrittoIdx]));
                    $contact->setIscritto(!in_array($iscritto, ['0', 'false', 'no', 'n', ''], true));
                }

                $this->em->persist($contact);
                $result['imported']++;
                $i++;

                if ($i % $batchSize === 0) {
                    $this->em->flush();
                }
            }

            $this->em->flush();
        } finally {
            fclose($handle);
        }

        return $result;
    }
}
