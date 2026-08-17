<?php
/**
 * Instalador e atualizador de banco do Titanium CRM.
 *
 * Centraliza a ordem das migrations legadas sem reescrever os arquivos SQL.
 * Cada migration concluída é registrada em `system_migrations`, portanto uma
 * nova execução aplica somente o que ainda não foi instalado.
 */
class DatabaseSetup
{
    private $rootPath;
    private $database;

    public function __construct($rootPath, array $database = [])
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/');
        $this->database = array_merge([
            'host' => defined('DB_HOST') ? DB_HOST : '',
            'name' => defined('DB_NAME') ? DB_NAME : '',
            'user' => defined('DB_USER') ? DB_USER : '',
            'pass' => defined('DB_PASS') ? DB_PASS : '',
            'charset' => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
        ], $database);
    }

    /**
     * Fonte única da ordem de instalação. Novas alterações de banco devem
     * entrar em um novo arquivo SQL e ser adicionadas ao fim desta lista.
     */
    public static function migrations($includeDemo = false)
    {
        $migrations = [
            ['name' => '000_schema', 'file' => 'database/sql/schema.sql', 'label' => 'Estrutura principal'],
        ];

        if ($includeDemo) {
            $migrations[] = ['name' => '001_demo_seed', 'file' => 'database/sql/seed.sql', 'label' => 'Dados de demonstração'];
        } else {
            $migrations[] = ['name' => '001_default_catalog', 'file' => null, 'label' => 'Catálogos iniciais'];
        }

        return array_merge($migrations, [
            ['name' => '010_lead_score', 'file' => 'database/sql/migration_fase2.sql', 'label' => 'Lead Score'],
            ['name' => '020_permissions_notifications', 'file' => 'database/sql/migration_fase3.sql', 'label' => 'Permissões e notificações'],
            ['name' => '030_lead_imports', 'file' => 'database/sql/migration_fase4.sql', 'label' => 'Importação de leads'],
            ['name' => '040_lead_score_sources', 'file' => 'database/sql/migration_fase5.sql', 'label' => 'Score por origem'],
            ['name' => '050_goals_agenda', 'file' => 'database/sql/migration_fase7_agenda.sql', 'label' => 'Metas e agenda'],
            ['name' => '060_leads_productivity', 'file' => 'database/sql/migration_fase7_leads.sql', 'label' => 'Produtividade de leads'],
            ['name' => '070_tasks', 'file' => 'database/sql/migration_tasks.sql', 'label' => 'Tarefas'],
            ['name' => '080_internal_chat', 'file' => 'database/sql/migration_chat.sql', 'label' => 'Chat interno'],
            ['name' => '081_lead_chat_rooms', 'file' => 'database/sql/migration_chat_v2.sql', 'label' => 'Salas por lead'],
            ['name' => '082_task_chat_rooms', 'file' => 'database/sql/migration_chat_v3.sql', 'label' => 'Salas por tarefa'],
            ['name' => '083_chat_group_management', 'file' => 'database/sql/migration_chat_v4.sql', 'label' => 'Gestão de grupos do chat'],
            ['name' => '090_forms', 'file' => 'database/sql/migration_forms.sql', 'label' => 'Formulários'],
            ['name' => '091_forms_theme', 'file' => 'database/sql/migration_forms_v2.sql', 'label' => 'Tema de formulários'],
            ['name' => '092_forms_branding', 'file' => 'database/sql/migration_forms_v3.sql', 'label' => 'Identidade dos formulários'],
            ['name' => '093_forms_api', 'file' => 'database/sql/migration_forms_v4.sql', 'label' => 'API de formulários'],
            ['name' => '100_user_permissions', 'file' => 'database/sql/migration_user_permissions.sql', 'label' => 'Permissões por pessoa'],
            ['name' => '110_workspace', 'file' => 'database/sql/migration_workspace.sql', 'label' => 'Workspace'],
            ['name' => '111_workspace_attachments', 'file' => 'database/sql/migration_workspace_v2.sql', 'label' => 'Anexos e quadros'],
            ['name' => '112_workspace_automations', 'file' => 'database/sql/migration_workspace_v3.sql', 'label' => 'Workspace e automações'],
            ['name' => '113_automation_templates', 'file' => 'database/sql/migration_automation_v2.sql', 'label' => 'Modelos de automação'],
            ['name' => '114_workspace_knowledge', 'file' => 'database/sql/migration_workspace_v4.sql', 'label' => 'Base de conhecimento'],
            ['name' => '115_calendar_people', 'file' => 'database/sql/migration_workspace_v5.sql', 'label' => 'Eventos e pessoas'],
            ['name' => '120_evolution_inbox', 'file' => 'database/sql/migration_evolution_inbox.sql', 'label' => 'Inbox Evolution'],
            ['name' => '121_evolution_messages', 'file' => 'database/sql/migration_evolution_api.sql', 'label' => 'Mensagens Evolution'],
            ['name' => '122_evolution_contact_data', 'file' => 'database/sql/migration_evolution_api_v2.sql', 'label' => 'Dados de contatos Evolution'],
            ['name' => '123_evolution_instances', 'file' => 'database/sql/migration_evolution_inbox_v3.sql', 'label' => 'Múltiplas linhas e fluxos'],
            ['name' => '130_goals_by_function', 'file' => 'database/sql/migration_goals_by_function.sql', 'label' => 'Metas por função'],
            ['name' => '131_templates_multichannel', 'file' => 'database/sql/migration_templates_multichannel.sql', 'label' => 'Templates de WhatsApp e e-mail'],
        ]);
    }

    public function inspect()
    {
        $result = [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'database_exists' => false,
            'application_exists' => false,
            'users' => 0,
            'tracked' => [],
            'error' => null,
        ];

        if (!$result['pdo_mysql']) {
            $result['error'] = 'A extensão PHP pdo_mysql não está habilitada.';
            return $result;
        }

        try {
            $server = $this->serverPdo();
            $result['database_exists'] = $this->databaseExists($server);
            if (!$result['database_exists']) {
                return $result;
            }

            $pdo = $this->databasePdo(false);
            $result['application_exists'] = $this->hasApplicationTables($pdo);
            $result['users'] = $this->userCount($pdo);
            $result['tracked'] = $this->migrationRows($pdo);
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
        }

        return $result;
    }

    /**
     * @param array{name:string,email:string,password:string} $admin
     * @return array{applied:array,skipped:array,review:array,created_admin:bool,demo_ignored:bool}
     */
    public function install($includeDemo, array $admin = [])
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('A extensão PHP pdo_mysql não está habilitada.');
        }

        $pdo = $this->databasePdo(true);
        $this->createMigrationTable($pdo);
        $freshDatabase = !$this->hasApplicationTables($pdo);
        $hasUsers = $this->userCount($pdo) > 0;
        $useDemo = (bool) $includeDemo && $freshDatabase && !$hasUsers;
        $tracked = $this->migrationRows($pdo);
        $result = [
            'applied' => [],
            'skipped' => [],
            'review' => [],
            'created_admin' => false,
            'demo_ignored' => (bool) $includeDemo && !$useDemo,
        ];

        $migrations = self::migrations($useDemo);
        try {
            // O schema e os catálogos vêm antes do administrador e das
            // migrations que criam conteúdo associado ao primeiro admin.
            foreach (array_slice($migrations, 0, 2) as $migration) {
                $this->applyMigration($pdo, $migration, $tracked, $result);
            }

            if ($this->userCount($pdo) === 0) {
                $this->createInitialAdmin($pdo, $admin);
                $result['created_admin'] = true;
            }

            foreach (array_slice($migrations, 2) as $migration) {
                $this->applyMigration($pdo, $migration, $tracked, $result);
            }
        } finally {
            // Migrations legadas desligam checagens de FK durante alterações.
            // Nunca deixe a conexão do instalador em um estado inconsistente.
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable $exception) {
                // A exceção original da migration, quando houver, é mais útil.
            }
        }

        return $result;
    }

    private function applyMigration(PDO $pdo, array $migration, array &$tracked, array &$result)
    {
        $name = $migration['name'];
        $checksum = $migration['file'] === null
            ? hash('sha256', 'default_catalog_v1')
            : $this->fileChecksum($migration['file']);

        if (isset($tracked[$name])) {
            if (!hash_equals($tracked[$name]['checksum'], $checksum)) {
                $result['review'][] = $migration['label'] . ' (' . $name . ') foi alterada após já ter sido aplicada.';
            } else {
                $result['skipped'][] = $migration['label'];
            }
            return;
        }

        if ($migration['file'] === null) {
            $this->seedDefaultCatalog($pdo);
        } else {
            $this->executeSqlFile($pdo, $migration['file']);
        }

        $statement = $pdo->prepare(
            'INSERT INTO system_migrations (migration_name, checksum, applied_at) VALUES (?, ?, NOW())'
        );
        $statement->execute([$name, $checksum]);
        $tracked[$name] = ['checksum' => $checksum];
        $result['applied'][] = $migration['label'];
    }

    private function createInitialAdmin(PDO $pdo, array $admin)
    {
        $name = trim((string) ($admin['name'] ?? ''));
        $email = trim((string) ($admin['email'] ?? ''));
        $password = (string) ($admin['password'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new RuntimeException(
                'Informe nome, e-mail válido e uma senha de ao menos 8 caracteres para o administrador inicial.'
            );
        }

        $statement = $pdo->prepare(
            "INSERT INTO users (name, email, password, role, active) VALUES (?, ?, ?, 'admin', 1)"
        );
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    }

    private function seedDefaultCatalog(PDO $pdo)
    {
        $statements = [
            "INSERT INTO loss_reasons (name, active) SELECT 'Sem entrada', 1 WHERE NOT EXISTS (SELECT 1 FROM loss_reasons WHERE name = 'Sem entrada')",
            "INSERT INTO loss_reasons (name, active) SELECT 'Não respondeu', 1 WHERE NOT EXISTS (SELECT 1 FROM loss_reasons WHERE name = 'Não respondeu')",
            "INSERT INTO loss_reasons (name, active) SELECT 'Comprou com concorrente', 1 WHERE NOT EXISTS (SELECT 1 FROM loss_reasons WHERE name = 'Comprou com concorrente')",
            "INSERT INTO loss_reasons (name, active) SELECT 'Desistiu', 1 WHERE NOT EXISTS (SELECT 1 FROM loss_reasons WHERE name = 'Desistiu')",
            "INSERT INTO loss_reasons (name, active) SELECT 'Sem perfil', 1 WHERE NOT EXISTS (SELECT 1 FROM loss_reasons WHERE name = 'Sem perfil')",
            "INSERT INTO loss_reasons (name, active) SELECT 'Não possui renda', 1 WHERE NOT EXISTS (SELECT 1 FROM loss_reasons WHERE name = 'Não possui renda')",
            "INSERT INTO loss_reasons (name, active) SELECT 'Outro', 1 WHERE NOT EXISTS (SELECT 1 FROM loss_reasons WHERE name = 'Outro')",
            "INSERT IGNORE INTO pipeline_stages (name, order_position, color) VALUES ('Novo', 1, '#3b82f6'), ('Contato', 2, '#0891b2'), ('Qualificado', 3, '#d97706'), ('Negociação', 4, '#7c3aed'), ('Documentação', 5, '#6366f1'), ('Fechado', 6, '#16a34a'), ('Perdido', 7, '#dc2626')",
            "INSERT IGNORE INTO tags (name, color) VALUES ('VIP', '#d97706'), ('Urgente', '#dc2626'), ('Retornar depois', '#6b7280'), ('Indicação', '#16a34a')",
            "INSERT IGNORE INTO settings (`key`, `value`) VALUES ('company_name', 'Titanium Consultoria'), ('company_logo', ''), ('company_favicon', ''), ('smtp_host', ''), ('smtp_port', '587'), ('smtp_user', ''), ('smtp_pass', ''), ('smtp_from_name', 'Titanium CRM')",
        ];

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }

    private function createMigrationTable(PDO $pdo)
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS system_migrations (' .
            'id INT UNSIGNED NOT NULL AUTO_INCREMENT, ' .
            'migration_name VARCHAR(120) NOT NULL, ' .
            'checksum CHAR(64) NOT NULL, ' .
            'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'PRIMARY KEY (id), UNIQUE KEY uq_system_migrations_name (migration_name)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function migrationRows(PDO $pdo)
    {
        if (!$this->tableExists($pdo, 'system_migrations')) {
            return [];
        }

        $rows = $pdo->query('SELECT migration_name, checksum, applied_at FROM system_migrations')->fetchAll(PDO::FETCH_ASSOC);
        $tracked = [];
        foreach ($rows as $row) {
            $tracked[$row['migration_name']] = $row;
        }
        return $tracked;
    }

    private function hasApplicationTables(PDO $pdo)
    {
        return $this->tableExists($pdo, 'users') || $this->tableExists($pdo, 'leads');
    }

    private function userCount(PDO $pdo)
    {
        if (!$this->tableExists($pdo, 'users')) {
            return 0;
        }
        return (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    private function tableExists(PDO $pdo, $table)
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function fileChecksum($relativePath)
    {
        $path = $this->path($relativePath);
        if (!is_file($path)) {
            throw new RuntimeException('Migration não encontrada: ' . $relativePath);
        }
        return hash_file('sha256', $path);
    }

    private function executeSqlFile(PDO $pdo, $relativePath)
    {
        $path = $this->path($relativePath);
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException('Não foi possível ler a migration: ' . $relativePath);
        }

        $statements = $this->splitSql($sql);
        foreach ($statements as $index => $statement) {
            try {
                $this->executeStatement($pdo, $statement);
            } catch (PDOException $exception) {
                throw new RuntimeException(
                    'Falha em ' . $relativePath . ' (comando ' . ($index + 1) . '): ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
    }

    /**
     * PDO::exec() descarta o PDOStatement de um EXECUTE que produza SELECT 1
     * (usado pelas migrations idempotentes). Em alguns drivers da Hostinger
     * esse cursor fica pendente e impede o próximo DEALLOCATE PREPARE com o
     * erro MySQL 2014. query() nos dá acesso ao statement para esgotar todos
     * os result sets antes de continuar.
     */
    private function executeStatement(PDO $pdo, $sql)
    {
        $statement = $pdo->query($sql);
        if (!$statement instanceof PDOStatement) {
            return;
        }

        do {
            if ($statement->columnCount() > 0) {
                $statement->fetchAll(PDO::FETCH_ASSOC);
            }
        } while ($statement->nextRowset());

        $statement->closeCursor();
    }

    /**
     * Divide SQL MySQL preservando strings, comentários e procedimentos com
     * DELIMITER. PDO não reconhece a diretiva DELIMITER, por isso ela é
     * interpretada aqui antes de os comandos serem enviados ao servidor.
     */
    private function splitSql($sql)
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', (string) $sql);
        $delimiter = ';';
        $buffer = '';
        $statements = [];

        foreach (preg_split('/\R/', $sql) as $line) {
            if (preg_match('/^\s*DELIMITER\s+(.+?)\s*$/i', $line, $matches)) {
                if ($this->hasSqlCode($buffer)) {
                    throw new RuntimeException('DELIMITER encontrado antes do fim do comando SQL.');
                }
                $delimiter = trim($matches[1]);
                if ($delimiter === '') {
                    throw new RuntimeException('DELIMITER SQL inválido.');
                }
                $buffer = '';
                continue;
            }

            $buffer .= $line . "\n";
            while (($statement = $this->extractStatement($buffer, $delimiter)) !== null) {
                if ($this->hasSqlCode($statement)) {
                    $statements[] = trim($statement);
                }
            }
        }

        if ($this->hasSqlCode($buffer)) {
            throw new RuntimeException('O último comando SQL não foi finalizado com o delimitador esperado (' . $delimiter . ').');
        }

        return $statements;
    }

    private function extractStatement(&$buffer, $delimiter)
    {
        $length = strlen($buffer);
        $delimiterLength = strlen($delimiter);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $buffer[$index];
            $next = $index + 1 < $length ? $buffer[$index + 1] : '';

            if ($lineComment) {
                if ($character === "\n") {
                    $lineComment = false;
                }
                continue;
            }
            if ($blockComment) {
                if ($character === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                    continue;
                }
                if ($character === $quote) {
                    // SQL aceita aspas duplicadas dentro de strings delimitadas.
                    if ($next === $quote && $quote !== '`') {
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($character === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($buffer[$index + 2]))) {
                $lineComment = true;
                $index++;
                continue;
            }
            if ($character === '#') {
                $lineComment = true;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }
            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                continue;
            }
            if (substr($buffer, $index, $delimiterLength) === $delimiter) {
                $statement = substr($buffer, 0, $index);
                $buffer = substr($buffer, $index + $delimiterLength);
                return $statement;
            }
        }

        return null;
    }

    private function hasSqlCode($sql)
    {
        $withoutBlocks = preg_replace('#/\*.*?\*/#s', '', (string) $sql);
        $withoutLines = preg_replace('/(^|\n)\s*(?:--[^\n]*|#[^\n]*)/', '$1', $withoutBlocks);
        return trim($withoutLines) !== '';
    }

    private function serverPdo()
    {
        $this->assertConfiguration();
        return new PDO(
            'mysql:host=' . $this->database['host'] . ';charset=' . $this->database['charset'],
            $this->database['user'],
            $this->database['pass'],
            $this->pdoOptions()
        );
    }

    private function databasePdo($createWhenMissing)
    {
        $server = $this->serverPdo();
        if (!$this->databaseExists($server)) {
            if (!$createWhenMissing) {
                throw new RuntimeException('O banco configurado ainda não existe.');
            }
            try {
                $server->exec(
                    'CREATE DATABASE IF NOT EXISTS ' . $this->quoteIdentifier($this->database['name']) .
                    ' CHARACTER SET ' . $this->quoteIdentifier($this->database['charset']) .
                    ' COLLATE utf8mb4_unicode_ci'
                );
            } catch (PDOException $exception) {
                throw new RuntimeException(
                    'Não foi possível criar o banco "' . $this->database['name'] . '". Crie-o no hPanel ou conceda CREATE ao usuário MySQL. ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }

        return new PDO(
            'mysql:host=' . $this->database['host'] . ';dbname=' . $this->database['name'] . ';charset=' . $this->database['charset'],
            $this->database['user'],
            $this->database['pass'],
            $this->pdoOptions()
        );
    }

    private function databaseExists(PDO $server)
    {
        $statement = $server->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$this->database['name']]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function assertConfiguration()
    {
        if (trim((string) $this->database['host']) === '' || trim((string) $this->database['name']) === '' || trim((string) $this->database['user']) === '') {
            throw new RuntimeException('Preencha host, nome e usuário do banco antes de continuar.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $this->database['charset'])) {
            throw new RuntimeException('Charset do banco inválido.');
        }
    }

    private function pdoOptions()
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Migrations idempotentes usam PREPARE/EXECUTE com "SELECT 1" como
        // no-op. Sem buffer, o driver MySQL mantém esse result set aberto e
        // bloqueia o próximo DEALLOCATE/ALTER (erro 2014).
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }

        return $options;
    }

    private function quoteIdentifier($identifier)
    {
        return '`' . str_replace('`', '``', (string) $identifier) . '`';
    }

    private function path($relativePath)
    {
        return $this->rootPath . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    }
}
