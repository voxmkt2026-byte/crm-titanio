<?php
/**
 * app/core/Router.php
 * Router simples baseado em array de rotas (método + path => Controller@action).
 * Suporta parâmetros dinâmicos no formato /leads/{id}.
 *
 * Rotas públicas (sem login): este Router NÃO aplica nenhum middleware de
 * autenticação global — quem decide se uma rota exige login é o próprio
 * método do Controller, chamando (ou não) $this->requireLogin() como
 * primeira linha da action (ver app/core/Controller.php). Isso já permite
 * registrar rotas públicas normalmente com get()/post(): basta o Controller
 * correspondente NÃO chamar requireLogin(). É o padrão usado pelo webhook
 * público de captação de leads (Fase 3, ver WebhookController), que precisa
 * responder sem sessão autenticada (o Meta/Google chamam a URL diretamente).
 * Rotas públicas devem sempre validar a requisição por outro meio (token
 * secreto via query string, verify_token etc.) já que não há CSRF/sessão.
 */

class Router
{
    private array $routes = [];

    public function get(string $path, string $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, string $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function options(string $path, string $handler): void
    {
        $this->addRoute('OPTIONS', $path, $handler);
    }

    private function addRoute(string $method, string $path, string $handler): void
    {
        $this->routes[] = [
            'method'  => $method,
            'path'    => trim($path, '/'),
            'handler' => $handler,
        ];
    }

    /**
     * Converte um path de rota (com {param}) em regex e retorna também
     * a lista de nomes de parâmetros na ordem em que aparecem.
     */
    private function compile(string $path): array
    {
        $paramNames = [];
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($matches) use (&$paramNames) {
            $paramNames[] = $matches[1];
            return '([^/]+)';
        }, $path);

        $regex = '#^' . $pattern . '$#';
        return [$regex, $paramNames];
    }

    public function dispatch(string $requestMethod, string $requestUri): void
    {
        // Remove query string e barras extras
        $uri = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $uri = trim($uri, '/');

        // Remove o prefixo do subdiretório público, se existir (ex: /leads/public)
        $basePath = trim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/');
        if ($basePath !== '' && strpos($uri, $basePath) === 0) {
            $uri = trim(substr($uri, strlen($basePath)), '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            [$regex, $paramNames] = $this->compile($route['path']);

            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches);
                // Parâmetros de rota podem conter caracteres que precisam ser
                // percent-encoded no navegador. É o caso do remoteJid do
                // WhatsApp (5511...@s.whatsapp.net) usado pelo Atendimento:
                // sem decodificar, o controller recebia "%40" em vez de "@"
                // e não encontrava a conversa para responder.
                $matches = array_map('rawurldecode', $matches);
                $params = array_combine($paramNames, $matches) ?: [];

                $this->callHandler($route['handler'], $params);
                return;
            }
        }

        // Nenhuma rota encontrada
        http_response_code(404);
        $viewFile = APP_PATH . '/views/errors/404.php';
        if (file_exists($viewFile)) {
            require $viewFile;
        } else {
            echo '404 - Página não encontrada';
        }
    }

    private function callHandler(string $handler, array $params): void
    {
        [$controllerName, $action] = explode('@', $handler);

        $controllerFile = APP_PATH . '/controllers/' . $controllerName . '.php';
        if (!file_exists($controllerFile)) {
            http_response_code(500);
            die("Controller não encontrado: {$controllerName}");
        }

        require_once $controllerFile;

        if (!class_exists($controllerName)) {
            http_response_code(500);
            die("Classe de controller não encontrada: {$controllerName}");
        }

        $controller = new $controllerName();

        if (!method_exists($controller, $action)) {
            http_response_code(500);
            die("Ação não encontrada: {$controllerName}@{$action}");
        }

        call_user_func_array([$controller, $action], $params);
    }
}
