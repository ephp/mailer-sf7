<?php

namespace App\Controller;

use App\Entity\Campaign;
use App\Entity\CampaignAttachment;
use App\Entity\CampaignEmail;
use App\Entity\MailList;
use App\Form\CampaignCreateType;
use App\Form\CampaignType;
use App\Form\CampaignUpdateType;
use App\Message\SendCampaignEmailMessage;
use App\Repository\CampaignEmailRepository;
use App\Repository\CampaignRepository;
use App\Repository\ContactRepository;
use App\Service\AccountMailerFactory;
use App\Service\CampaignSenderService;
use App\Service\EmailTemplateRenderer;
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
        private readonly CampaignSenderService $campaignSenderService,
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
        CampaignEmailRepository $campaignEmailRepository,
        \App\Repository\OpenStatRepository $openStatRepository,
        \App\Repository\LinkStatRepository $linkStatRepository,
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
        $search = $request->query->get('fts');

        $qb = $campaignRepository->findByAccountQuery($account, $status, $template, $sort, $direction, $search);

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 20),
            ['sortFieldParameterName' => '_disabled_sort'],
        );

        /** @var Campaign[] $items */
        $items = iterator_to_array($pagination);
        $ids = array_values(array_filter(array_map(fn(Campaign $c) => $c->getId(), $items)));
        if ($ids !== []) {
            $sentMap = $campaignEmailRepository->countByCampaignIdsAndStatus($ids, \Ephp\MailflowBundle\Enum\CampaignEmailStatus::Sent);
            $opensMap = $openStatRepository->countUniqueByCampaignIds($ids);
            $clicksMap = $linkStatRepository->countUniqueByCampaignIds($ids);
            foreach ($items as $c) {
                $cid = (int) $c->getId();
                $c->setStats(
                    $sentMap[$cid] ?? 0,
                    $opensMap[$cid] ?? 0,
                    $clicksMap[$cid] ?? 0,
                );
            }
        }

        return new Response($serializer->serialize(new PaginatedList($pagination), 'json', ['groups' => ['campaign:read']]));
    }

    #[Route('/campaigns/{id}', name: 'campaign_find', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function find(
        int $id,
        CampaignRepository $campaignRepository,
        \App\Repository\CampaignAttachmentRepository $attachmentRepository,
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

        $campaign->setAttachmentsPayload($this->buildAttachmentsPayload($attachmentRepository->findByCampaign($campaign)));

        return new Response($serializer->serialize(new ItemDetail($campaign), 'json', ['groups' => ['campaign:read']]));
    }

    /**
     * @param iterable<\App\Entity\CampaignAttachment> $attachments
     * @return array<int, array{id:int, filename:string, size:int, mimetype:?string, url:string}>
     */
    private function buildAttachmentsPayload(iterable $attachments): array
    {
        $out = [];
        foreach ($attachments as $att) {
            $out[] = [
                'id' => (int) $att->getId(),
                'filename' => $att->getFilename(),
                'size' => $att->getSize(),
                'mimetype' => $att->getMimetype(),
                'url' => (string) ($att->getUpload()?->getUrl() ?? ''),
            ];
        }
        return $out;
    }

    #[Route('/campaigns/{id}/preview', name: 'campaign_preview', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function preview(
        int $id,
        CampaignRepository $campaignRepository,
        EmailTemplateRenderer $renderer,
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

        $rendered = $renderer->render($campaign);

        $detail = new ItemDetail(['html' => $rendered->html, 'plain_text' => $rendered->plainText]);
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_OK);
    }

    #[Route('/campaigns/{id}/send-test', name: 'campaign_send_test', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendTest(
        int $id,
        Request $request,
        CampaignRepository $campaignRepository,
        EmailTemplateRenderer $renderer,
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

        $data = json_decode($request->getContent(), true) ?? [];
        $emails = array_values(array_filter((array) ($data['emails'] ?? []), 'is_string'));

        if (count($emails) === 0 || count($emails) > 5) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.send_test_email_count'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($emails as $emailAddr) {
            if (!filter_var($emailAddr, FILTER_VALIDATE_EMAIL)) {
                $detail = new ItemDetail(null, $translator->trans('campaign.error.invalid_email'), ItemDetail::MESSAGE_ERROR);
                return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $dsnOverride = null;
        foreach ($campaign->getMailLists() as $mailList) {
            $dsnOverride = $mailList->getEffectiveDsn();
            if ($dsnOverride !== null) {
                break;
            }
        }

        try {
            $mailer = $this->mailerFactory->createMailer($account, $dsnOverride);
        } catch (\RuntimeException $e) {
            $detail = new ItemDetail(null, $e->getMessage(), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $rendered = $renderer->render($campaign);
        $fromAddress = $account->getMailFrom() ?? '';
        $fromName = $account->getMailFromName() ?? '';
        $subject = '[TEST] ' . ($campaign->getEmailSubject() ?? '');

        $sent = 0;
        $failed = 0;
        $errors = [];

        foreach ($emails as $recipientEmail) {
            try {
                $email = (new Email())
                    ->from(new Address($fromAddress, $fromName))
                    ->to($recipientEmail)
                    ->subject($subject)
                    ->html($rendered->html)
                    ->text($rendered->plainText);

                $email->getHeaders()->addTextHeader('Content-Language', 'it');

                $mailer->send($email);
                $sent++;
            } catch (TransportExceptionInterface $e) {
                $failed++;
                $errors[] = $recipientEmail . ': ' . $e->getMessage();
            }
        }

        $result = ['sent' => $sent, 'failed' => $failed];
        if (!empty($errors)) {
            $result['errors'] = $errors;
        }

        return new Response($serializer->serialize(new ItemDetail($result), 'json'), Response::HTTP_OK);
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

    #[Route('/campaigns/{id}', name: 'campaign_update', methods: ['PUT', 'PATCH'])]
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

    #[Route('/campaigns/{id}', name: 'campaign_destroy', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function destroy(
        int $id,
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

        if (!$campaign->isDraft() || $campaign->getSentAt() !== null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_draft'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CONFLICT);
        }

        $em->remove($campaign);
        $em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/campaigns/recipients-count', name: 'campaign_recipients_count', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function recipientsCount(
        Request $request,
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
        $mailListIds = (array) ($data['mailListIds'] ?? []);
        $taxonomyTermIds = array_map('intval', (array) ($data['filter']['taxonomyTermIds'] ?? []));

        $validMailLists = [];
        foreach ($mailListIds as $listId) {
            $mailList = $em->find(MailList::class, (int) $listId);
            if ($mailList !== null && $mailList->getAccountId() === $account->getId()) {
                $validMailLists[] = $mailList;
            }
        }

        $count = $this->contactRepository->countRecipientsForLists($validMailLists, $taxonomyTermIds);

        $detail = new ItemDetail(['count' => $count]);
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_OK);
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
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        if ($campaign->getMailLists()->isEmpty()) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.no_mail_lists'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        if ($account->getEffectiveDsn() === null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.smtp_not_configured'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        if ($this->campaignSenderService->countRecipients($campaign) === 0) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.no_recipients'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        $totalEmails = $this->campaignSenderService->prepareCampaign($campaign);
        $this->campaignSenderService->dispatchAll($campaign);

        $campaign->setStatus('sending');
        $em->flush();

        $detail = new ItemDetail(['total_emails' => $totalEmails]);
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_ACCEPTED);
    }

    #[Route('/campaigns/{id}/attachments', name: 'campaign_attachment_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function attachmentUpload(
        int $id,
        Request $request,
        CampaignRepository $campaignRepository,
        \App\Repository\CampaignAttachmentRepository $attachmentRepository,
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        \Oi\FileBundle\Service\FileHandlerInterface $fileHandler,
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

        $file = $request->files->get('file');
        if ($file === null) {
            $detail = new ItemDetail(null, 'No file provided', ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        // Move into public/uploads/campaign_attachment first, then let
        // FileHandler register the Upload (same pattern as the Account logo).
        $targetDir = $this->getParameter('kernel.project_dir') . '/public/uploads/campaign_attachment';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Unable to create directory $targetDir");
        }
        $ext = $file->guessExtension() ?: 'bin';
        $originalName = $file->getClientOriginalName();
        $movedFile = $file->move($targetDir, uniqid('att_', true) . '.' . $ext);

        $upload = $fileHandler->makeUploadFromUploadedFile($movedFile, $request);

        $attachment = new CampaignAttachment();
        $attachment->setCampaign($campaign);
        $attachment->setUpload($upload);
        $attachment->setFilename($originalName);
        $attachment->setSize($upload->getSize());
        $attachment->setMimetype($upload->getMimetype());

        $em->persist($attachment);
        $em->flush();

        $payload = [
            'id' => (int) $attachment->getId(),
            'filename' => $attachment->getFilename(),
            'size' => $attachment->getSize(),
            'mimetype' => $attachment->getMimetype(),
            'url' => (string) ($upload->getUrl() ?? ''),
        ];
        return new Response($serializer->serialize(new ItemDetail($payload), 'json'), Response::HTTP_CREATED);
    }

    #[Route('/campaigns/{id}/attachments/{attachmentId}', name: 'campaign_attachment_delete', methods: ['DELETE'], requirements: ['id' => '\d+', 'attachmentId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function attachmentDelete(
        int $id,
        int $attachmentId,
        CampaignRepository $campaignRepository,
        \App\Repository\CampaignAttachmentRepository $attachmentRepository,
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        \Oi\FileBundle\Service\FileHandlerInterface $fileHandler,
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

        $attachment = $attachmentRepository->find($attachmentId);
        if ($attachment === null || $attachment->getCampaign()?->getId() !== $campaign->getId()) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $upload = $attachment->getUpload();
        $em->remove($attachment);
        $em->flush();
        if ($upload !== null) {
            try {
                $fileHandler->deleteFile($upload);
            } catch (\Throwable) {
                // best-effort: row is gone even if the physical file can't be removed
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/campaigns/{id}/schedule', name: 'campaign_schedule', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function schedule(
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

        if (!in_array($campaign->getStatus(), ['draft', 'scheduled'], true) || $campaign->getSentAt() !== null) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.not_schedulable'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        $payload = $request->toArray();
        $scheduledAtRaw = (string) ($payload['scheduled_at'] ?? '');

        // Allow unscheduling: scheduled_at = null/empty → back to draft
        if ($scheduledAtRaw === '') {
            $campaign->setScheduledAt(null);
            $campaign->setStatus('draft');
            $em->flush();
            return new Response($serializer->serialize(new ItemDetail($campaign), 'json', ['groups' => ['campaign:read']]));
        }

        try {
            $scheduledAt = new \DateTime($scheduledAtRaw);
        } catch (\Exception) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.invalid_date'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($scheduledAt < new \DateTime()) {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.scheduled_past'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $campaign->setScheduledAt($scheduledAt);
        $campaign->setStatus('scheduled');
        $em->flush();

        return new Response($serializer->serialize(new ItemDetail($campaign), 'json', ['groups' => ['campaign:read']]));
    }

    #[Route('/campaigns/{id}/sending-status', name: 'campaign_sending_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function sendingStatus(
        int $id,
        CampaignRepository $campaignRepository,
        CampaignEmailRepository $campaignEmailRepository,
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

        $counts = $campaignEmailRepository->countsByStatus($campaign);
        $total = $campaignEmailRepository->countByCampaign($campaign);

        $pending = $counts['pending'] ?? 0;
        $sending = $counts['sending'] ?? 0;
        $sent = $counts['sent'] ?? 0;
        $failed = $counts['failed'] ?? 0;
        $bounced = $counts['bounced'] ?? 0;

        $percentComplete = ($total > 0 && ($pending + $sending) === 0) ? 100 : (
            $total > 0 ? (int) round(($sent + $failed + $bounced) / $total * 100) : 0
        );

        $detail = new ItemDetail([
            'total' => $total,
            'pending' => $pending,
            'sending' => $sending,
            'sent' => $sent,
            'failed' => $failed,
            'bounced' => $bounced,
            'percent_complete' => $percentComplete,
        ]);

        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_OK);
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

        $sourceName = $source->getName();
        $copyName = $sourceName !== null && $sourceName !== ''
            ? 'Copia di ' . $sourceName
            : 'Copia senza titolo';

        $copy = new Campaign();
        $copy->setAccount($account);
        $copy->setName($copyName);
        $copy->setEmailSubject($source->getEmailSubject());
        $copy->setSnippet($source->getSnippet());
        $copy->setBody($source->getBody());
        $copy->setStructure($source->getStructure());
        $copy->setComposition($source->getComposition());
        $copy->setFilter($source->getFilter());
        $copy->setDraft(true);
        $copy->setTemplate(false);
        $copy->setStatus('draft');
        $copy->setClonedFrom($source);

        $em = $doctrine->getManager();
        foreach ($source->getMailLists() as $mailList) {
            $copy->getMailLists()->add($mailList);
        }

        $em->persist($copy);
        $em->flush();

        $detail = new ItemDetail($copy, $translator->trans('campaign.success.duplicated'));
        return new Response($serializer->serialize($detail, 'json', ['groups' => ['campaign:read']]), Response::HTTP_CREATED);
    }

    #[Route('/campaigns/{id}/save-as-template', name: 'campaign_save_as_template', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveAsTemplate(
        int $id,
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $source = $this->findCampaignForUser($id, $doctrine, $translator, $serializer);
        if ($source instanceof Response) {
            return $source;
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            $detail = new ItemDetail(null, $translator->trans('campaign.error.name_required'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        $template = new Campaign();
        $template->setAccount($account);
        $template->setName($name);
        $template->setEmailSubject($source->getEmailSubject());
        $template->setBody($source->getBody());
        $template->setStructure($source->getStructure());
        $template->setComposition($source->getComposition());
        $template->setFilter($source->getFilter());
        $template->setTemplate(true);
        $template->setDraft(false);
        $template->setStatus('draft');
        $template->setClonedFrom($source);

        $description = trim((string) ($data['description'] ?? ''));
        $template->setSnippet($description !== '' ? $description : $source->getSnippet());

        $em = $doctrine->getManager();
        foreach ($source->getMailLists() as $mailList) {
            $template->getMailLists()->add($mailList);
        }

        $em->persist($template);
        $em->flush();

        $detail = new ItemDetail($template, $translator->trans('campaign.success.saved_as_template'));
        return new Response($serializer->serialize($detail, 'json', ['groups' => ['campaign:read']]), Response::HTTP_CREATED);
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
