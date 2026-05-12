<?php

namespace App\EventSubscriber;

use App\Repository\AccountRepository;
use App\Service\PublicApiRateLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class PublicApiKeyAuthListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly PublicApiRateLimiter $rateLimiter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!preg_match('#^/api/public/v1/#', $request->getPathInfo())) {
            return;
        }

        $apiKey = $request->headers->get('X-API-Key');

        if ($apiKey === null || $apiKey === '') {
            $event->setResponse(new JsonResponse(
                ['error' => 'unauthorized', 'message' => 'Missing or invalid X-API-Key header'],
                Response::HTTP_UNAUTHORIZED,
            ));
            return;
        }

        $account = $this->accountRepository->findOneBy(['apiKey' => $apiKey]);

        if ($account === null) {
            $event->setResponse(new JsonResponse(
                ['error' => 'unauthorized', 'message' => 'Missing or invalid X-API-Key header'],
                Response::HTTP_UNAUTHORIZED,
            ));
            return;
        }

        if (!$account->isEnabled()) {
            $event->setResponse(new JsonResponse(
                ['error' => 'forbidden', 'message' => 'Account disabled'],
                Response::HTTP_FORBIDDEN,
            ));
            return;
        }

        $rateLimit = $this->rateLimiter->consume($account);

        if (!$rateLimit->isAccepted()) {
            $retryAfter = max(0, $rateLimit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new JsonResponse(
                ['error' => 'rate_limit', 'message' => 'API rate limit exceeded'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            ));
            return;
        }

        $request->attributes->set('mailflow_account', $account);
    }
}
