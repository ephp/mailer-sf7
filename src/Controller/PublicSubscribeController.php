<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\ContactTaxonomy;
use App\Entity\TaxonomyTerm;
use App\Repository\ContactRepository;
use App\Repository\MailListRepository;
use App\Repository\TaxonomyCategoryRepository;
use App\Repository\TaxonomyTermRepository;
use App\Service\PrivacyPolicyService;
use Doctrine\ORM\EntityManagerInterface;
use Oi\ApiBundle\Model\ItemDetail;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Prefisso /api/public/v1: la rotta vecchia /subscribe/{token} collideva con
// la pagina Next dello stesso path (form pubblico di iscrizione). Nginx
// instrada /api/* al backend, /subscribe/* a Next. Allineato a PublicApiController.
#[Route('/api/public/v1')]
class PublicSubscribeController extends AbstractController
{
    #[Route('/subscribe/{token}', name: 'public_subscribe_info', methods: ['GET'], requirements: ['token' => '[0-9a-fA-F-]{36}'])]
    public function info(
        string $token,
        MailListRepository $mailListRepository,
        TaxonomyCategoryRepository $categoryRepository,
        TaxonomyTermRepository $termRepository,
        PrivacyPolicyService $privacyPolicyService,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
    ): Response {
        $mailList = $mailListRepository->findOneBySubscribeToken($token);
        if ($mailList === null) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $categories = $categoryRepository->findByMailList($mailList);
        $categoriesPayload = [];
        foreach ($categories as $cat) {
            $terms = $termRepository->findByCategory($cat);
            $categoriesPayload[] = [
                'id' => $cat->getId(),
                'name' => $cat->getName(),
                'terms' => array_map(
                    fn(TaxonomyTerm $t) => ['id' => $t->getId(), 'name' => $t->getLabel()],
                    $terms,
                ),
            ];
        }

        $privacyPolicy = $mailList->getAccount() !== null
            ? $privacyPolicyService->render($mailList->getAccount())
            : '';

        $payload = [
            'list' => [
                'id' => $mailList->getId(),
                'name' => $mailList->getName(),
                'description' => $mailList->getDescription(),
            ],
            'categories' => $categoriesPayload,
            'privacy_policy' => $privacyPolicy,
        ];

        return new Response($serializer->serialize(new ItemDetail($payload), 'json'));
    }

    #[Route('/subscribe/{token}', name: 'public_subscribe_create', methods: ['POST'], requirements: ['token' => '[0-9a-fA-F-]{36}'])]
    public function subscribe(
        string $token,
        Request $request,
        MailListRepository $mailListRepository,
        ContactRepository $contactRepository,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        SerializerInterface $serializer,
        TranslatorInterface $translator,
        RateLimiterFactory $subscribeLimiter,
        LoggerInterface $logger,
    ): Response {
        $limit = $subscribeLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: subscribe', ['ip' => $request->getClientIp()]);
            $detail = new ItemDetail(null, $translator->trans('subscribe.error.rate_limit'), ItemDetail::MESSAGE_ERROR);
            $response = new Response($serializer->serialize($detail, 'json'), Response::HTTP_TOO_MANY_REQUESTS);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $response->headers->set('Retry-After', (string) $retryAfter);
            return $response;
        }

        $mailList = $mailListRepository->findOneBySubscribeToken($token);
        if ($mailList === null) {
            $detail = new ItemDetail(null, $translator->trans('maillist.error.not_found'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_NOT_FOUND);
        }

        $payload = $request->toArray();
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        $nome = is_string($payload['nome'] ?? null) ? trim($payload['nome']) : null;
        $cognome = is_string($payload['cognome'] ?? null) ? trim($payload['cognome']) : null;
        $telefono = is_string($payload['telefono'] ?? null) ? trim($payload['telefono']) : null;
        $privacyAccepted = (bool) ($payload['privacy_accepted'] ?? false);
        /** @var int[] $termIds */
        $termIds = array_values(array_unique(array_map('intval', (array) ($payload['term_ids'] ?? []))));

        if (!$privacyAccepted) {
            $detail = new ItemDetail(null, $translator->trans('subscribe.error.privacy_required'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $violations = $validator->validate($email, [
            new Assert\NotBlank(),
            new Assert\Email(),
            new Assert\Length(max: 255),
        ]);
        if (count($violations) > 0) {
            $detail = new ItemDetail(null, $translator->trans('subscribe.error.invalid_email'), ItemDetail::MESSAGE_ERROR);
            return new Response($serializer->serialize($detail, 'json'), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $contactRepository->findByEmailAndMailList($email, $mailList);
        if ($existing !== null) {
            // Reactivate if previously unsubscribed and update name/phone with
            // the latest values supplied via the public form.
            if (!$existing->isIscritto()) {
                $existing->resubscribe();
            }
            if ($nome !== null && $nome !== '') $existing->setNome($nome);
            if ($cognome !== null && $cognome !== '') $existing->setCognome($cognome);
            if ($telefono !== null && $telefono !== '') $existing->setTelefono($telefono);
            $existing->setPrivacyAcceptedAt(new \DateTime());
            $contact = $existing;
        } else {
            $contact = new Contact();
            $contact->setEmail($email);
            $contact->setNome($nome);
            $contact->setCognome($cognome);
            $contact->setTelefono($telefono);
            $contact->setMailList($mailList);
            $contact->setPrivacyAcceptedAt(new \DateTime());
            $em->persist($contact);
        }

        $em->flush();

        // Apply taxonomy terms, but only those that belong to a category of this list.
        if ($termIds !== []) {
            foreach ($termIds as $termId) {
                $term = $em->find(TaxonomyTerm::class, $termId);
                if ($term === null || $term->getCategory()?->getMailListId() !== $mailList->getId()) {
                    continue;
                }
                // Skip if already linked
                $already = $em->getRepository(ContactTaxonomy::class)
                    ->findOneBy(['contact' => $contact, 'term' => $term]);
                if ($already !== null) {
                    continue;
                }
                $ct = new ContactTaxonomy();
                $ct->setContact($contact);
                $ct->setTerm($term);
                $em->persist($ct);
            }
            $em->flush();
        }

        $detail = new ItemDetail(
            ['contact_id' => $contact->getId(), 'email' => $contact->getEmail()],
            $translator->trans('subscribe.success'),
        );
        return new Response($serializer->serialize($detail, 'json'), Response::HTTP_CREATED);
    }
}
