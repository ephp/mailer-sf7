<?php

namespace App\Controller;

use App\Entity\CampaignEmail;
use App\Entity\LinkClickEvent;
use App\Entity\OpenStat;
use App\Entity\UnsubscribeRequest;
use App\Repository\LinkStatRepository;
use App\Repository\OpenStatRepository;
use App\Repository\UnsubscribeRequestRepository;
use App\Service\EmailTemplateRenderer;
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
    public function __construct(
        #[Autowire('%app_url%')] private readonly string $appUrl,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    // 1x1 PNG trasparente — fallback inline quando public/img/email.png manca
    // sul filesystem di deploy. Garantisce che l'endpoint torni sempre 200 con
    // un'immagine valida invece di scoppià' con un'eccezione FileNotFound.
    private const TRANSPARENT_PIXEL_PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a\x2d\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    #[Route('/t/open/{uuid}', name: 'tracking_open', methods: ['GET'])]
    public function open(
        string $uuid,
        Request $request,
        EntityManagerInterface $em,
        OpenStatRepository $openStatRepository,
        RateLimiterFactory $trackingOpenLimiter,
        LoggerInterface $logger,
    ): Response {
        $buildPixelResponse = function (): Response {
            $path = $this->projectDir . '/public/img/email.png';
            $body = is_file($path) && is_readable($path)
                ? (string) file_get_contents($path)
                : self::TRANSPARENT_PIXEL_PNG;

            return new Response($body, Response::HTTP_OK, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);
        };

        $limit = $trackingOpenLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: tracking_open', ['ip' => $request->getClientIp()]);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $response = $buildPixelResponse();
            $response->headers->set('Retry-After', (string) $retryAfter);
            return $response;
        }

        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['trackingOpenId' => $uuid]);

        if ($campaignEmail !== null) {
            $openStat = $openStatRepository->findOneByCampaignEmail($campaignEmail);
            if ($openStat === null) {
                $openStat = new OpenStat(new \DateTime());
                $openStat->setCampaignEmail($campaignEmail);
                $em->persist($openStat);
            } else {
                $openStat->incrementCount();
            }
            $em->flush();
        }

        return $buildPixelResponse();
    }

    #[Route('/v/{uuid}', name: 'tracking_web_view', methods: ['GET'])]
    public function webView(
        string $uuid,
        EntityManagerInterface $em,
        EmailTemplateRenderer $renderer,
    ): Response {
        $campaignEmail = $em->getRepository(CampaignEmail::class)->findOneBy(['trackingOpenId' => $uuid]);

        if ($campaignEmail === null) {
            return $this->render('email/web_view_not_found.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $html = $campaignEmail->getHtml();

        // Le mail spedite prima della migration 20260509120001 (colonna `html`)
        // hanno html=NULL. Ri-renderizziamo on-the-fly e ricacciamo in DB così
        // dalla seconda apertura serviamo dalla copia archiviata.
        if ($html === null || $html === '') {
            $campaign = $campaignEmail->getCampaign();
            if ($campaign === null) {
                return $this->render('email/web_view_not_found.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
            }

            $mailList = $campaignEmail->getMailList();
            $unsubUrl = $mailList?->isPermettiDisiscrizione() !== false
                ? $this->appUrl . '/unsubscribe/' . $campaignEmail->getUnsubscribeToken()
                : null;
            $webViewUrl = $this->appUrl . '/v/' . $campaignEmail->getTrackingOpenId();
            $openTrackingUrl = $this->appUrl . '/t/open/' . $campaignEmail->getTrackingOpenId();

            $html = $renderer->render(
                $campaign,
                $campaignEmail->getContact(),
                $unsubUrl,
                $webViewUrl,
                $openTrackingUrl,
            )->html;

            $campaignEmail->setHtml($html);
            $em->flush();
        }

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
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

        $userAgent = $request->headers->get('User-Agent', '');
        $ipAddress = $request->getClientIp();

        $event = new LinkClickEvent();
        $event->setLinkStat($linkStat);
        $event->setIpAddress($ipAddress);
        $event->setUserAgent($userAgent !== '' ? $userAgent : null);

        $limit = $trackingClickLimiter->create($ipAddress ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $logger->info('Rate limit hit: tracking_click', ['ip' => $ipAddress]);
            $event->setCounted(false);
            $event->setSkipReason('rate_limit');
            $em->persist($event);
            $em->flush();
            $response = new RedirectResponse($linkStat->getUrl(), Response::HTTP_FOUND);
            $retryAfter = max(0, $limit->getRetryAfter()->getTimestamp() - time());
            $response->headers->set('Retry-After', (string) $retryAfter);
            return $response;
        }

        // Skip counting clicks from known prefetchers / link scanners (Gmail
        // image proxy, Outlook Safe Links, Mimecast, Proofpoint, etc.). They
        // hit our redirect to scan the destination, not because a human
        // clicked. Still serve the redirect, so a real human request behind
        // the same UA filter is not broken.
        $scannerReason = $this->matchScannerPattern($userAgent);
        if ($scannerReason !== null) {
            $logger->info('Click tracking: scanner UA, not counting', ['ua' => $userAgent, 'reason' => $scannerReason]);
            $event->setCounted(false);
            $event->setSkipReason($scannerReason);
            $em->persist($event);
            $em->flush();
            return new RedirectResponse($linkStat->getUrl(), Response::HTTP_FOUND);
        }

        $linkStat->incrementCount();
        $em->persist($event);
        $em->flush();

        return new RedirectResponse($linkStat->getUrl(), Response::HTTP_FOUND);
    }

    /**
     * Returns the pattern name matched (truncated to fit skip_reason column)
     * if the UA looks like a prefetcher/scanner, or null for a real client.
     */
    private function matchScannerPattern(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return 'empty_ua';
        }
        $patterns = [
            'bot', 'crawler', 'spider', 'scanner', 'monitor',
            'GoogleImageProxy', 'YahooMailProxy',
            'Mimecast', 'Proofpoint', 'Barracuda', 'IronPort',
            'OutlookSafeLink', 'safelinks', 'urldefense',
            'BingPreview', 'Slackbot', 'facebookexternalhit',
            'Twitterbot', 'LinkedInBot', 'WhatsApp', 'TelegramBot',
            'curl', 'wget', 'python-requests',
            'PhantomJS', 'HeadlessChrome',
        ];
        foreach ($patterns as $needle) {
            if (stripos($userAgent, $needle) !== false) {
                return substr('ua:' . $needle, 0, 64);
            }
        }
        return null;
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

        $unsubscribeRequest = new UnsubscribeRequest(new \DateTime());
        $unsubscribeRequest->setCampaignEmail($campaignEmail);
        $unsubscribeRequest->setIpAddress($request->getClientIp());
        $em->persist($unsubscribeRequest);

        $contact = $campaignEmail->getContact();
        if ($contact !== null) {
            $contact->unsubscribe('user_request');
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
