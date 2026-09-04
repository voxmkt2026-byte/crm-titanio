<?php
/**
 * app/models/ImportError.php
 * Erros/avisos linha a linha de uma importação de leads via CSV (Fase 4).
 */

require_once APP_PATH . '/core/Model.php';

class ImportError extends Model
{
    protected string $table = 'import_errors';

    public function add(int $importId, int $rowNumber, ?array $rawData, string $errorMessage): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO import_errors (import_id, row_num, raw_data, error_message, created_at)
             VALUES (:import_id, :row_num, :raw_data, :error_message, NOW())"
        );
        $stmt->execute([
            ':import_id'     => $importId,
            ':row_num'    => $rowNumber,
            ':raw_data'      => $rawData !== null ? json_encode($rawData, JSON_UNESCAPED_UNICODE) : null,
            ':error_message' => $errorMessage,
        ]);
    }

    public function forImport(int $importId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM import_errors WHERE import_id = :import_id ORDER BY row_num ASC"
        );
        $stmt->execute([':import_id' => $importId]);
        return $stmt->fetchAll();
    }
}
