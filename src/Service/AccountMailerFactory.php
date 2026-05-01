<?php

namespace App\Service;

use App\Entity\Account;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

class AccountMailerFactory
{
    /**
     * Creates a Mailer using the Account's effective DSN, or an explicit DSN override.
     *
     * @throws \RuntimeException if no DSN is configured on the account
     */
    public function createMailer(Account $account, ?string $dsnOverride = null): MailerInterface
    {
        $dsn = $dsnOverride ?? $account->getEffectiveDsn();

        if ($dsn === null) {
            throw new \RuntimeException(
                sprintf('No mailer DSN configured for account "%s" (id: %d).', $account->getRagioneSociale() ?? '', (int) $account->getId())
            );
        }

        $transport = Transport::fromDsn($dsn);
        return new Mailer($transport);
    }
}
