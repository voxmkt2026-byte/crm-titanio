SET NAMES utf8mb4;

-- Permissões por pessoa (além do papel): permite liberar uma permissão extra
-- para um único usuário sem promovê-lo de papel (ex: consultor com acesso a
-- "leads.export"), ou negar explicitamente uma permissão que o papel dele
-- normalmente liberaria. Lido por Auth::can() (ver app/core/Auth.php),
-- combinado com role_permissions (migration_fase3.sql).

CREATE TABLE IF NOT EXISTS user_permissions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 permission_id INT UNSIGNED NOT NULL,
 grant_type ENUM('allow','deny') NOT NULL DEFAULT 'allow',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uniq_user_permission (user_id, permission_id),
 CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
