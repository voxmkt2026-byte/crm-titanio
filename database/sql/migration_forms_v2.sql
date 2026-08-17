SET NAMES utf8mb4;

-- Personalização visual do Construtor de Formulários: tema de cor e
-- tipografia da página pública (ver forms/public.php e forms/builder.php).
-- As etapas do formulário (multi-step/progresso) não precisam de coluna nova
-- — são guardadas dentro do próprio JSON de `fields` (chave "new_step" por
-- campo, marcando onde uma nova etapa começa).

ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS theme VARCHAR(20) NOT NULL DEFAULT 'padrao' AFTER success_message,
    ADD COLUMN IF NOT EXISTS font_family VARCHAR(20) NOT NULL DEFAULT 'padrao' AFTER theme;
