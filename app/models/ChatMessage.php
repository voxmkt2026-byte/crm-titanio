<?php
/**
 * app/models/ChatMessage.php
 * Mensagens do chat interno (tabela `chat_messages`, ver
 * database/sql/migration_chat.sql). Suporta soft delete (moderação sem
 * perder auditoria), edição e paginação por scroll ("carregar mais
 * antigas") além do polling incremental via since_id.
 */

require_once APP_PATH . '/core/Model.php';

class ChatMessage extends Model
{
    protected string $table = 'chat_messages';

    public function find(int $id)
    {
        $stmt = $this->db->prepare(
            "SELECT cm.*, u.name AS user_name, u.avatar AS user_avatar
             FROM chat_messages cm
             LEFT JOIN users u ON u.id = cm.user_id
             WHERE cm.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function send(int $roomId, ?int $userId, string $content, string $type = 'texto'): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_messages (room_id, user_id, type, content, created_at)
             VALUES (:room_id, :user_id, :type, :content, NOW())"
        );
        $stmt->execute([
            ':room_id' => $roomId,
            ':user_id' => $userId,
            ':type'    => $type,
            ':content' => $content,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Mensagem de sistema (ex: "Fulano entrou na sala"), sem autor. */
    public function system(int $roomId, string $content): int
    {
        return $this->send($roomId, null, $content, 'sistema');
    }

    /**
     * Lista mensagens de uma sala.
     * - $sinceId informado: polling incremental, retorna só mensagens novas (id > sinceId), ordem crescente.
     * - $beforeId informado: "carregar mais antigas", retorna até $limit mensagens com id < beforeId, ordem crescente.
     * - nenhum dos dois: carga inicial, últimas $limit mensagens, ordem crescente.
     */
    public function listForRoom(int $roomId, ?int $sinceId = null, ?int $beforeId = null, int $limit = 30): array
    {
        if ($sinceId !== null) {
            $stmt = $this->db->prepare(
                "SELECT cm.*, u.name AS user_name, u.avatar AS user_avatar
                 FROM chat_messages cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 WHERE cm.room_id = :room_id AND cm.id > :since_id
                 ORDER BY cm.id ASC
                 LIMIT :limit"
            );
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        if ($beforeId !== null) {
            $stmt = $this->db->prepare(
                "SELECT cm.*, u.name AS user_name, u.avatar AS user_avatar
                 FROM chat_messages cm
                 LEFT JOIN users u ON u.id = cm.user_id
                 WHERE cm.room_id = :room_id AND cm.id < :before_id
                 ORDER BY cm.id DESC
                 LIMIT :limit"
            );
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
            return array_reverse($rows);
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM (
                SELECT cm.*, u.name AS user_name, u.avatar AS user_avatar
                FROM chat_messages cm
                LEFT JOIN users u ON u.id = cm.user_id
                WHERE cm.room_id = :room_id
                ORDER BY cm.id DESC
                LIMIT :limit
            ) t ORDER BY t.id ASC"
        );
        $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE chat_messages SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL");
        return $stmt->execute([':id' => $id]);
    }

    /** Apaga (soft delete) todas as mensagens de uma sala — usado pelo comando /limpar. */
    public function clearRoom(int $roomId): int
    {
        $stmt = $this->db->prepare(
            "UPDATE chat_messages SET deleted_at = NOW() WHERE room_id = :room_id AND deleted_at IS NULL"
        );
        $stmt->execute([':room_id' => $roomId]);
        return $stmt->rowCount();
    }

    public function edit(int $id, string $content): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE chat_messages SET content = :content, edited_at = NOW() WHERE id = :id AND deleted_at IS NULL"
        );
        return $stmt->execute([':content' => $content, ':id' => $id]);
    }
}
