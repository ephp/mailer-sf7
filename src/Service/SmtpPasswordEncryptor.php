<?php

namespace App\Service;

class SmtpPasswordEncryptor
{
    private string $key;

    public function __construct(string $secret)
    {
        $this->key = substr(hash('sha256', $secret), 0, 32);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);

        return 'enc:' . base64_encode($iv . $encrypted);
    }

    public function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'enc:')) {
            return $value;
        }

        $data = base64_decode(substr($value, 4));
        if ($data === false || strlen($data) <= 16) {
            return $value;
        }

        $iv = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $result = openssl_decrypt($cipher, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv);

        return $result !== false ? $result : $value;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, 'enc:');
    }
}
