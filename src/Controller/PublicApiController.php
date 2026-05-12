<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Entity\Contact;
use App\Entity\ContactTaxonomy;
use App\Entity\MailList;
use App\Entity\TaxonomyTerm;
use App\Form\CampaignType;
use App\Message\SendCampaignEmailMessage;
use App\Repository\CampaignEmailRepository;
use App\Repository\ContactRepository;
use App\Repository\ContactTaxonomyRepository;
use App\Repository\LinkStatRepository;
use App\Repository\MailListRepository;
use App\Repository\OpenStatRepository;
use App\Repository\UnsubscribeRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Oi\ApiBundle\Model\ItemDetail;
use Oi\ApiBundle\Service\Form\Interfaces\FormErrorMessageHandlerInterface;
use Ephp\MailflowBundle\Enum\CampaignEmailStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/public/v1')]
class PublicApiController extends AbstractController
{
    public function __construct(
        private readonly FormErrorMessageHandlerInterface $formErrorMessageHandler,
        private readonly MessageBusInterface $bus,
        private readonly ContactRepository $contactRepository,
        #[Autowire('%ephp_mailflow.default_batch_size%')]
        private readonly int $batchSize,
        #[Autowire('%ephp_mailflow.default_send_interval%')]
        private readonly int $sendInterval,
    ) {}

    /**
     * POST /api/public/v1/contacts
     *
     * Upsert a contact in a list identified by list_id.
     * Body: { list_id: int, email: string, nome?: string, cognome?: string, telefono?: string, iscritto?: bool|string }
     */
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

    /**
     * POST /api/public/v1/lists/{listId}/contacts
     *
     * Subscribe (upsert) a contact to a specific list with explicit privacy consent.
     * Body JSON: { email, nome?, cognome?, telefono?, term_ids?: int[], privacy_accepted: boolean }
     */
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

    /**
     * POST /api/public/v1/campaigns
     *
     * Create a new campaign draft.
     * Body: { campaign: { emailSubject: string, name?: string, snippet?: string, body?: string }, mail_list_ids?: int[] }
     */
    #[Route('/campaigns', name: 'public_api_campaigns_new', methods: ['POST'])]
    public function newCampaign(
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $data = $request->request->all();
        $campaignData = $data['campaign'] ?? $data;

        $campaign = new Campaign();
        $campaign->setAccount($account);

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->submit($campaignData);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $detail = new ItemDetail(
                null,
                $this->formErrorMessageHandler->getErrorMessageFromForm($form),
                ItemDetail::MESSAGE_ERROR,
            );
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $em = $doctrine->getManager();
        $this->syncMailLists($campaign, $data['mail_list_ids'] ?? [], $account, $em);

        $em->persist($campaign);
        $em->flush();

        $detail = new ItemDetail($campaign, $translator->trans('campaign.success.created'));
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
    }

    /**
     * POST /api/public/v1/campaigns/{id}/send
     *
     * Trigger sending of a campaign (or schedule it).
     * Body: { scheduled_at?: string (ISO 8601) }
     */
    #[Route('/campaigns/{id}/send', name: 'public_api_campaigns_send', methods: ['POST'])]
    public function sendCampaign(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $em = $doctrine->getManager();
        /** @var Campaign|null $campaign */
        $campaign = $em->find(Campaign::class, $id);

        if ($campaign === null || $campaign->getAccountId() !== $account->getId()) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $scheduledAtRaw = $request->request->get('scheduled_at');
        if ($scheduledAtRaw !== null && $scheduledAtRaw !== '') {
            try {
                $campaign->setScheduledAt(new \DateTime($scheduledAtRaw));
            } catch (\Exception) {
                $detail = new ItemDetail(null, $translator->trans('campaign.error.invalid_date'), ItemDetail::MESSAGE_ERROR);
                return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $campaign->setScheduledAt(null);
        }

        $campaign->setDraft(false);
        $em->flush();

        $this->dispatchCampaignEmails($campaign, $em);

        $message = $campaign->getScheduledAt() !== null
            ? $translator->trans('campaign.success.scheduled')
            : $translator->trans('campaign.success.sent');

        $detail = new ItemDetail($campaign, $message);
        return new Response($serializer->serialize($detail, 'json'));
    }

    /**
     * GET /api/public/v1/campaigns/{id}/stats
     *
     * Return delivery and engagement statistics for a campaign.
     */
    #[Route('/campaigns/{id}/stats', name: 'public_api_campaigns_stats', methods: ['GET'])]
    public function campaignStats(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        CampaignEmailRepository $campaignEmailRepository,
        OpenStatRepository $openStatRepository,
        LinkStatRepository $linkStatRepository,
        UnsubscribeRequestRepository $unsubscribeRepository,
    ): Response {
        /** @var Account $account */
        $account = $request->attributes->get('mailflow_account');

        $em = $doctrine->getManager();
        /** @var Campaign|null $campaign */
        $campaign = $em->find(Campaign::class, $id);

        if ($campaign === null || $campaign->getAccountId() !== $account->getId()) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $totalSent = $campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Sent);
        $totalFailed = $campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Failed);
        $totalPending = $campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Pending);
        $totalBounced = $campaignEmailRepository->countByCampaignAndStatus($campaign, CampaignEmailStatus::Bounced);
        $totalOpens = $openStatRepository->countUniqueByCampaign($campaign);
        $totalClicks = $linkStatRepository->countUniqueByCampaign($campaign);
        $totalUnsubscribes = $unsubscribeRepository->countByCampaign($campaign);

        $stats = [
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
            'total_pending' => $totalPending,
            'total_bounced' => $totalBounced,
            'total_opens' => $totalOpens,
            'total_clicks' => $totalClicks,
            'total_unsubscribes' => $totalUnsubscribes,
            'open_rate' => $totalSent > 0 ? round($totalOpens / $totalSent * 100, 1) : 0.0,
            'click_rate' => $totalSent > 0 ? round($totalClicks / $totalSent * 100, 1) : 0.0,
            'unsubscribe_rate' => $totalSent > 0 ? round($totalUnsubscribes / $totalSent * 100, 1) : 0.0,
        ];

        $detail = new ItemDetail($stats);
        return new Response($serializer->serialize($detail, 'json'));
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

    private function dispatchCampaignEmails(Campaign $campaign, EntityManagerInterface $em): void
    {
        $seen = [];
        $entries = [];

        foreach ($campaign->getMailLists() as $mailList) {
            $contacts = $this->contactRepository->findSubscribedByMailList($mailList);
            foreach ($contacts as $contact) {
                $email = $contact->getEmail();
                if ($email !== null && !isset($seen[$email])) {
                    $seen[$email] = true;
                    $entries[] = ['contact' => $contact, 'list' => $mailList];
                }
            }
        }

        $schedulingDelayMs = 0;
        if ($campaign->getScheduledAt() !== null) {
            $schedulingDelayMs = max(0, $campaign->getScheduledAt()->getTimestamp() - time()) * 1000;
        }

        $sendIntervalMs = $this->sendInterval * 1000;
        $campaignEmails = [];

        foreach ($entries as $i => $data) {
            $campaignEmail = new CampaignEmail();
            $campaignEmail->setCampaign($campaign);
            $campaignEmail->setContact($data['contact']);
            $campaignEmail->setMailList($data['list']);
            $campaignEmail->setEmail($data['contact']->getEmail() ?? '');
            $campaignEmail->setTrackingOpenId($this->generateUuid());
            $em->persist($campaignEmail);
            $campaignEmails[] = ['ce' => $campaignEmail, 'index' => $i];

            if (($i + 1) % $this->batchSize === 0) {
                $em->flush();
            }
        }
        $em->flush();

        foreach ($campaignEmails as $item) {
            /** @var CampaignEmail $campaignEmail */
            $campaignEmail = $item['ce'];
            $i = $item['index'];
            $batchDelayMs = (int) ($i / $this->batchSize) * $sendIntervalMs;
            $totalDelayMs = $schedulingDelayMs + $batchDelayMs;
            $stamps = $totalDelayMs > 0 ? [new DelayStamp($totalDelayMs)] : [];
            $this->bus->dispatch(new SendCampaignEmailMessage($campaignEmail->getId()), $stamps);
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function syncMailLists(
        Campaign $campaign,
        array $listIds,
        Account $account,
        EntityManagerInterface $em,
    ): void {
        $collection = $campaign->getMailLists();
        $collection->clear();

        foreach ($listIds as $listId) {
            $mailList = $em->find(MailList::class, (int) $listId);
            if ($mailList !== null && $mailList->getAccountId() === $account->getId()) {
                $collection->add($mailList);
            }
        }
    }
}
