<?php

namespace App\Controller;

use App\Entity\CampaignEmail;
use App\Entity\OpenStat;
use App\Entity\UnsubscribeRequest;
use App\Repository\LinkStatRepository;
use App\Repository\OpenStatRepository;
use App\Repository\UnsubscribeRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;

class TrackingController extends AbstractController
{
    // 1x1 transparent PNG
    private const PIXEL_PNG = "\x89\x50\x4e\x47\x0d\x0a\x1a\x0a\x00\x00\x00\x0d\x49\x48\x44\x52\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\x0a\x49\x44\x41\x54\x78\x9c\x62\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00\x49\x45\x4e\x44\xae\x42\x60\x82";

    public function __construct(
        #[Autowire('%app_url%')] private readonly string $appUrl,
    ) {}

    #[Route('/t/open/{uuid}', name: 'tracking_open', methods: ['GET'])]
    public function open(
        string $uuid,
        Request $request,
        EntityManagerInterface $em,
        OpenStatRepository $openStatRepository,
        RateLimiterFactory $trackingOpenLimiter,
        LoggerInterface $logger,
    ): Response {
        $pixelResponse = new Response(
            self::PIXEL_PNG,
            Response::HTTP_OK,
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]
        );

        $limit = $trackingOpenLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: tracking_open', ['ip' => $request->getClientIp()]);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $pixelResponse->headers->set('Retry-After', (string) $retryAfter);
            return $pixelResponse;
        }

        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['trackingOpenId' => $uuid]);

        if ($campaignEmail !== null) {
            $openStat = $openStatRepository->findOneByCampaignEmail($campaignEmail);
            if ($openStat === null) {
                $openStat = new OpenStat(new \DateTimeImmutable());
                $openStat->setCampaignEmail($campaignEmail);
                $em->persist($openStat);
            } else {
                $openStat->incrementCount();
            }
            $em->flush();
        }

        return $pixelResponse;
    }

    #[Route('/t/click/{token}', name: 'tracking_click', methods: ['GET'])]
    public function click(
        string $token,
        Request $request,
        EntityManagerInterface $em,
        LinkStatRepository $linkStatRepository,
        RateLimiterFactory $trackingClickLimiter,
        LoggerInterface $logger,
    ): Response {
        $linkStat = $linkStatRepository->findOneByToken($token);

        if ($linkStat === null) {
            $logger->info('Click tracking: token not found', ['token' => $token]);
            return new RedirectResponse($this->appUrl . '/', Response::HTTP_FOUND);
        }

        $limit = $trackingClickLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: tracking_click', ['ip' => $request->getClientIp()]);
            $response = new RedirectResponse($linkStat->getUrl(), Response::HTTP_FOUND);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $response->headers->set('Retry-After', (string) $retryAfter);
            return $response;
        }

        $linkStat->incrementCount();
        $em->flush();

        return new RedirectResponse($linkStat->getUrl(), Response::HTTP_FOUND);
    }

    #[Route('/unsubscribe/{uuid}', name: 'tracking_unsubscribe', methods: ['GET'])]
    public function unsubscribe(
        string $uuid,
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $unsubscribeLimiter,
        LoggerInterface $logger,
    ): Response {
        $limit = $unsubscribeLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: unsubscribe', ['ip' => $request->getClientIp()]);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $response = $this->render('unsubscribe/invalid.html.twig', [], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            $response->headers->set('Retry-After', (string) $retryAfter);
            return $response;
        }

        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['unsubscribeToken' => $uuid]);

        if ($campaignEmail === null) {
            return $this->render('unsubscribe/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $mailList = $campaignEmail->getMailList();

        return $this->render('unsubscribe/confirm.html.twig', [
            'uuid' => $uuid,
            'listName' => $mailList?->getName() ?? 'questa lista',
            'unsubscribeText' => $mailList?->getUnsubscribeText(),
        ]);
    }

    #[Route('/unsubscribe/{uuid}', name: 'tracking_unsubscribe_post', methods: ['POST'])]
    public function unsubscribePost(
        string $uuid,
        Request $request,
        EntityManagerInterface $em,
        UnsubscribeRequestRepository $unsubscribeRepository,
        RateLimiterFactory $unsubscribeLimiter,
        LoggerInterface $logger,
    ): Response {
        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['unsubscribeToken' => $uuid]);

        if ($campaignEmail === null) {
            return $this->render('unsubscribe/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $limit = $unsubscribeLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: unsubscribe_post', ['ip' => $request->getClientIp()]);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $listName = $campaignEmail->getMailList()?->getName() ?? 'questa lista';
            $response = $this->render('unsubscribe/confirm.html.twig', [
                'uuid' => $uuid,
                'listName' => $listName,
                'unsubscribeText' => $campaignEmail->getMailList()?->getUnsubscribeText(),
            ]);
            $response->headers->set('Retry-After', (string) $retryAfter);
            return $response;
        }

        $existing = $unsubscribeRepository->findOneByCampaignEmail($campaignEmail);
        if ($existing !== null) {
            return $this->render('unsubscribe/already_done.html.twig');
        }

        $unsubscribeRequest = new UnsubscribeRequest(new \DateTimeImmutable());
        $unsubscribeRequest->setCampaignEmail($campaignEmail);
        $unsubscribeRequest->setIpAddress($request->getClientIp());
        $em->persist($unsubscribeRequest);

        $contact = $campaignEmail->getContact();
        if ($contact !== null) {
            $contact->unsubscribe();
        }

        $em->flush();

        $listName = $campaignEmail->getMailList()?->getName() ?? 'questa lista';

        return $this->render('unsubscribe/success.html.twig', [
            'uuid' => $uuid,
            'listName' => $listName,
        ]);
    }

    #[Route('/unsubscribe/{uuid}/resubscribe', name: 'tracking_resubscribe', methods: ['POST'])]
    public function resubscribe(
        string $uuid,
        Request $request,
        EntityManagerInterface $em,
        RateLimiterFactory $unsubscribeLimiter,
        LoggerInterface $logger,
    ): Response {
        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['unsubscribeToken' => $uuid]);

        if ($campaignEmail === null) {
            return $this->render('unsubscribe/invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $limit = $unsubscribeLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: resubscribe', ['ip' => $request->getClientIp()]);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $listName = $campaignEmail->getMailList()?->getName() ?? 'questa lista';
            $response = $this->render('unsubscribe/resubscribed.html.twig', ['listName' => $listName]);
            $response->headers->set('Retry-After', (string) $retryAfter);
            return $response;
        }

        $contact = $campaignEmail->getContact();
        if ($contact !== null) {
            $contact->resubscribe();
        }

        $em->flush();

        $listName = $campaignEmail->getMailList()?->getName() ?? 'questa lista';

        return $this->render('unsubscribe/resubscribed.html.twig', [
            'listName' => $listName,
        ]);
    }
}
