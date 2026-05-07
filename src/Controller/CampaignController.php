<?php

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\CampaignEmail;
use App\Entity\MailList;
use App\Form\CampaignCreateType;
use App\Form\CampaignType;
use App\Form\CampaignUpdateType;
use App\Message\SendCampaignEmailMessage;
use App\Repository\CampaignEmailRepository;
use App\Repository\CampaignRepository;
use App\Repository\ContactRepository;
use App\Repository\LinkStatRepository;
use App\Repository\OpenStatRepository;
use App\Repository\UnsubscribeRequestRepository;
use App\Service\AccountMailerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Oi\ApiBundle\Model\ItemDetail;
use Oi\ApiBundle\Model\PaginatedList;
use Oi\ApiBundle\Service\Form\Interfaces\FormErrorMessageHandlerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1')]
class CampaignController extends AbstractController
{
    public function __construct(
        private readonly FormErrorMessageHandlerInterface $formErrorMessageHandler,
        private readonly AccountMailerFactory $mailerFactory,
        private readonly MessageBusInterface $bus,
        private readonly ContactRepository $contactRepository,
        #[Autowire('%ephp_mailflow.default_batch_size%')]
        private readonly int $batchSize,
        #[Autowire('%ephp_mailflow.default_send_interval%')]
        private readonly int $sendInterval,
    ) {}

    #[Route('/campaigns', name: 'campaign_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(
        Request $request,
        CampaignRepository $campaignRepository,
        PaginatorInterface $paginator,
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

        $status = $request->query->get('status');
        $template = $request->query->getBoolean('template', false);
        $sort = $request->query->get('sort', 'createdAt');
        $direction = $request->query->get('direction', 'desc');

        $qb = $campaignRepository->findByAccountQuery($account, $status, $template, $sort, $direction);

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 20),
            ['sortFieldParameterName' => '_disabled_sort'],
        );

        return new Response($serializer->serialize(new PaginatedList($pagination), 'json', ['groups' => ['campaign:read']]));
    }

    #[Route('/campaigns/{id}', name: 'campaign_find', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function find(
        int $id,
        CampaignRepository $campaignRepository,
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

        $campaign = $campaignRepository->findOneByIdAndAccount($id, $account);

        if ($campaign === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return new Response($serializer->serialize(new ItemDetail($campaign), 'json', ['groups' => ['campaign:read']]));
    }

    #[Route('/campaigns', name: 'campaign_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(
        Request $request,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $em,
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

        $data = json_decode($request->getContent(), true) ?? [];

        $form = $this->createForm(CampaignCreateType::class);
        $form->submit($data);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $detail = new ItemDetail(
                null,
                $this->formErrorMessageHandler->getErrorMessageFromForm($form),
                ItemDetail::MESSAGE_ERROR,
            );
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $campaign = new Campaign();
        $campaign->setAccount($account);
        $campaign->setDraft(true);
        $campaign->setStatus('draft');
        $campaign->setTemplate(false);

        $fromTemplateId = $form->get('fromTemplateId')->getData();

        if ($fromTemplateId !== null) {
            $template = $campaignRepository->findOneByIdAndAccount((int) $fromTemplateId, $account);

            if ($template === null || !$template->isTemplate()) {
                $detail = new ItemDetail(null, $translator->trans('campaign.error.template_not_found'), ItemDetail::MESSAGE_ERROR);
                return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
            }

            $campaign->setName($template->getName());
            $campaign->setEmailSubject($template->getEmailSubject());
            $campaign->setSnippet($template->getSnippet());
            $campaign->setBody($template->getBody());
            $campaign->setStructure($template->getStructure());
            $campaign->setComposition($template->getComposition());
            $campaign->setFilter($template->getFilter());
            $campaign->setClonedFrom($template);

            foreach ($template->getMailLists() as $mailList) {
                $campaign->getMailLists()->add($mailList);
            }
        }

        $em->persist($campaign);
        $em->flush();

        return new Response(
            $serializer->serialize(new ItemDetail($campaign), 'json', ['groups' => ['campaign:read']]),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/campaigns/{id}', name: 'campaign_update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function update(
        int $id,
        Request $request,
        CampaignRepository $campaignRepository,
        EntityManagerInterface $em,
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

        $campaign = $campaignRepository->findOneByIdAndAccount($id, $account);

        if ($campaign === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        if ($campaign->getStatus() !== 'draft' || $campaign->getSentAt() !== null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_draft'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $form = $this->createForm(CampaignUpdateType::class, $campaign);
        $form->submit($data, false);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $detail = new ItemDetail(
                null,
                $this->formErrorMessageHandler->getErrorMessageFromForm($form),
                ItemDetail::MESSAGE_ERROR,
            );
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('mailListIds', $data)) {
            $this->syncMailLists($campaign, (array) $data['mailListIds'], $account, $em);
        }

        if (array_key_exists('structure', $data) && is_array($data['structure'])) {
            $campaign->setStructure($data['structure']);
        }

        if (array_key_exists('composition', $data) && is_array($data['composition'])) {
            $campaign->setComposition($data['composition']);
        }

        if (array_key_exists('filter', $data) && is_array($data['filter'])) {
            $campaign->setFilter($data['filter']);
        }

        $em->flush();

        return new Response(
            $serializer->serialize(new ItemDetail($campaign), 'json', ['groups' => ['campaign:read']]),
            Response::HTTP_OK,
        );
    }

    #[Route('/campaigns-new', name: 'campaign_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(
        Request $request,
        ManagerRegistry $doctrine,
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

        $campaign = new Campaign();
        $campaign->setAccount($account);

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $this->syncMailLists($campaign, $request->request->all()['mail_list_ids'] ?? [], $account, $em);
            $this->syncStructure($campaign, $request->request->all()['structure'] ?? null);

            $em->persist($campaign);
            $em->flush();

            $detail = new ItemDetail($campaign, $translator->trans('campaign.success.created'));
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/campaigns/{id}/edit', name: 'campaign_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $form = $this->createForm(CampaignType::class, $campaign);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $this->syncMailLists($campaign, $request->request->all()['mail_list_ids'] ?? [], $account, $em);
            $this->syncStructure($campaign, $request->request->all()['structure'] ?? null);
            $em->flush();

            $detail = new ItemDetail($campaign, $translator->trans('campaign.success.updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            $campaign,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/campaigns/{id}/test-email', name: 'campaign_test_email', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function testEmail(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        $recipientEmail = $request->request->get('email') ?? '';
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.invalid_email'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $account = $campaign->getAccount();

        try {
            $mailer = $this->mailerFactory->createMailer($account);
            $fromAddress = $account->getMailFrom();
            $fromName = $account->getMailFromName();

            $email = (new Email())
                ->from(new Address($fromAddress, $fromName))
                ->to($recipientEmail)
                ->subject('[TEST] ' . $campaign->getEmailSubject())
                ->html($campaign->getBody() ?? '');

            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $detail = new ItemDetail(null, $e->getMessage(), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $detail = new ItemDetail(null, $translator->trans('campaign.success.test_sent'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    #[Route('/campaigns/{id}/send', name: 'campaign_send', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function send(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        $scheduledAtRaw = $request->request->get('scheduled_at');
        if ($scheduledAtRaw !== null && $scheduledAtRaw !== '') {
            try {
                $scheduledAt = new \DateTimeImmutable($scheduledAtRaw);
                $campaign->setScheduledAt($scheduledAt);
            } catch (\Exception) {
                $detail = new ItemDetail(null, $translator->trans('campaign.error.invalid_date'), ItemDetail::MESSAGE_ERROR);
                return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $campaign->setScheduledAt(null);
        }

        $campaign->setDraft(false);

        $em = $doctrine->getManager();
        $em->flush();

        $this->dispatchCampaignEmails($campaign, $em);

        $message = $campaign->getScheduledAt() !== null
            ? $translator->trans('campaign.success.scheduled')
            : $translator->trans('campaign.success.sent');

        $detail = new ItemDetail($campaign, $message);
        return new Response($serializer->serialize($detail, 'json'));
    }

    #[Route('/campaigns/{id}/stats', name: 'campaign_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function stats(
        int $id,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        CampaignEmailRepository $campaignEmailRepository,
        OpenStatRepository $openStatRepository,
        LinkStatRepository $linkStatRepository,
        UnsubscribeRequestRepository $unsubscribeRepository,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        $totalSent = $campaignEmailRepository->countByCampaignAndStatus($campaign, \Ephp\MailflowBundle\Enum\CampaignEmailStatus::Sent);
        $totalFailed = $campaignEmailRepository->countByCampaignAndStatus($campaign, \Ephp\MailflowBundle\Enum\CampaignEmailStatus::Failed);
        $totalPending = $campaignEmailRepository->countByCampaignAndStatus($campaign, \Ephp\MailflowBundle\Enum\CampaignEmailStatus::Pending);
        $totalBounced = $campaignEmailRepository->countByCampaignAndStatus($campaign, \Ephp\MailflowBundle\Enum\CampaignEmailStatus::Bounced);
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

    #[Route('/campaign-templates', name: 'campaign_template_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function templates(
        Request $request,
        CampaignRepository $campaignRepository,
        PaginatorInterface $paginator,
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

        $qb = $campaignRepository->createQueryBuilder('c')
            ->where('c.account = :account')
            ->andWhere('c.template = true')
            ->setParameter('account', $account)
            ->orderBy('c.createdAt', 'DESC');

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 50),
        );

        return new Response($serializer->serialize(new PaginatedList($pagination), 'json'));
    }

    #[Route('/campaigns/{id}/duplicate', name: 'campaign_duplicate', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function duplicate(
        int $id,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $source = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($source instanceof Response) {
            return $source;
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $copy = new Campaign();
        $copy->setAccount($account);
        $copy->setName($source->getName());
        $copy->setEmailSubject($source->getEmailSubject());
        $copy->setSnippet($source->getSnippet());
        $copy->setBody($source->getBody());
        $copy->setStructure($source->getStructure());
        $copy->setComposition($source->getComposition());
        $copy->setFilter($source->getFilter());
        $copy->setDraft(true);
        $copy->setTemplate(false);

        $em = $doctrine->getManager();
        foreach ($source->getMailLists() as $mailList) {
            $copy->getMailLists()->add($mailList);
        }

        $em->persist($copy);
        $em->flush();

        $detail = new ItemDetail($copy, $translator->trans('campaign.success.duplicated'));
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
    }

    #[Route('/campaigns/{id}/save-as-template', name: 'campaign_save_as_template', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveAsTemplate(
        int $id,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        $campaign->setTemplate(true);
        $doctrine->getManager()->flush();

        $detail = new ItemDetail($campaign, $translator->trans('campaign.success.saved_as_template'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    #[Route('/campaigns/{id}/delete', name: 'campaign_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        int $id,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $campaign = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($campaign instanceof Response) {
            return $campaign;
        }

        $em = $doctrine->getManager();
        $em->remove($campaign);
        $em->flush();

        $detail = new ItemDetail(null, $translator->trans('campaign.success.deleted'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    private function dispatchCampaignEmails(Campaign $campaign, EntityManagerInterface $em): void
    {
        // Collect subscribed contacts, deduplicated by email address
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

        // Rate limiting: spread emails across batches with sendInterval seconds between each batch
        $sendIntervalMs = $this->sendInterval * 1000;

        // Persist all CampaignEmail records in batches, collect objects for dispatch
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

        // Dispatch messages — IDs are available after flush
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

    private function findCampaignForUser(
        int $id,
        ManagerRegistry $doctrine,
        TranslatorInterface $translator,
        SerializerInterface $serializer,
    ): Campaign|Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $campaign = $doctrine->getManager()->find(Campaign::class, $id);

        if ($campaign === null || $campaign->getAccountId() !== $account?->getId()) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return $campaign;
    }

    private function syncMailLists(
        Campaign $campaign,
        array $listIds,
        ?\App\Entity\Account $account,
        EntityManagerInterface $em,
    ): void {
        $collection = $campaign->getMailLists();
        $collection->clear();

        foreach ($listIds as $listId) {
            $mailList = $em->find(MailList::class, (int) $listId);
            if ($mailList !== null && $mailList->getAccountId() === $account?->getId()) {
                $collection->add($mailList);
            }
        }
    }

    private function syncStructure(Campaign $campaign, mixed $structure): void
    {
        if (is_array($structure)) {
            $campaign->setStructure($structure);
        }
    }
}
