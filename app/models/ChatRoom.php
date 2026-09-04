<?php
/**
 * app/models/ChatRoom.php
 * Salas de chat: departamento oficial, grupo customizado ou conversa
 * direta (DM). Tabelas `chat_rooms` e `chat_room_members` criadas em
 * database/sql/migration_chat.sql.
 *
 * Regra de acesso (aplicada no ChatController, nunca só no front-end):
 * um usuário só pode ler/escrever numa sala se existir uma linha dele em
 * chat_room_members para aquele room_id — ver isMember(). A sala
 * "departamento" da sigla "Geral" e a do próprio department_id do usuário
 * são preenchidas automaticamente em syncUserDepartmentMembership(),
 * chamada no login (AuthController), no cadastro/edição de usuário
 * (UserController) e, defensivamente, a cada carregamento da tela de chat
 * (ChatController::index), para nunca depender de um único ponto de entrada.
 *
 * IMPORTANTE (bug conhecido MySQL 8 / Hostinger): nunca usar alias de
 * SUM/AVG/COUNT dentro de outra expressão em ORDER BY/HAVING. As queries
 * abaixo usam subqueries correlacionadas (COUNT(*) AS unread_count) e
 * ordenam por uma coluna direta (last_message_at), nunca por uma expressão
 * que recombine um alias de agregação.
 */

require_once APP_PATH . '/core/Model.php';

class ChatRoom extends Model
{
    protected string $table = 'chat_rooms';

    public function find(int $id)
    {
        $stmt = $this->db->prepare("SELECT * FROM chat_rooms WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /** Sala oficial (tipo 'departamento') de um chat_department. */
    public function findDepartmentRoom(int $departmentId)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM chat_rooms WHERE department_id = :department_id AND type = 'departamento' LIMIT 1"
        );
        $stmt->execute([':department_id' => $departmentId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /** Retorna a única sala privada vinculada a um lead, quando já criada. */
    public function findLeadRoom(int $leadId)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM chat_rooms WHERE lead_id = :lead_id LIMIT 1"
        );
        $stmt->execute([':lead_id' => $leadId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /** Retorna a única sala privada vinculada a uma tarefa, quando já criada. */
    public function findTaskRoom(int $taskId)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM chat_rooms WHERE task_id = :task_id LIMIT 1"
        );
        $stmt->execute([':task_id' => $taskId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Garante que o usuário está na sala "Geral" e, se tiver departamento
     * definido, na sala oficial do próprio departamento — removendo-o de
     * salas de departamento antigas caso ele tenha trocado de setor. Nunca
     * mexe em salas do tipo 'grupo'/'direto' (essas dependem só de convite).
     */
    public function syncUserDepartmentMembership(int $userId, ?int $departmentId): void
    {
        // Sala "Geral": todo usuário sempre participa.
        $stmt = $this->db->prepare(
            "SELECT r.id FROM chat_rooms r
             INNER JOIN chat_departments d ON d.id = r.department_id
             WHERE d.slug = 'geral' AND r.type = 'departamento' LIMIT 1"
        );
        $stmt->execute();
        $geral = $stmt->fetch();
        if ($geral) {
            $this->addMember((int) $geral['id'], $userId, 'membro', true);
        }

        // Remove o usuário de salas de departamento que não são mais a dele
        // (exceto a "Geral", sempre preservada).
        $stmt = $this->db->prepare(
            "DELETE crm FROM chat_room_members crm
             INNER JOIN chat_rooms r ON r.id = crm.room_id
             INNER JOIN chat_departments d ON d.id = r.department_id
             WHERE crm.user_id = :user_id
               AND r.type = 'departamento'
               AND d.slug != 'geral'
               AND (:department_id IS NULL OR r.department_id != :department_id2)"
        );
        $stmt->execute([
            ':user_id'       => $userId,
            ':department_id' => $departmentId,
            ':department_id2' => $departmentId,
        ]);

        if ($departmentId) {
            $room = $this->findDepartmentRoom($departmentId);
            if ($room) {
                $this->addMember((int) $room['id'], $userId, 'membro', true);
            }
        }
    }

    public function isMember(int $roomId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id LIMIT 1"
        );
        $stmt->execute([':room_id' => $roomId, ':user_id' => $userId]);
        return (bool) $stmt->fetch();
    }

    public function getMember(int $roomId, int $userId)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id LIMIT 1"
        );
        $stmt->execute([':room_id' => $roomId, ':user_id' => $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Adiciona (ou reativa) um membro na sala. Se $markAsRead for true, já
     * marca last_read_at = NOW() (usado na entrada automática de departamento,
     * para não "chover" mensagens antigas como não lidas).
     */
    public function addMember(int $roomId, int $userId, string $role = 'membro', bool $markAsRead = false): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO chat_room_members (room_id, user_id, role, joined_at, last_read_at, muted)
             VALUES (:room_id, :user_id, :role, NOW(), :last_read_at, 0)"
        );
        $stmt->execute([
            ':room_id'      => $roomId,
            ':user_id'      => $userId,
            ':role'         => $role,
            ':last_read_at' => $markAsRead ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public function removeMember(int $roomId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM chat_room_members WHERE room_id = :room_id AND user_id = :user_id"
        );
        return $stmt->execute([':room_id' => $roomId, ':user_id' => $userId]);
    }

    /** Quantidade de membros que podem administrar localmente a sala. */
    public function adminCount(int $roomId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM chat_room_members
             WHERE room_id = :room_id AND role IN ('admin_sala', 'moderador')"
        );
        $stmt->execute([':room_id' => $roomId]);
        return (int) $stmt->fetchColumn();
    }

    public function memberCount(int $roomId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM chat_room_members WHERE room_id = :room_id');
        $stmt->execute([':room_id' => $roomId]);
        return (int) $stmt->fetchColumn();
    }

    public function markRead(int $roomId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE chat_room_members SET last_read_at = NOW() WHERE room_id = :room_id AND user_id = :user_id"
        );
        return $stmt->execute([':room_id' => $roomId, ':user_id' => $userId]);
    }

    public function toggleMute(int $roomId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE chat_room_members SET muted = IF(muted = 1, 0, 1) WHERE room_id = :room_id AND user_id = :user_id"
        );
        return $stmt->execute([':room_id' => $roomId, ':user_id' => $userId]);
    }

    /** Lista de membros de uma sala, com dados básicos do usuário. */
    public function membersOf(int $roomId): array
    {
        $stmt = $this->db->prepare(
            "SELECT crm.user_id, crm.role, crm.joined_at, crm.muted, u.name, u.email, u.avatar
             FROM chat_room_members crm
             INNER JOIN users u ON u.id = crm.user_id
             WHERE crm.room_id = :room_id
             ORDER BY FIELD(crm.role, 'admin_sala', 'moderador', 'membro'), u.name ASC"
        );
        $stmt->execute([':room_id' => $roomId]);
        return $stmt->fetchAll();
    }

    /**
     * Cria uma sala de grupo customizada. O criador entra como 'admin_sala'
     * (moderação local mesmo sem a permissão global chat.moderate).
     *
     * @param int[] $memberIds IDs dos demais membros (o criador é adicionado à parte)
     * @return int ID da sala criada
     */
    public function createGroup(string $name, int $creatorId, array $memberIds): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_rooms (department_id, name, type, created_by, created_at)
             VALUES (NULL, :name, 'grupo', :created_by, NOW())"
        );
        $stmt->execute([':name' => $name, ':created_by' => $creatorId]);
        $roomId = (int) $this->db->lastInsertId();

        $this->addMember($roomId, $creatorId, 'admin_sala', true);
        foreach (array_unique($memberIds) as $memberId) {
            $memberId = (int) $memberId;
            if ($memberId > 0 && $memberId !== $creatorId) {
                $this->addMember($roomId, $memberId, 'membro', true);
            }
        }

        return $roomId;
    }

    /** Atualiza apenas grupos personalizados (nunca salas de lead/tarefa). */
    public function updateCustomGroup(int $roomId, string $name, ?string $description, ?string $imageFilename): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE chat_rooms
             SET name = :name, description = :description, image_filename = :image_filename
             WHERE id = :id AND type = 'grupo' AND lead_id IS NULL AND task_id IS NULL"
        );
        return $stmt->execute([
            ':id' => $roomId,
            ':name' => $name,
            ':description' => $description,
            ':image_filename' => $imageFilename,
        ]);
    }

    /**
     * Cria a sala privada de colaboração de um lead. A migration v2 garante
     * uma sala por lead (UNIQUE em lead_id); o controlador trata o caso de a
     * sala já existir antes de chamar este método.
     *
     * @param int[] $memberIds IDs dos participantes adicionais
     */
    public function createLeadRoom(int $leadId, string $name, int $creatorId, array $memberIds): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_rooms (department_id, lead_id, name, type, created_by, created_at)
             VALUES (NULL, :lead_id, :name, 'grupo', :created_by, NOW())"
        );
        $stmt->execute([
            ':lead_id'    => $leadId,
            ':name'       => $name,
            ':created_by' => $creatorId,
        ]);
        $roomId = (int) $this->db->lastInsertId();

        $this->addMember($roomId, $creatorId, 'admin_sala', true);
        foreach (array_unique($memberIds) as $memberId) {
            $memberId = (int) $memberId;
            if ($memberId > 0 && $memberId !== $creatorId) {
                $this->addMember($roomId, $memberId, 'membro', true);
            }
        }

        return $roomId;
    }

    /**
     * Cria a conversa privada de uma tarefa. A migration v3 garante uma sala
     * por tarefa (UNIQUE em task_id); o controlador trata concorrência.
     *
     * @param int[] $memberIds IDs dos participantes adicionais
     */
    public function createTaskRoom(int $taskId, string $name, int $creatorId, array $memberIds): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO chat_rooms (department_id, task_id, name, type, created_by, created_at)
             VALUES (NULL, :task_id, :name, 'grupo', :created_by, NOW())"
        );
        $stmt->execute([
            ':task_id'    => $taskId,
            ':name'       => $name,
            ':created_by' => $creatorId,
        ]);
        $roomId = (int) $this->db->lastInsertId();

        $this->addMember($roomId, $creatorId, 'admin_sala', true);
        foreach (array_unique($memberIds) as $memberId) {
            $memberId = (int) $memberId;
            if ($memberId > 0 && $memberId !== $creatorId) {
                $this->addMember($roomId, $memberId, 'membro', true);
            }
        }

        return $roomId;
    }

    /**
     * Busca a DM existente entre dois usuários ou cria uma nova, evitando
     * duplicar salas para o mesmo par.
     */
    public function findOrCreateDirect(int $userA, int $userB): int
    {
        $stmt = $this->db->prepare(
            "SELECT r.id
             FROM chat_rooms r
             INNER JOIN chat_room_members m1 ON m1.room_id = r.id AND m1.user_id = :user_a
             INNER JOIN chat_room_members m2 ON m2.room_id = r.id AND m2.user_id = :user_b
             WHERE r.type = 'direto'
             LIMIT 1"
        );
        $stmt->execute([':user_a' => $userA, ':user_b' => $userB]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int) $existing['id'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO chat_rooms (department_id, name, type, created_by, created_at)
             VALUES (NULL, NULL, 'direto', :created_by, NOW())"
        );
        $stmt->execute([':created_by' => $userA]);
        $roomId = (int) $this->db->lastInsertId();

        $this->addMember($roomId, $userA, 'membro', true);
        $this->addMember($roomId, $userB, 'membro', true);

        return $roomId;
    }

    /**
     * Salas do usuário logado (departamento, grupos e DMs), com contagem de
     * não lidas e prévia da última mensagem, para a coluna esquerda do chat.
     * Repetimos a expressão de contagem em vez de reaproveitar alias entre
     * subqueries — cada subquery já é isolada, então não há o problema do
     * alias de agregação referenciado noutra expressão (bug MySQL 8).
     */
    public function roomsForUser(int $userId): array
    {
        $sql = "SELECT
                    r.id, r.type, r.name, r.description, r.image_filename, r.department_id, r.lead_id, r.task_id,
                    d.name AS department_name, d.color AS department_color, d.icon AS department_icon,
                    l.name AS lead_name, l.lead_code, t.title AS task_title,
                    crm.role AS my_role, crm.muted, crm.last_read_at,
                    (
                        SELECT COUNT(*) FROM chat_messages cm
                        WHERE cm.room_id = r.id
                          AND cm.deleted_at IS NULL
                          AND cm.created_at > COALESCE(crm.last_read_at, '1970-01-01 00:00:00')
                    ) AS unread_count,
                    (
                        SELECT cm2.content FROM chat_messages cm2
                        WHERE cm2.room_id = r.id AND cm2.deleted_at IS NULL
                        ORDER BY cm2.created_at DESC, cm2.id DESC LIMIT 1
                    ) AS last_message,
                    (
                        SELECT cm3.created_at FROM chat_messages cm3
                        WHERE cm3.room_id = r.id AND cm3.deleted_at IS NULL
                        ORDER BY cm3.created_at DESC, cm3.id DESC LIMIT 1
                    ) AS last_message_at,
                    (
                        SELECT u2.name FROM chat_room_members m2
                        INNER JOIN users u2 ON u2.id = m2.user_id
                        WHERE m2.room_id = r.id AND m2.user_id != :uid_direct_name
                        LIMIT 1
                    ) AS direct_other_name,
                    (
                        SELECT m3.user_id FROM chat_room_members m3
                        WHERE m3.room_id = r.id AND m3.user_id != :uid_direct_id
                        LIMIT 1
                    ) AS direct_other_id,
                    (
                        SELECT u4.avatar FROM chat_room_members m4
                        INNER JOIN users u4 ON u4.id = m4.user_id
                        WHERE m4.room_id = r.id AND m4.user_id != :uid_direct_avatar
                        LIMIT 1
                    ) AS direct_other_avatar
                FROM chat_room_members crm
                INNER JOIN chat_rooms r ON r.id = crm.room_id
                LEFT JOIN chat_departments d ON d.id = r.department_id
                LEFT JOIN leads l ON l.id = r.lead_id
                LEFT JOIN tasks t ON t.id = r.task_id
                WHERE crm.user_id = :uid_main
                ORDER BY (last_message_at IS NULL) ASC, last_message_at DESC, r.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':uid_direct_name' => $userId,
            ':uid_direct_id'   => $userId,
            ':uid_direct_avatar' => $userId,
            ':uid_main'        => $userId,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Contagem total de não lidas (para o badge da sidebar), somando só
     * salas não silenciadas — silenciar uma sala tira ela do contador
     * global sem exigir que o usuário saia dela.
     */
    public function unreadCountForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total
             FROM chat_messages cm
             INNER JOIN chat_room_members crm ON crm.room_id = cm.room_id AND crm.user_id = :user_id
             WHERE cm.deleted_at IS NULL
               AND crm.muted = 0
               AND cm.created_at > COALESCE(crm.last_read_at, '1970-01-01 00:00:00')
               AND (cm.user_id IS NULL OR cm.user_id != :user_id2)"
        );
        $stmt->execute([':user_id' => $userId, ':user_id2' => $userId]);
        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM chat_rooms WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /** Apaga um grupo livre; mensagens e memberships seguem o ON DELETE CASCADE. */
    public function deleteCustomGroup(int $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM chat_rooms
             WHERE id = :id AND type = 'grupo' AND lead_id IS NULL AND task_id IS NULL"
        );
        return $stmt->execute([':id' => $id]) && $stmt->rowCount() > 0;
    }
}
