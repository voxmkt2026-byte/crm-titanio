<?php
/**
 * app/core/Controller.php
 * Classe base para todos os Controllers.
 */

abstract class Controller
{
    /**
     * Renderiza uma view dentro do layout principal.
     *
     * @param string $viewName Ex: 'leads/index' (relativo a app/views, sem .php)
     * @param array  $data     Dados extraídos como variáveis dentro da view
     * @param string|null $layout Nome do layout (dentro de app/views/layouts). Null = sem layout.
     */
    protected function view(string $viewName, array $data = [], ?string $layout = 'layouts/main'): void
    {
        $viewFile = APP_PATH . '/views/' . $viewName . '.php';

        if (!file_exists($viewFile)) {
            http_response_code(500);
            die("View não encontrada: {$viewName}");
        }

        extract($data, EXTR_SKIP);

        if ($layout === null) {
            require $viewFile;
            return;
        }

        $layoutFile = APP_PATH . '/views/' . $layout . '.php';
        if (!file_exists($layoutFile)) {
            // Sem layout definido, renderiza só a view
            require $viewFile;
            return;
        }

        // Views podem opcionalmente definir $pageScripts (bloco <script> extra,
        // ex: inicialização de gráficos Chart.js) que o layout renderiza no final
        // do <body>. Usamos referência para que a atribuição feita dentro da view
        // (que roda no mesmo escopo do closure) seja visível fora dele.
        $pageScripts = $data['pageScripts'] ?? null;
        $pageStyles = $data['pageStyles'] ?? null;

        // Renderiza a view antes do layout. Isso garante que scripts e estilos
        // extras estejam completos e nunca escapem como texto visível na página.
        ob_start();
        require $viewFile;
        $viewContent = (string) ob_get_clean();

        $content = static function () use ($viewContent): void {
            echo $viewContent;
        };

        require $layoutFile;
    }

    protected function redirect(string $path): void
    {
        $url = (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0)
            ? $path
            : BASE_URL . '/' . ltrim($path, '/');

        header('Location: ' . $url);
        exit;
    }

    protected function json($data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    protected function requireLogin(): void
    {
        Auth::requireLogin();
    }
}
