<?php

namespace App\Service;

use App\Entity\Account;

class SmtpDiagnosticService
{
    private const SMTP_TIMEOUT = 10;
    private const DKIM_SELECTORS = ['default', 'mail', 'google', 'selector1', 'selector2', 'k1', 'smtp', 'dkim'];

    public function diagnose(Account $account): array
    {
        return [
            'smtp' => $this->testSmtpConnection($account),
            'dns' => $this->checkDns($account),
        ];
    }

    private function testSmtpConnection(Account $account): array
    {
        $host = $account->getSmtpHost();
        $port = $account->getSmtpPort() ?? 587;

        if ($host === null) {
            return [
                'connected' => false,
                'host' => null,
                'port' => $port,
                'error' => 'smtp_host_not_configured',
            ];
        }

        $errno = 0;
        $errstr = '';
        $connection = @fsockopen($host, $port, $errno, $errstr, self::SMTP_TIMEOUT);

        if ($connection === false) {
            return [
                'connected' => false,
                'host' => $host,
                'port' => $port,
                'error' => $errstr ?: 'connection_failed',
            ];
        }

        fclose($connection);

        return [
            'connected' => true,
            'host' => $host,
            'port' => $port,
            'error' => null,
        ];
    }

    private function checkDns(Account $account): array
    {
        $domain = $this->extractDomain($account);

        if ($domain === null) {
            return [
                'domain' => null,
                'spf' => ['found' => false, 'record' => null],
                'dkim' => ['found' => false, 'selector' => null, 'record' => null],
                'dmarc' => ['found' => false, 'record' => null],
            ];
        }

        return [
            'domain' => $domain,
            'spf' => $this->checkSpf($domain),
            'dkim' => $this->checkDkim($domain),
            'dmarc' => $this->checkDmarc($domain),
        ];
    }

    private function extractDomain(Account $account): ?string
    {
        $smtpUser = $account->getSmtpUser();
        if ($smtpUser !== null && str_contains($smtpUser, '@')) {
            return substr($smtpUser, strpos($smtpUser, '@') + 1);
        }

        return $account->getSmtpHost();
    }

    private function checkSpf(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_TXT);
        if ($records === false) {
            return ['found' => false, 'record' => null];
        }

        foreach ($records as $record) {
            if (isset($record['txt']) && str_starts_with($record['txt'], 'v=spf1')) {
                return ['found' => true, 'record' => $record['txt']];
            }
        }

        return ['found' => false, 'record' => null];
    }

    private function checkDkim(string $domain): array
    {
        foreach (self::DKIM_SELECTORS as $selector) {
            $dkimDomain = $selector . '._domainkey.' . $domain;
            $records = @dns_get_record($dkimDomain, DNS_TXT);

            if ($records !== false) {
                foreach ($records as $record) {
                    if (isset($record['txt']) && str_contains($record['txt'], 'v=DKIM1')) {
                        return [
                            'found' => true,
                            'selector' => $selector,
                            'record' => $record['txt'],
                        ];
                    }
                }
            }
        }

        return ['found' => false, 'selector' => null, 'record' => null];
    }

    private function checkDmarc(string $domain): array
    {
        $dmarcDomain = '_dmarc.' . $domain;
        $records = @dns_get_record($dmarcDomain, DNS_TXT);

        if ($records === false) {
            return ['found' => false, 'record' => null];
        }

        foreach ($records as $record) {
            if (isset($record['txt']) && str_starts_with($record['txt'], 'v=DMARC1')) {
                return ['found' => true, 'record' => $record['txt']];
            }
        }

        return ['found' => false, 'record' => null];
    }
}
