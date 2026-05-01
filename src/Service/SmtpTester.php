<?php

namespace App\Service;

class SmtpTester
{
    private const TIMEOUT = 10;

    public function testFromDsn(string $dsn): array
    {
        $parsed = parse_url($dsn);

        if ($parsed === false || empty($parsed['host'])) {
            return ['success' => false, 'error' => 'Invalid DSN format'];
        }

        $host = $parsed['host'];
        $port = isset($parsed['port']) ? (int) $parsed['port'] : 587;

        return $this->testConnection($host, $port);
    }

    public function testFromFields(string $host, int $port = 587): array
    {
        return $this->testConnection($host, $port);
    }

    private function testConnection(string $host, int $port): array
    {
        $errno = 0;
        $errstr = '';

        $connection = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT);

        if ($connection === false) {
            return [
                'success' => false,
                'error' => $errstr ?: sprintf('Cannot connect to %s:%d', $host, $port),
            ];
        }

        stream_set_timeout($connection, self::TIMEOUT);

        $banner = fgets($connection, 1024);
        if ($banner === false || !str_starts_with(trim((string) $banner), '220')) {
            fclose($connection);
            return ['success' => false, 'error' => 'Unexpected server banner: ' . trim((string) $banner ?: 'no response')];
        }

        fwrite($connection, "EHLO mailflow.test\r\n");

        $response = '';
        while ($line = fgets($connection, 1024)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        fwrite($connection, "QUIT\r\n");
        fclose($connection);

        if (!str_starts_with($response, '250')) {
            return ['success' => false, 'error' => 'EHLO failed: ' . trim($response)];
        }

        return ['success' => true, 'error' => null];
    }
}
