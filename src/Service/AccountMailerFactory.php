<?php

namespace App\Service;

use App\Entity\Account;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

class AccountMailerFactory
{
    public function __construct(
        private readonly SmtpPasswordEncryptor $encryptor,
    ) {}

    /**
     * Creates a Mailer using the Account's effective DSN, or an explicit DSN override.
     *
     * @throws \RuntimeException if no DSN is configured on the account
     */
    public function createMailer(Account $account, ?string $dsnOverride = null): MailerInterface
    {
        $dsn = $dsnOverride ?? $this->resolveAccountDsn($account);

        if ($dsn === null) {
            throw new \RuntimeException(
                sprintf('No mailer DSN configured for account "%s" (id: %d).', $account->getRagioneSociale() ?? '', (int) $account->getId())
            );
        }

        $transport = Transport::fromDsn($dsn);
        return new Mailer($transport);
    }

    private function resolveAccountDsn(Account $account): ?string
    {
        if ($account->getMailerDsn() !== null) {
            return $account->getMailerDsn();
        }

        if ($account->getSmtpHost() === null || $account->getSmtpUser() === null) {
            return null;
        }

        $rawPassword = $account->getSmtpPassword();
        $plainPassword = $rawPassword !== null ? $this->encryptor->decrypt($rawPassword) : '';
        $port = $account->getSmtpPort() ?? 587;
        $encryption = $account->getSmtpEncryption() ?? 'tls';

        return sprintf(
            'smtp://%s:%s@%s:%d?encryption=%s',
            urlencode($account->getSmtpUser()),
            urlencode($plainPassword),
            $account->getSmtpHost(),
            $port,
            $encryption,
        );
    }
}
