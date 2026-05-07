<?php

namespace App\Controller\Admin;

use App\Entity\Account;
use App\Form\AccountType;
use App\Repository\AccountRepository;
use App\Service\AccountMailerFactory;
use App\Service\SmtpDiagnosticService;
use App\Service\SmtpPasswordEncryptor;
use App\Service\SmtpTester;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Oi\ApiBundle\Model\ItemDetail;
use Oi\ApiBundle\Model\PaginatedList;
use Oi\ApiBundle\Service\Form\Interfaces\FormErrorMessageHandlerInterface;
use Oi\FileBundle\Service\FileHandlerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1')]
class AccountController extends AbstractController
{
    public function __construct(
        private readonly FormErrorMessageHandlerInterface $formErrorMessageHandler,
    ) {}

    #[Route('/account', name: 'account_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getAccount(
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

        return new Response(
            $serializer->serialize(new ItemDetail($account), 'json', ['groups' => ['account:read']]),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }

    #[Route('/account', name: 'account_put', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function updateAccount(
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        SmtpPasswordEncryptor $encryptor,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $existingPassword = $account->getSmtpPassword();

        $form = $this->createForm(AccountType::class, $account);
        $raw = $request->request->all();
        if (empty($raw)) {
            $raw = json_decode($request->getContent(), true) ?? [];
        }
        $form->submit($raw[$form->getName()] ?? $raw);

        if ($form->isSubmitted() && $form->isValid()) {
            $newPassword = $account->getSmtpPassword();
            if (empty($newPassword)) {
                $account->setSmtpPassword($existingPassword);
            } else {
                $account->setSmtpPassword($encryptor->encrypt($newPassword));
            }

            $doctrine->getManager()->flush();

            return new Response(
                $serializer->serialize(new ItemDetail($account, $translator->trans('account.success.updated')), 'json', ['groups' => ['account:read']]),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json'],
            );
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
    }

    #[Route('/account/logo', name: 'account_logo_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadLogo(
        Request $request,
        ManagerRegistry $doctrine,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        FileHandlerInterface $fileHandler,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('logo');

        if ($file === null) {
            $detail = new ItemDetail(null, 'No file provided', ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $upload = $fileHandler->makeUploadFromUploadedFile($file, $request);
            $oldLogo = $account->getLogo();
            $account->setLogo($upload);
            $doctrine->getManager()->flush();

            if ($oldLogo !== null) {
                $fileHandler->deleteFile($oldLogo);
            }

            return new Response(
                $serializer->serialize(new ItemDetail($account, $translator->trans('account.success.logo_uploaded')), 'json', ['groups' => ['account:read']]),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json'],
            );
        } catch (\Exception $e) {
            $detail = new ItemDetail(null, $e->getMessage(), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    #[Route('/account/test-smtp', name: 'account_test_smtp', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function testSmtp(
        Request $request,
        SmtpTester $smtpTester,
        SerializerInterface $serializer,
    ): Response {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!empty($data['dsn'])) {
            $result = $smtpTester->testFromDsn((string) $data['dsn']);
        } elseif (!empty($data['smtpHost'])) {
            $port = isset($data['smtpPort']) ? (int) $data['smtpPort'] : 587;
            $result = $smtpTester->testFromFields((string) $data['smtpHost'], $port);
        } else {
            $result = ['success' => false, 'error' => 'No DSN or SMTP host provided'];
        }

        return new Response(
            $serializer->serialize($result, 'json'),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }

    #[Route('/account/send-test-email', name: 'account_send_test_email', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendTestEmail(
        Request $request,
        AccountMailerFactory $mailerFactory,
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
        $to = trim((string) ($data['to'] ?? ''));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $detail = new ItemDetail(null, 'Indirizzo email destinatario non valido.', ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        try {
            $mailer = $mailerFactory->createMailer($account);
            $fromAddress = $account->getMailFrom();
            $fromName = $account->getMailFromName();
            $now = (new \DateTimeImmutable())->format('d/m/Y H:i:s');

            $email = (new Email())
                ->from(new Address($fromAddress, $fromName))
                ->to($to)
                ->subject('MailFlow - Email di Test')
                ->text(sprintf("Questa è un'email di test inviata da MailFlow il %s.\n\nSe hai ricevuto questo messaggio, la configurazione SMTP è corretta.", $now));

            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $detail = new ItemDetail(null, $e->getMessage(), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            $detail = new ItemDetail(null, $e->getMessage(), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_BAD_REQUEST);
        }

        $detail = new ItemDetail(null, sprintf('Email di test inviata a %s.', $to));
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_OK, ['Content-Type' => 'application/json']);
    }

    #[Route('/account/my-account', name: 'account_my_account', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function myAccount(
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return new Response($serializer->serialize(new ItemDetail($account), 'json'));
    }

    #[Route('/account/my-account/edit', name: 'account_my_account_edit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function editMyAccount(
        Request              $request,
        ManagerRegistry      $doctrine,
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(AccountType::class, $account);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $doctrine->getManager()->flush();
            $detail = new ItemDetail($account, $translator->trans('account.success.updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            $account,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/account/my-account/diagnose', name: 'account_my_account_diagnose', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function diagnose(
        SmtpDiagnosticService $diagnosticService,
        SerializerInterface   $serializer,
        TranslatorInterface   $translator,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $account = $user->getAccount();

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $result = $diagnosticService->diagnose($account);
        $detail = new ItemDetail($result);
        return new Response($serializer->serialize($detail, 'json'));
    }

    #[Route('/account/my-account/regenerate-key', name: 'account_my_account_regenerate_key', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function regenerateApiKey(
        ManagerRegistry     $doctrine,
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

        $account->regenerateApiKey();
        $doctrine->getManager()->flush();

        $detail = new ItemDetail($account, $translator->trans('account.success.api_key_regenerated'));
        return new Response($serializer->serialize($detail, 'json'));
    }

    #[Route('/backend/account', name: 'admin_account_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        Request              $request,
        AccountRepository    $accountRepository,
        PaginatorInterface   $paginator,
        SerializerInterface  $serializer,
    ): Response {
        $qb = $accountRepository->createQueryBuilder('a')
            ->orderBy('a.ragioneSociale', 'ASC');

        $pagination = $paginator->paginate(
            $qb,
            $request->query->getInt('page', 1),
            $request->query->getInt('per_page', 20),
        );

        $list = new PaginatedList($pagination);

        return new Response($serializer->serialize($list, 'json'));
    }

    #[Route('/backend/account/{id}', name: 'admin_account_find', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function find(
        int                  $id,
        ManagerRegistry      $doctrine,
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        $account = $doctrine->getManager()->find(Account::class, $id);

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        return new Response($serializer->serialize(new ItemDetail($account), 'json'));
    }

    #[Route('/backend/account-new', name: 'admin_account_new', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(
        Request              $request,
        ManagerRegistry      $doctrine,
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        $account = new Account();
        $form = $this->createForm(AccountType::class, $account);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $doctrine->getManager();
            $em->persist($account);
            $em->flush();

            $detail = new ItemDetail($account, $translator->trans('account.success.created'));
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
        }

        $detail = new ItemDetail(
            null,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/backend/account/{id}/edit', name: 'admin_account_edit', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(
        int                  $id,
        Request              $request,
        ManagerRegistry      $doctrine,
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        $em = $doctrine->getManager();
        $account = $em->find(Account::class, $id);

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $form = $this->createForm(AccountType::class, $account);
        $form->submit($request->request->all()[$form->getName()] ?? []);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $detail = new ItemDetail($account, $translator->trans('account.success.updated'));
            return new Response($serializer->serialize($detail, 'json'));
        }

        $detail = new ItemDetail(
            $account,
            $this->formErrorMessageHandler->getErrorMessageFromForm($form),
            ItemDetail::MESSAGE_ERROR,
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/backend/account/{id}/enable', name: 'admin_account_enable', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function enable(
        int                  $id,
        ManagerRegistry      $doctrine,
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        $em = $doctrine->getManager();
        $account = $em->find(Account::class, $id);

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $account->setEnabled(true);
        $em->flush();

        return new Response($serializer->serialize(new ItemDetail($account, $translator->trans('account.success.enabled')), 'json'));
    }

    #[Route('/backend/account/{id}/disable', name: 'admin_account_disable', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function disable(
        int                  $id,
        ManagerRegistry      $doctrine,
        SerializerInterface  $serializer,
        TranslatorInterface  $translator,
    ): Response {
        $em = $doctrine->getManager();
        $account = $em->find(Account::class, $id);

        if ($account === null) {
            $detail = new ItemDetail(null, $translator->trans('account.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $account->setEnabled(false);
        $em->flush();

        return new Response($serializer->serialize(new ItemDetail($account, $translator->trans('account.success.disabled')), 'json'));
    }
}
