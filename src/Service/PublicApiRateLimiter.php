<?php

namespace App\Service;

use App\Entity\Account;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

class PublicApiRateLimiter
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public function consume(Account $account): RateLimit
    {
        $storage = new CacheStorage($this->cache);
        $limiter = new SlidingWindowLimiter(
            'api:' . $account->getId(),
            $account->getApiRateLimit(),
            new \DateInterval('PT60S'),
            $storage,
        );

        return $limiter->consume(1);
    }
}
