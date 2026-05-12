<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Campaign;
use App\Entity\Contact;
use App\Entity\ContactTaxonomy;
use App\Entity\MailList;
use App\Entity\TaxonomyTerm;
use App\Repository\ContactRepository;
use App\Repository\ContactTaxonomyRepository;
use App\Repository\MailListRepository;
use App\Service\CampaignSenderService;
use App\Service\PrivacyPolicyService;
use App\Service\StatisticsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oi\ApiBundle\Model\ItemDetail;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/public/v1')]
class PublicApiController extends AbstractController
{
    public function __construct(
        private readonly ContactRepository $contactRepository,
    ) {}

    #[OA\Post(
        path: '/api/public/v1/contacts',
        operationId: 'upsertContact',
        summary: 'Crea o aggiorna un contatto (endpoint legacy)',
        description: 'Endpoint precedente per upsert contatto tramite form-data. Usa POST /lists/{listId}/contacts per le nuove integrazioni.',
        tags: ['Contacts'],
        deprecated: true
    )]
    #[OA\Response(response: 201, description: 'Contatto creato')]
    #[OA\Response(response: 200, description: 'Contatto aggiornato')]
    #[OA\Response(response: 422, description: 'Errore di validazione')]
    #[Route('/contacts', name: 'public_api_contacts_upsert', methods: ['POST'])]
    public function upsertContact(
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        ValidatorInterface $validator,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $data = $request->request->all();

        $listId = isset($data['list_id']) ? (int) $data['list_id'] : null;
        if ($listId === null || $listId <= 0) {
            $detail = new ItemDetail(null, 'list_id is required', ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em = $doctrine->getManager();
        /** @var MailList|null $mailList */
        $mailList = $em->find(MailList::class, $listId);

        if ($mailList === null || $mailList->getAccountId() !== $account->getId()) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            $detail = new ItemDetail(null, 'email is required', ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $violations = $validator->validate($email, [new Assert\Email()]);
        if (count($violations) > 0) {
            $detail = new ItemDetail(null, 'Invalid email address', ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $contact = $this->contactRepository->findByEmailAndMailList($email, $mailList);
        $isNew = $contact === null;

        if ($isNew) {
            $contact = new Contact();
            $contact->setEmail($email);
            $contact->setMailList($mailList);
        }

        if (array_key_exists('nome', $data)) {
            $contact->setNome((string) $data['nome'] !== '' ? (string) $data['nome'] : null);
        }
        if (array_key_exists('cognome', $data)) {
            $contact->setCognome((string) $data['cognome'] !== '' ? (string) $data['cognome'] : null);
        }
        if (array_key_exists('telefono', $data)) {
            $contact->setTelefono((string) $data['telefono'] !== '' ? (string) $data['telefono'] : null);
        }
        if (array_key_exists('iscritto', $data)) {
            $iscrittoRaw = strtolower((string) $data['iscritto']);
            $contact->setIscritto(!in_array($iscrittoRaw, ['0', 'false', 'no', 'n', ''], true));
        }

        if ($isNew) {
            $em->persist($contact);
        }
        $em->flush();

        $message = $isNew
            ? $translator->trans('contact.success.created')
            : $translator->trans('contact.success.updated');
        $status = $isNew ? Response::HTTP_CREATED : Response::HTTP_OK;

        $detail = new ItemDetail($contact, $message);
        return new Response($serializer->serialize($detail, 'json'), $status);
    }

    #[OA\Post(
        path: '/api/public/v1/lists/{listId}/contacts',
        operationId: 'subscribe',
        summary: 'Iscrive o aggiorna un contatto in una lista',
        description: 'Esegue upsert di un contatto nella lista specificata con consenso privacy esplicito. Se il contatto esiste ed è discritto, viene automaticamente reiscritto. I term_ids vengono filtrati ai termini appartenenti alla lista.',
        tags: ['Contacts']
    )]
    #[OA\Parameter(
        name: 'listId',
        in: 'path',
        required: true,
        description: 'ID della lista di destinazione',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'privacy_accepted'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'mario.rossi@esempio.it'),
                new OA\Property(property: 'nome', type: 'string', example: 'Mario', nullable: true),
                new OA\Property(property: 'cognome', type: 'string', example: 'Rossi', nullable: true),
                new OA\Property(property: 'telefono', type: 'string', example: '+39 055 123456', nullable: true),
                new OA\Property(property: 'term_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'IDs dei termini di tassonomia da applicare'),
                new OA\Property(property: 'privacy_accepted', type: 'boolean', example: true, description: 'Deve essere true per procedere'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Contatto creato con successo',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'contact_id', type: 'integer', example: 42),
                new OA\Property(property: 'email', type: 'string', example: 'mario.rossi@esempio.it'),
                new OA\Property(property: 'iscritto', type: 'boolean', example: true),
                new OA\Property(property: 'privacy_accepted_at', type: 'string', format: 'date-time', example: '2026-05-12T10:00:00+00:00'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Contatto aggiornato con successo',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'contact_id', type: 'integer', example: 42),
                new OA\Property(property: 'email', type: 'string', example: 'mario.rossi@esempio.it'),
                new OA\Property(property: 'iscritto', type: 'boolean', example: true),
                new OA\Property(property: 'privacy_accepted_at', type: 'string', format: 'date-time', example: '2026-05-12T10:00:00+00:00'),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Lista non trovata',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'not_found'),
                new OA\Property(property: 'message', type: 'string', example: 'List not found'),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Errore di validazione (email non valida o privacy_accepted mancante)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'subscribe.error.privacy_required'),
                new OA\Property(property: 'message', type: 'string', example: 'Privacy acceptance is required'),
            ]
        )
    )]
    #[Route('/lists/{listId}/contacts', name: 'public_api_lists_contacts_subscribe', methods: ['POST'])]
    public function subscribe(
        int $listId,
        Request $request,
        ManagerRegistry $doctrine,
        MailListRepository $mailListRepository,
        ContactTaxonomyRepository $contactTaxonomyRepository,
        ValidatorInterface $validator,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $mailList = $mailListRepository->findOneByIdAndAccount($listId, $account);
        if ($mailList === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'List not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            return new JsonResponse(['error' => 'validation_error', 'message' => 'email is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $violations = $validator->validate($email, [new Assert\Email()]);
        if (count($violations) > 0) {
            return new JsonResponse(['error' => 'validation_error', 'message' => 'Invalid email address'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (($data['privacy_accepted'] ?? null) !== true) {
            return new JsonResponse(['error' => 'subscribe.error.privacy_required', 'message' => 'Privacy acceptance is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em = $doctrine->getManager();

        $contact = $this->contactRepository->findByEmailAndMailList($email, $mailList);
        $isNew = $contact === null;

        if ($isNew) {
            $contact = new Contact();
            $contact->setEmail($email);
            $contact->setMailList($mailList);
            $contact->setIscritto(true);
        } elseif (!$contact->isIscritto()) {
            $contact->resubscribe();
        }

        if (isset($data['nome']) && (string) $data['nome'] !== '') {
            $contact->setNome((string) $data['nome']);
        }
        if (isset($data['cognome']) && (string) $data['cognome'] !== '') {
            $contact->setCognome((string) $data['cognome']);
        }
        if (isset($data['telefono']) && (string) $data['telefono'] !== '') {
            $contact->setTelefono((string) $data['telefono']);
        }

        $contact->setPrivacyAcceptedAt(new \DateTime());

        if ($isNew) {
            $em->persist($contact);
        }
        $em->flush();

        $termIds = array_values(array_filter(array_map('intval', (array) ($data['term_ids'] ?? []))));
        if ($termIds !== []) {
            $this->applyTermIds($contact, $mailList, $termIds, $em, $contactTaxonomyRepository);
        }

        return new JsonResponse([
            'contact_id' => $contact->getId(),
            'email' => $contact->getEmail(),
            'iscritto' => $contact->isIscritto(),
            'privacy_accepted_at' => $contact->getPrivacyAcceptedAt()?->format(\DateTimeInterface::ATOM),
        ], $isNew ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[OA\Delete(
        path: '/api/public/v1/lists/{listId}/contacts/{email}',
        operationId: 'unsubscribe',
        summary: 'Disiscrive un contatto da una lista',
        description: 'Disiscrive il contatto identificato dall\'email dalla lista specificata. Idempotente: restituisce 404 se il contatto non esiste, senza generare errori su chiamate ripetute.',
        tags: ['Contacts']
    )]
    #[OA\Parameter(
        name: 'listId',
        in: 'path',
        required: true,
        description: 'ID della lista',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Parameter(
        name: 'email',
        in: 'path',
        required: true,
        description: 'Indirizzo email del contatto (URL-encoded se contiene caratteri speciali)',
        schema: new OA\Schema(type: 'string', example: 'mario.rossi%40esempio.it')
    )]
    #[OA\Response(response: 204, description: 'Contatto discritto con successo')]
    #[OA\Response(
        response: 404,
        description: 'Lista o contatto non trovato',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'not_found'),
                new OA\Property(property: 'message', type: 'string', example: 'Contact not found'),
            ]
        )
    )]
    #[Route('/lists/{listId}/contacts/{email}', name: 'public_api_lists_contacts_unsubscribe', methods: ['DELETE'], requirements: ['email' => '.+'])]
    public function unsubscribe(
        int $listId,
        string $email,
        Request $request,
        ManagerRegistry $doctrine,
        MailListRepository $mailListRepository,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $mailList = $mailListRepository->findOneByIdAndAccount($listId, $account);
        if ($mailList === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'List not found'], Response::HTTP_NOT_FOUND);
        }

        $email = urldecode($email);

        $contact = $this->contactRepository->findByEmailAndMailList($email, $mailList);
        if ($contact === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Contact not found'], Response::HTTP_NOT_FOUND);
        }

        $contact->unsubscribe('api_client');
        $doctrine->getManager()->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[OA\Get(
        path: '/api/public/v1/lists/{listId}/contacts/{email}',
        operationId: 'findContact',
        summary: 'Recupera lo stato di un contatto in una lista',
        description: 'Restituisce i dati e lo stato di iscrizione di un contatto, inclusi i termini di tassonomia associati e la data di consenso privacy.',
        tags: ['Contacts']
    )]
    #[OA\Parameter(
        name: 'listId',
        in: 'path',
        required: true,
        description: 'ID della lista',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Parameter(
        name: 'email',
        in: 'path',
        required: true,
        description: 'Indirizzo email del contatto (URL-encoded se contiene caratteri speciali)',
        schema: new OA\Schema(type: 'string', example: 'mario.rossi%40esempio.it')
    )]
    #[OA\Response(
        response: 200,
        description: 'Dati del contatto',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'mario.rossi@esempio.it'),
                new OA\Property(property: 'nome', type: 'string', example: 'Mario', nullable: true),
                new OA\Property(property: 'cognome', type: 'string', example: 'Rossi', nullable: true),
                new OA\Property(property: 'telefono', type: 'string', example: '+39 055 123456', nullable: true),
                new OA\Property(property: 'iscritto', type: 'boolean', example: true),
                new OA\Property(property: 'term_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'IDs dei termini di tassonomia associati'),
                new OA\Property(property: 'privacy_accepted_at', type: 'string', format: 'date-time', nullable: true, example: '2026-05-12T10:00:00+00:00'),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Lista o contatto non trovato',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'not_found'),
                new OA\Property(property: 'message', type: 'string', example: 'Contact not found'),
            ]
        )
    )]
    #[Route('/lists/{listId}/contacts/{email}', name: 'public_api_lists_contacts_find', methods: ['GET'], requirements: ['email' => '.+'])]
    public function findContact(
        int $listId,
        string $email,
        Request $request,
        MailListRepository $mailListRepository,
        ContactTaxonomyRepository $contactTaxonomyRepository,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $mailList = $mailListRepository->findOneByIdAndAccount($listId, $account);
        if ($mailList === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'List not found'], Response::HTTP_NOT_FOUND);
        }

        $email = urldecode($email);

        $contact = $this->contactRepository->findByEmailAndMailList($email, $mailList);
        if ($contact === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Contact not found'], Response::HTTP_NOT_FOUND);
        }

        $termIds = $contactTaxonomyRepository->findTermIdsByContact($contact);

        return new JsonResponse([
            'email' => $contact->getEmail(),
            'nome' => $contact->getNome(),
            'cognome' => $contact->getCognome(),
            'telefono' => $contact->getTelefono(),
            'iscritto' => $contact->isIscritto(),
            'term_ids' => $termIds,
            'privacy_accepted_at' => $contact->getPrivacyAcceptedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[OA\Get(
        path: '/api/public/v1/lists',
        operationId: 'lists',
        summary: 'Elenca le liste disponibili per l\'account',
        description: 'Restituisce tutte le liste di iscrizione attive associate all\'account autenticato, ordinate alfabeticamente per nome.',
        tags: ['Lists']
    )]
    #[OA\Response(
        response: 200,
        description: 'Elenco delle liste',
        content: new OA\JsonContent(
            type: 'array',
            items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer', example: 1),
                    new OA\Property(property: 'name', type: 'string', example: 'Newsletter mensile'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Aggiornamenti mensili per i clienti'),
                    new OA\Property(property: 'subscribe_token', type: 'string', example: 'abc123xyz'),
                ]
            )
        )
    )]
    #[Route('/lists', name: 'public_api_lists_index', methods: ['GET'])]
    public function lists(
        Request $request,
        MailListRepository $mailListRepository,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $mailLists = $mailListRepository->findByAccount($account);

        $data = array_map(static fn(MailList $l) => [
            'id' => $l->getId(),
            'name' => $l->getName(),
            'description' => $l->getDescription(),
            'subscribe_token' => $l->getSubscribeToken(),
        ], $mailLists);

        return new JsonResponse($data);
    }

    #[OA\Get(
        path: '/api/public/v1/lists/{listId}/privacy-policy',
        operationId: 'privacyPolicy',
        summary: 'Recupera il testo della privacy policy della lista',
        description: 'Restituisce il testo della privacy policy dell\'account, con i placeholder sostituiti (nome azienda, email di contatto, ecc.). Se l\'account non ha una privacy policy personalizzata, viene usato il template GDPR di default.',
        tags: ['Lists']
    )]
    #[OA\Parameter(
        name: 'listId',
        in: 'path',
        required: true,
        description: 'ID della lista',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Testo della privacy policy renderizzato',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'privacy_policy', type: 'string', example: 'Ai sensi del Regolamento UE 2016/679 (GDPR), la Società Esempio S.r.l. ti informa che i tuoi dati personali...'),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Lista non trovata',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'not_found'),
                new OA\Property(property: 'message', type: 'string', example: 'List not found'),
            ]
        )
    )]
    #[Route('/lists/{listId}/privacy-policy', name: 'public_api_lists_privacy_policy', methods: ['GET'])]
    public function privacyPolicy(
        int $listId,
        Request $request,
        MailListRepository $mailListRepository,
        PrivacyPolicyService $privacyPolicyService,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $mailList = $mailListRepository->findOneByIdAndAccount($listId, $account);
        if ($mailList === null) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'List not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'privacy_policy' => $privacyPolicyService->render($account),
        ]);
    }

    #[OA\Post(
        path: '/api/public/v1/campaigns',
        operationId: 'newCampaign',
        summary: 'Crea una nuova campagna in bozza',
        description: 'Crea una nuova campagna email in stato draft, associata alle liste specificate. I taxonomyTermIds vengono filtrati automaticamente ai termini appartenenti alle liste indicate.',
        tags: ['Campaigns']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['emailSubject', 'body', 'mailListIds'],
            properties: [
                new OA\Property(property: 'emailSubject', type: 'string', example: 'Offerta speciale maggio 2026'),
                new OA\Property(property: 'body', type: 'string', example: '<h1>Ciao!</h1><p>Scopri le nostre offerte...</p>'),
                new OA\Property(property: 'mailListIds', type: 'array', items: new OA\Items(type: 'integer'), description: 'IDs delle liste a cui inviare la campagna'),
                new OA\Property(property: 'name', type: 'string', example: 'Campagna maggio 2026', nullable: true),
                new OA\Property(property: 'snippet', type: 'string', example: 'Scopri le nostre offerte esclusive...', nullable: true),
                new OA\Property(property: 'structure', type: 'object', description: 'Struttura JSON del template drag-and-drop', nullable: true),
                new OA\Property(property: 'taxonomyTermIds', type: 'array', items: new OA\Items(type: 'integer'), description: 'Filtra i destinatari per termini di tassonomia'),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Campagna creata con successo',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'campaign_id', type: 'integer', example: 10),
                new OA\Property(property: 'status', type: 'string', example: 'draft'),
            ]
        )
    )]
    #[OA\Response(
        response: 422,
        description: 'Errore di validazione (campi obbligatori mancanti o lista non trovata)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'validation_error'),
                new OA\Property(property: 'message', type: 'string', example: 'emailSubject is required'),
            ]
        )
    )]
    #[Route('/campaigns', name: 'public_api_campaigns_new', methods: ['POST'])]
    public function newCampaign(
        Request $request,
        ManagerRegistry $doctrine,
        MailListRepository $mailListRepository,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $emailSubject = trim((string) ($data['emailSubject'] ?? ''));
        if ($emailSubject === '') {
            return new JsonResponse(['error' => 'validation_error', 'message' => 'emailSubject is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            return new JsonResponse(['error' => 'validation_error', 'message' => 'body is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mailListIds = array_values(array_filter(array_map('intval', (array) ($data['mailListIds'] ?? []))));
        if ($mailListIds === []) {
            return new JsonResponse(['error' => 'validation_error', 'message' => 'mailListIds is required and must be non-empty'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em = $doctrine->getManager();
        $mailLists = [];
        foreach ($mailListIds as $listId) {
            $mailList = $mailListRepository->findOneByIdAndAccount($listId, $account);
            if ($mailList === null) {
                return new JsonResponse(['error' => 'validation_error', 'message' => sprintf('Mail list %d not found', $listId)], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $mailLists[] = $mailList;
        }

        $taxonomyTermIds = array_values(array_filter(array_map('intval', (array) ($data['taxonomyTermIds'] ?? []))));
        $filteredTermIds = [];
        if ($taxonomyTermIds !== []) {
            $filteredTermIds = array_column(
                $em->createQueryBuilder()
                    ->select('t.id')
                    ->from(TaxonomyTerm::class, 't')
                    ->innerJoin('t.category', 'cat')
                    ->where('t.id IN (:ids)')
                    ->andWhere('cat.mailList IN (:lists)')
                    ->setParameter('ids', $taxonomyTermIds)
                    ->setParameter('lists', $mailLists)
                    ->getQuery()
                    ->getArrayResult(),
                'id',
            );
        }

        $campaign = new Campaign();
        $campaign->setAccount($account);
        $campaign->setEmailSubject($emailSubject);
        $campaign->setBody($body);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            $campaign->setName($name);
        }

        $snippet = trim((string) ($data['snippet'] ?? ''));
        if ($snippet !== '') {
            $campaign->setSnippet($snippet);
        }

        if (isset($data['structure']) && is_array($data['structure'])) {
            $campaign->setStructure($data['structure']);
        }

        if ($filteredTermIds !== []) {
            $campaign->setFilter(['taxonomyTermIds' => $filteredTermIds]);
        }

        foreach ($mailLists as $mailList) {
            $campaign->getMailLists()->add($mailList);
        }

        $em->persist($campaign);
        $em->flush();

        return new JsonResponse([
            'campaign_id' => $campaign->getId(),
            'status' => $campaign->getStatus(),
        ], Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/public/v1/campaigns/{id}/send',
        operationId: 'sendCampaign',
        summary: 'Avvia l\'invio di una campagna',
        description: 'Prepara e avvia l\'invio della campagna specificata. La campagna deve essere in stato draft o scheduled. Dopo la chiamata lo stato passa a sending.',
        tags: ['Campaigns']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID della campagna',
        schema: new OA\Schema(type: 'integer', example: 10)
    )]
    #[OA\Response(
        response: 202,
        description: 'Invio avviato con successo',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'campaign_id', type: 'integer', example: 10),
                new OA\Property(property: 'status', type: 'string', example: 'sending'),
                new OA\Property(property: 'recipients', type: 'integer', example: 1250, description: 'Numero di destinatari a cui verrà inviata la campagna'),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Campagna non trovata',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'not_found'),
                new OA\Property(property: 'message', type: 'string', example: 'Campaign not found'),
            ]
        )
    )]
    #[OA\Response(
        response: 409,
        description: 'Campagna già in invio o già inviata',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'invalid_state'),
                new OA\Property(property: 'message', type: 'string', example: 'Campaign already sending or sent'),
            ]
        )
    )]
    #[Route('/campaigns/{id}/send', name: 'public_api_campaigns_send', methods: ['POST'])]
    public function sendCampaign(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        CampaignSenderService $campaignSenderService,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $em = $doctrine->getManager();
        /** @var Campaign|null $campaign */
        $campaign = $em->find(Campaign::class, $id);

        if ($campaign === null || $campaign->getAccountId() !== $account->getId()) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Campaign not found'], Response::HTTP_NOT_FOUND);
        }

        if (!in_array($campaign->getStatus(), ['draft', 'scheduled'], true)) {
            return new JsonResponse(['error' => 'invalid_state', 'message' => 'Campaign already sending or sent'], Response::HTTP_CONFLICT);
        }

        $recipients = $campaignSenderService->prepareCampaign($campaign);
        $campaignSenderService->dispatchAll($campaign);

        $campaign->setStatus('sending');
        $em->flush();

        return new JsonResponse(
            ['campaign_id' => $campaign->getId(), 'status' => 'sending', 'recipients' => $recipients],
            Response::HTTP_ACCEPTED
        );
    }

    #[OA\Get(
        path: '/api/public/v1/campaigns/{id}/stats',
        operationId: 'campaignStats',
        summary: 'Recupera le statistiche di una campagna',
        description: 'Restituisce le statistiche complete di una campagna: consegne, aperture, click, disiscrizioni e i relativi tassi percentuali.',
        tags: ['Stats']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'ID della campagna',
        schema: new OA\Schema(type: 'integer', example: 10)
    )]
    #[OA\Response(
        response: 200,
        description: 'Statistiche della campagna',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'total', type: 'integer', example: 1250),
                new OA\Property(property: 'sent', type: 'integer', example: 1230),
                new OA\Property(property: 'failed', type: 'integer', example: 20),
                new OA\Property(property: 'bounced', type: 'integer', example: 15),
                new OA\Property(property: 'unique_opens', type: 'integer', example: 430),
                new OA\Property(property: 'total_opens', type: 'integer', example: 610),
                new OA\Property(property: 'unique_clicks', type: 'integer', example: 120),
                new OA\Property(property: 'total_clicks', type: 'integer', example: 185),
                new OA\Property(property: 'unsubscribes', type: 'integer', example: 8),
                new OA\Property(property: 'open_rate', type: 'number', format: 'float', example: 34.96),
                new OA\Property(property: 'click_rate', type: 'number', format: 'float', example: 9.76),
                new OA\Property(property: 'click_to_open_rate', type: 'number', format: 'float', example: 27.91),
                new OA\Property(property: 'unsubscribe_rate', type: 'number', format: 'float', example: 0.65),
            ]
        )
    )]
    #[OA\Response(
        response: 404,
        description: 'Campagna non trovata',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'error', type: 'string', example: 'not_found'),
                new OA\Property(property: 'message', type: 'string', example: 'Campaign not found'),
            ]
        )
    )]
    #[Route('/campaigns/{id}/stats', name: 'public_api_campaigns_stats', methods: ['GET'])]
    public function campaignStats(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        StatisticsService $statisticsService,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $em = $doctrine->getManager();
        /** @var Campaign|null $campaign */
        $campaign = $em->find(Campaign::class, $id);

        if ($campaign === null || $campaign->getAccountId() !== $account->getId()) {
            return new JsonResponse(['error' => 'not_found', 'message' => 'Campaign not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($statisticsService->getCampaignStats($campaign));
    }

    /**
     * @param int[] $termIds
     */
    private function applyTermIds(
        Contact $contact,
        MailList $mailList,
        array $termIds,
        EntityManagerInterface $em,
        ContactTaxonomyRepository $contactTaxonomyRepository,
    ): void {
        /** @var TaxonomyTerm[] $terms */
        $terms = $em->createQueryBuilder()
            ->select('t')
            ->from(TaxonomyTerm::class, 't')
            ->innerJoin('t.category', 'cat')
            ->where('t.id IN (:ids)')
            ->andWhere('cat.mailList = :list')
            ->setParameter('ids', $termIds)
            ->setParameter('list', $mailList)
            ->getQuery()
            ->getResult();

        $existingTermIds = $contactTaxonomyRepository->findTermIdsByContact($contact);

        foreach ($terms as $term) {
            if (!in_array($term->getId(), $existingTermIds, true)) {
                $ct = new ContactTaxonomy();
                $ct->setContact($contact);
                $ct->setTerm($term);
                $em->persist($ct);
            }
        }
        $em->flush();
    }

}
