<?php
/**
 * Entrega segura de imagens enviadas ao CRM quando a hospedagem não publica
 * diretamente a pasta física public/uploads (cenário comum ao copiar o
 * conteúdo de public/ para public_html na Hostinger).
 */
require_once APP_PATH . '/core/Controller.php';

class UploadController extends Controller
{
    public function image(string $filename): void
    {
        // Apenas imagens geradas pelos uploads de identidade, formulários e
        // relatórios. Nunca aceita barras, nomes arbitrários ou extensões
        // executáveis no fallback do front controller.
        $pattern = '/^(?:logo|favicon|report_logo|chat_group|formulario_(?:logo|cover_image))_[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp|ico)$/i';
        if (!preg_match($pattern, $filename)) {
            $this->notFound();
        }

        $directory = realpath(UPLOADS_PATH);
        $path = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $filename) : false;
        if (!$directory || !$path || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !is_file($path)) {
            $this->notFound();
        }

        $mime = (string) mime_content_type($path);
        $allowed = [
            'image/jpeg', 'image/png', 'image/webp',
            'image/x-icon', 'image/vnd.microsoft.icon', 'image/icon',
        ];
        if (!in_array($mime, $allowed, true)) {
            $this->notFound();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Imagem não encontrada.');
    }
}
