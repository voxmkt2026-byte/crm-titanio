<?php
declare(strict_types=1);
namespace App;

/** Local reversible view state. Never modifies calls, recordings or analysis files. */
final class CallTrashStore
{
    private string $path;
    public function __construct(private string $directory, string $account)
    {
        AccountRegistry::validateId($account);
        $this->path = $directory . '/' . hash('sha256', 'trash-account:' . $account) . '.json';
    }

    public static function validateId(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $id)) {
            throw new AppException('Identificador de ligação inválido.', 'INVALID_CALL_ID', 400);
        }
        return $id;
    }

    public function trash(string $id, array $call, string $reason = ''): void
    {
        self::validateId($id);
        if (mb_strlen($reason) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)) {
            throw new AppException('Motivo inválido (máximo 500 caracteres).', 'INVALID_TRASH_REASON', 400);
        }
        $clean = ['id' => $id];
        foreach (['call_type', 'started_at', 'ended_at', 'from', 'to', 'hangup_cause', 'email', 'first_name', 'last_name', 'bina'] as $field) {
            $value = $call[$field] ?? '';
            $clean[$field] = is_string($value) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '', 0, 250) : '';
        }
        $clean['duration'] = max(0, (int) ($call['duration'] ?? 0));
        $clean['has_recording'] = ($call['has_recording'] ?? false) === true;
        foreach (['minute_price', 'call_price'] as $field) { $clean[$field] = is_numeric($call[$field] ?? null) ? (float) $call[$field] : null; }
        $clean['reason'] = trim($reason);
        $this->locked(true, function (array $rows) use ($id, $clean): array {
            $key = hash('sha256', $id);
            $clean['trashed_at'] = $rows[$key]['trashed_at'] ?? gmdate('c');
            $rows[$key] = $clean;
            return $rows;
        });
    }

    public function restore(string $id): void
    {
        self::validateId($id);
        $this->locked(true, static function (array $rows) use ($id): array { unset($rows[hash('sha256', $id)]); return $rows; });
    }

    public function listing(int $page, int $perPage = 20): array
    {
        $rows = array_values($this->locked(false));
        usort($rows, static fn (array $a, array $b): int => strcmp($b['trashed_at'], $a['trashed_at']));
        $total = count($rows); $perPage = max(1, min(100, $perPage));
        $pages = (int) ceil($total / $perPage); $page = max(1, min($page, max(1, $pages)));
        return ['data' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'count' => $total,
            'meta' => ['totalItemCount' => $total, 'totalPageCount' => $pages, 'currentPage' => $page,
                'itemsPerPage' => $perPage, 'nextPage' => $page < $pages ? $page + 1 : null, 'previousPage' => $page > 1 ? $page - 1 : null]];
    }

    public function filterPage(array $page): array
    {
        $rows = $this->locked(false); $before = count($page['data']);
        $page['data'] = array_values(array_filter($page['data'], static fn (array $call): bool => !isset($rows[hash('sha256', (string) ($call['id'] ?? ''))])));
        $page['meta']['hiddenOnPage'] = $before - count($page['data']);
        $page['meta']['trashCount'] = count($rows);
        return $page;
    }

    private function locked(bool $write, ?callable $change = null): array
    {
        if (!$write && !is_dir($this->directory)) { return []; }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { $this->failure(); }
        $lock = @fopen($this->path . '.lock', 'c+');
        if ($lock === false) { $this->failure(); }
        try {
            if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) { $this->failure(); }
            if ($write && !is_file($this->directory . '/.htaccess')) { $this->atomic($this->directory . '/.htaccess', "Require all denied\n"); }
            $rows = [];
            if (is_file($this->path)) {
                $raw = @file_get_contents($this->path);
                if (!is_string($raw)) { $this->failure(); }
                try { $rows = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { $this->failure(); }
                if (!is_array($rows)) { $this->failure(); }
                foreach ($rows as $key => $row) {
                    if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['trashed_at'] ?? null)
                        || !hash_equals(hash('sha256', $row['id']), (string) $key)) { $this->failure(); }
                }
            }
            if ($write) {
                $updated = $change($rows);
                if (isset($raw)) { $this->atomic($this->path . '.bak', $raw); }
                $this->atomic($this->path, json_encode((object) $updated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                return $updated;
            }
            return $rows;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function atomic(string $path, string $data): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (@file_put_contents($temp, $data, LOCK_EX) !== strlen($data)) { $this->failure(); }
            @chmod($temp, 0600);
            if (!@rename($temp, $path)) { $this->failure(); }
        } finally { if (is_file($temp)) { @unlink($temp); } }
    }
    private function failure(): void
    {
        throw new AppException('Não foi possível acessar a lixeira local. Os dados foram preservados.', 'TRASH_STORAGE_FAILED', 500);
    }
}
