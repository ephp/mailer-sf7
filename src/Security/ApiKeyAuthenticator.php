<?php

namespace App\Security;

use App\Entity\Account;
use App\Repository\AccountRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

class ApiKeyAuthenticator
{
    private const RATE_LIMIT_PER_MINUTE = 60;

    public function __construct(
        private readonly AccountRepository $accountRepository,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public function getAccount(Request $request): ?Account
    {
        $apiKey = $request->headers->get('X-API-Key');
        if ($apiKey === null || $apiKey === '') {
            return null;
        }

        return $this->accountRepository->findByApiKey($apiKey);
    }

    /**
     * Returns true if the request is within the rate limit, false if the limit is exceeded.
     * Increments the counter on each allowed request.
     */
    public function checkRateLimit(Account $account): bool
    {
        $window = (int) floor(time() / 60);
        $cacheKey = sprintf('pub_api_rl_%d_%d', $account->getId(), $window);

        $item = $this->cache->getItem($cacheKey);
        $count = $item->isHit() ? (int) $item->get() : 0;

        if ($count >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter(120);
        $this->cache->save($item);

        return true;
    }
}
