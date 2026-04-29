<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

class AccountMailerFactory
{
    public function __construct(
        #[Autowire(env: 'MAILER_DSN')]
        private readonly string $defaultDsn,
    ) {}

    /**
     * Creates a Mailer using the given DSN, or the default env DSN if null.
     */
    public function createMailer(?string $dsn = null): MailerInterface
    {
        $transport = Transport::fromDsn($dsn ?? $this->defaultDsn);
        return new Mailer($transport);
    }
}
