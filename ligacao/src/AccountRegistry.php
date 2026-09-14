<?php
declare(strict_types=1);
namespace App;

/** Local encrypted account overrides. The primary .env is never modified. */
final class AccountRegistry
{
    public function __construct(private Config $base, private string $directory) {}

    public static function validateId(mixed $id): string
    {
        if (!is_string($id) || !in_array($id, ['1', '2'], true)) {
            throw new AppException('Conta inválida.', 'INVALID_ACCOUNT', 400);
        }
        return $id;
    }

    public function accounts(): array
    {
        $saved = $this->read();
        return array_map(function (string $id) use ($saved): array {
            $data = $this->values($id, $saved);
            return ['id' => $id, 'name' => $data['name'], 'color' => $data['color'],
                'configured' => trim($data['token']) !== '', 'extension' => $data['extension'],
                'enabled' => $data['enabled'], 'primary' => $data['primary'],
                'provider' => 'api4com', 'base_url' => $data['base_url']];
        }, ['1', '2']);
    }

    public function config(string $id, bool $allowDisabled = false): Config
    {
        self::validateId($id);
        $data = $this->values($id, $this->read());
        if (!$data['enabled'] && !$allowDisabled) {
            throw new AppException('Esta conta está inativa.', 'ACCOUNT_DISABLED', 409);
        }
        if (trim($data['token']) === '') {
            throw new AppException('Configure o token desta conta para continuar.', 'ACCOUNT_NOT_CONFIGURED', 503);
        }
        return $this->base->with(['API4COM_TOKEN' => $data['token'], 'API4COM_BASE_URL' => $data['base_url'],
            'WEBPHONE_EXTENSION' => $data['extension']]);
    }

    private function values(string $id, array $saved): array
    {
        return array_replace(['enabled' => true, 'primary' => $id === '1', 'name' => 'Conta ' . $id, 'color' => $id === '1' ? '#2563eb' : '#16a34a',
            'token' => $id === '1' ? $this->base->get('API4COM_TOKEN', '') : '',
            'base_url' => $id === '1' ? $this->base->get('API4COM_BASE_URL', 'https://api.api4com.com/api/v1') : 'https://api.api4com.com/api/v1',
            'extension' => $id === '1' ? $this->base->get('WEBPHONE_EXTENSION', '1000') : '1000'], $saved[$id] ?? []);
    }

    public function save(array $payload): array
    {
        $id = self::validateId($payload['account'] ?? null);
        $changes = [];
        foreach (['enabled', 'primary'] as $field) {
            if (!array_key_exists($field, $payload)) { continue; }
            if (!is_bool($payload[$field])) { $this->invalid(); }
            $changes[$field] = $payload[$field];
        }
        foreach (['name', 'color', 'token', 'base_url', 'extension'] as $field) {
            if (!array_key_exists($field, $payload)) { continue; }
            if (!is_string($payload[$field])) { $this->invalid(); }
            $value = trim($payload[$field]);
            if ($field === 'token' && $value === '') { continue; }
            if ($field === 'name' && ($value === '' || mb_strlen($value) > 60 || preg_match('/[\x00-\x1f\x7f]/', $value))) { $this->invalid(); }
            if ($field === 'color' && !preg_match('/^#[a-fA-F0-9]{6}$/', $value)) { $this->invalid(); }
            if ($field === 'extension' && !preg_match('/^[0-9]{1,10}$/', $value)) { $this->invalid(); }
            if ($field === 'token' && (strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value))) { $this->invalid(); }
            if ($field === 'base_url' && !preg_match('~^https://api\.api4com\.com/api/v1/?$~i', $value)) { $this->invalid(); }
            $changes[$field] = $value;
        }
        $lock = $this->lock(LOCK_EX);
        try {
            $saved = $this->decode();
            $saved[$id] = array_replace($saved[$id] ?? [], $changes);
            if (array_key_exists('primary', $changes)) {
                $other = $id === '1' ? '2' : '1';
                $saved[$other]['primary'] = !$changes['primary'];
            }
            $keyPath = $this->directory . '/accounts.key';
            if (!is_file($keyPath)) { $this->atomic($keyPath, random_bytes(32)); }
            $key = file_get_contents($keyPath);
            if (!is_string($key) || strlen($key) !== 32) { $this->storageFailure(); }
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt(json_encode($saved, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) { $this->storageFailure(); }
            $this->atomic($this->directory . '/accounts.enc', $iv . $tag . $cipher);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return $this->accounts();
    }

    private function read(): array
    {
        if (!is_dir($this->directory)) { return []; }
        $lock = $this->lock(LOCK_SH);
        try { return $this->decode(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function decode(): array
    {
        $path = $this->directory . '/accounts.enc';
        if (!is_file($path)) { return []; }
        $blob = @file_get_contents($path);
        $key = @file_get_contents($this->directory . '/accounts.key');
        if (!is_string($blob) || strlen($blob) < 29 || !is_string($key) || strlen($key) !== 32) { $this->storageFailure(); }
        $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));
        if ($plain === false) { $this->storageFailure(); }
        try { $data = json_decode($plain, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->storageFailure(); }
        if (!is_array($data)) { $this->storageFailure(); }
        return $data;
    }

    private function lock(int $mode)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { $this->storageFailure(); }
        $deny = $this->directory . '/.htaccess';
        if (!is_file($deny)) { $this->atomic($deny, "Require all denied\n"); }
        $lock = @fopen($this->directory . '/accounts.lock', 'c+');
        if ($lock === false) { $this->storageFailure(); }
        if (!flock($lock, $mode)) { fclose($lock); $this->storageFailure(); }
        return $lock;
    }

    private function atomic(string $path, string $contents): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) { $this->storageFailure(); }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) { $this->storageFailure(); }
        } finally { if (is_file($tmp)) { @unlink($tmp); } }
    }

    private function invalid(): void
    {
        throw new AppException('Configuração da conta inválida. Verifique nome, cor, URL oficial e ramal.', 'INVALID_ACCOUNT_SETTINGS', 400);
    }

    private function storageFailure(): void
    {
        throw new AppException('Não foi possível acessar a configuração local das contas.', 'ACCOUNT_STORAGE_FAILED', 500);
    }
}
