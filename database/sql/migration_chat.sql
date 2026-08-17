-- =====================================================================
-- Titanium CRM - Migration Chat Interno
-- Execute este arquivo SOMENTE APÓS já ter importado, nesta ordem:
--   1) database/sql/schema.sql
--   2) database/sql/seed.sql
--   3) database/sql/migration_fase2.sql
--   4) database/sql/migration_fase3.sql
--   5) database/sql/migration_fase4.sql
--   6) database/sql/migration_fase5.sql
--
-- O que esta migration cria/altera:
--   - users: nova coluna department_id (FK nullable para chat_departments),
--            usada para entrada automática na sala do departamento e para
--            filtrar "colegas do meu departamento" ao iniciar uma DM.
--   - Tabela chat_departments (catálogo de departamentos + seed inicial)
--   - Tabela chat_rooms (sala de departamento / grupo customizado / DM 1-a-1)
--   - Tabela chat_room_members (participantes de cada sala, com papel de
--     moderação por sala e controle de "última leitura" para não lidas)
--   - Tabela chat_messages (mensagens, com soft delete e edição)
--   - Seed: 1 sala tipo 'departamento' para cada departamento (inclusive
--     "Geral", visível a todos os usuários)
--   - Novas permissões granulares: chat.moderate / chat.create_room
--     (ver app/core/Auth.php::can() e database/sql/migration_fase3.sql para
--     o modelo de permissions/role_permissions já existente)
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_chat.sql
--
-- Esta migration é incremental e idempotente onde o MySQL permite
-- (CREATE TABLE IF NOT EXISTS / INSERT IGNORE). A coluna nova em `users`
-- usa o mesmo procedimento auxiliar já usado na migration_fase3.sql, para
-- só ser adicionada se ainda não existir (evita erro em quem rodar este
-- arquivo mais de uma vez).
--
-- IMPORTANTE (bug conhecido MySQL 8 / Hostinger): nenhuma query deste
-- módulo referencia alias de SUM/AVG/COUNT dentro de outra expressão em
-- ORDER BY/HAVING (ver app/models/ChatRoom.php) — sempre a expressão
-- completa é repetida, para não reproduzir o erro
-- "PDOException 42S22 Reference not supported" já visto em produção.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabela: chat_departments
-- Catálogo de departamentos usados para dividir o chat interno.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#3b82f6' COMMENT 'Cor de destaque (hex) usada na UI do chat',
    icon VARCHAR(40) NOT NULL DEFAULT 'fa-solid fa-comments' COMMENT 'Classe FontAwesome',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_departments_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Departamentos usados para dividir o chat interno e o cadastro de usuários';

-- ---------------------------------------------------------------------
-- users: coluna department_id (departamento do usuário)
-- ---------------------------------------------------------------------
SET @col_exists_chat_1 := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'department_id'
);
SET @sql_chat_1 := IF(@col_exists_chat_1 = 0,
    'ALTER TABLE users ADD COLUMN department_id INT UNSIGNED DEFAULT NULL AFTER role, ADD KEY idx_users_department (department_id), ADD CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES chat_departments (id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt_chat_1 FROM @sql_chat_1;
EXECUTE stmt_chat_1;
DEALLOCATE PREPARE stmt_chat_1;

-- ---------------------------------------------------------------------
-- Seed: departamentos padrão (coerentes com o negócio da Titanium
-- Consultoria). "Geral" é a sala padrão, visível a todos os usuários
-- independentemente do departamento (ver ChatRoom::isMember()).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO chat_departments (name, slug, color, icon) VALUES
('Comercial', 'comercial', '#3b82f6', 'fa-solid fa-handshake'),
('Suporte', 'suporte', '#0891b2', 'fa-solid fa-headset'),
('Financeiro', 'financeiro', '#16a34a', 'fa-solid fa-sack-dollar'),
('Diretoria', 'diretoria', '#4f46e5', 'fa-solid fa-crown'),
('Geral', 'geral', '#6b7280', 'fa-solid fa-comments');

-- ---------------------------------------------------------------------
-- Tabela: chat_rooms
-- Uma sala tipo 'departamento' é a sala oficial de um chat_department
-- (department_id preenchido, único por departamento). 'grupo' é uma sala
-- customizada criada por um usuário. 'direto' é uma conversa 1-a-1.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_rooms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED DEFAULT NULL,
    lead_id INT UNSIGNED DEFAULT NULL COMMENT 'Lead associado quando a sala é uma colaboração privada sobre um atendimento',
    name VARCHAR(120) DEFAULT NULL COMMENT 'Nome da sala (grupo). Nulo para departamento (usa o nome do chat_department) e para DM (usa o nome do outro participante)',
    type ENUM('departamento', 'grupo', 'direto') NOT NULL DEFAULT 'grupo',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_rooms_department (department_id),
    UNIQUE KEY uq_chat_rooms_lead (lead_id),
    KEY idx_chat_rooms_type (type),
    CONSTRAINT fk_chat_rooms_department FOREIGN KEY (department_id) REFERENCES chat_departments (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_chat_rooms_lead FOREIGN KEY (lead_id) REFERENCES leads (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_chat_rooms_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Salas de chat: departamento oficial, grupo customizado ou conversa direta (DM)';

-- ---------------------------------------------------------------------
-- Tabela: chat_room_members
-- Participantes de cada sala. O papel (role) dá poderes de moderação
-- SOMENTE dentro daquela sala específica (ver app/core/Auth.php::can()
-- para a permissão global equivalente, chat.moderate).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_room_members (
    room_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('membro', 'moderador', 'admin_sala') NOT NULL DEFAULT 'membro',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_at DATETIME DEFAULT NULL COMMENT 'Usado para calcular mensagens não lidas (created_at > last_read_at)',
    muted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Silencia notificações/badge da sala sem sair dela',
    PRIMARY KEY (room_id, user_id),
    KEY idx_chat_room_members_user (user_id),
    CONSTRAINT fk_chat_room_members_room FOREIGN KEY (room_id) REFERENCES chat_rooms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_chat_room_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Participantes de cada sala de chat (membership + papel de moderação local + leitura)';

-- ---------------------------------------------------------------------
-- Tabela: chat_messages
-- `last_read_at` na membership já é suficiente para o requisito de
-- "não lidas" (COUNT de mensagens com created_at > last_read_at), então
-- não foi criada uma tabela extra de leitura granular por mensagem.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL COMMENT 'Nulo para mensagens de sistema (ex: "Fulano entrou na sala")',
    type ENUM('texto', 'sistema', 'comando') NOT NULL DEFAULT 'texto',
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME DEFAULT NULL,
    deleted_at DATETIME DEFAULT NULL COMMENT 'Soft delete: preserva auditoria ao "apagar" mensagem',
    PRIMARY KEY (id),
    KEY idx_chat_messages_room_created (room_id, created_at),
    CONSTRAINT fk_chat_messages_room FOREIGN KEY (room_id) REFERENCES chat_rooms (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_chat_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Mensagens do chat interno (texto, sistema ou comando), com soft delete e edição';

-- ---------------------------------------------------------------------
-- Seed: 1 sala oficial por departamento (inclusive "Geral")
-- ---------------------------------------------------------------------
INSERT IGNORE INTO chat_rooms (department_id, name, type, created_by, created_at)
SELECT id, NULL, 'departamento', NULL, NOW() FROM chat_departments;

-- ---------------------------------------------------------------------
-- Seed: adiciona todos os usuários já cadastrados na sala "Geral" (para
-- que já apareça no chat sem precisar esperar o próximo login — o login
-- e o cadastro/edição de usuário também sincronizam isso automaticamente
-- a partir de agora, ver ChatRoom::syncUserDepartmentMembership()).
-- ---------------------------------------------------------------------
INSERT IGNORE INTO chat_room_members (room_id, user_id, role, joined_at, last_read_at, muted)
SELECT r.id, u.id, 'membro', NOW(), NOW(), 0
FROM chat_rooms r
INNER JOIN chat_departments d ON d.id = r.department_id AND d.slug = 'geral'
CROSS JOIN users u;

-- Também entra automaticamente quem já tinha um department_id preenchido
-- (não deveria existir ainda nesta primeira execução, mas é seguro/idempotente).
INSERT IGNORE INTO chat_room_members (room_id, user_id, role, joined_at, last_read_at, muted)
SELECT r.id, u.id, 'membro', NOW(), NOW(), 0
FROM chat_rooms r
INNER JOIN users u ON u.department_id = r.department_id
WHERE r.type = 'departamento';

-- ---------------------------------------------------------------------
-- Seed: novas permissões granulares (tabelas permissions/role_permissions
-- já existentes desde a migration_fase3.sql)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO permissions (slug, label) VALUES
('chat.moderate',     'Moderar o chat interno (apagar/limpar mensagens e gerenciar membros de qualquer sala)'),
('chat.create_room',  'Criar salas de grupo no chat interno');

-- chat.moderate -> admin e supervisor por padrão
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE slug = 'chat.moderate';

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'supervisor', id FROM permissions WHERE slug = 'chat.moderate';

-- chat.create_room -> todos os papéis por padrão (ação colaborativa comum)
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE slug = 'chat.create_room';

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'supervisor', id FROM permissions WHERE slug = 'chat.create_room';

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'consultor', id FROM permissions WHERE slug = 'chat.create_room';

SET FOREIGN_KEY_CHECKS = 1;
