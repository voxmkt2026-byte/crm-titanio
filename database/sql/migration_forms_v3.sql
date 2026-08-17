SET NAMES utf8mb4;

-- Personalização visual extra do formulário público: logo próprio (senão usa
-- o logo da empresa em Configurações), imagem de capa/banner, e rodapé
-- personalizado (ver forms/public.php e forms/builder.php).

ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS logo_url VARCHAR(500) NULL AFTER font_family,
    ADD COLUMN IF NOT EXISTS cover_image_url VARCHAR(500) NULL AFTER logo_url,
    ADD COLUMN IF NOT EXISTS footer_text VARCHAR(255) NULL AFTER cover_image_url;
