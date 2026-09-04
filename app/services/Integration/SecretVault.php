<?php

declare(strict_types=1);

final class SecretVault
{
    private function __construct(private string $key)
    {
        if (strlen($this->key) !== 32) {
            throw new RuntimeException('A chave-mestra de integrações deve possuir 32 bytes.');
        }
    }

    public static function fromRawKey(string $key): self { return new self($key); }

    public static function fromFile(string $path, bool $create = true): self
    {
        if (!is_file($path)) {
            if (!$create) throw new RuntimeException('Chave-mestra de integrações não encontrada.');
            if (file_put_contents($path, base64_encode(random_bytes(32)), LOCK_EX) === false) {
                throw new RuntimeException('Não foi possível criar a chave-mestra de integrações.');
            }
            @chmod($path, 0600);
        }
        $contents = file_get_contents($path);
        $key = $contents === false ? false : base64_decode(trim($contents), true);
        if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('Chave-mestra de integrações inválida.');
        return new self($key);
    }

    public function encrypt(string $plaintext): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
        }
        if (!function_exists('openssl_encrypt')) throw new RuntimeException('Criptografia autenticada indisponível no servidor.');
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) throw new RuntimeException('Não foi possível cifrar a credencial.');
        return 'g1:' . base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $separator = strpos($payload, ':');
        if ($separator === false) throw new RuntimeException('Credencial cifrada inválida.');
        $version = substr($payload, 0, $separator);
        $decoded = base64_decode(substr($payload, $separator + 1), true);
        if (!is_string($decoded)) throw new RuntimeException('Credencial cifrada inválida.');

        if ($version === 's1' && function_exists('sodium_crypto_secretbox_open')) {
            $size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            if (strlen($decoded) <= $size) throw new RuntimeException('Credencial cifrada inválida.');
            $plaintext = sodium_crypto_secretbox_open(substr($decoded, $size), substr($decoded, 0, $size), $this->key);
            if (!is_string($plaintext)) throw new RuntimeException('Credencial cifrada adulterada ou incompatível.');
            return $plaintext;
        }
        if ($version === 'g1' && function_exists('openssl_decrypt')) {
            if (strlen($decoded) <= 28) throw new RuntimeException('Credencial cifrada inválida.');
            $plaintext = openssl_decrypt(substr($decoded, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($decoded, 0, 12), substr($decoded, 12, 16));
            if (!is_string($plaintext)) throw new RuntimeException('Credencial cifrada adulterada ou incompatível.');
            return $plaintext;
        }
        throw new RuntimeException('Formato de credencial cifrada não suportado.');
    }
}
