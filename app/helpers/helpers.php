<?php
/**
 * app/helpers/helpers.php
 * Funções auxiliares globais: escape de saída (XSS), formatação de
 * telefone/CEP/CPF, formatação de datas, etc.
 */

if (!function_exists('e')) {
    /**
     * Escapa uma string para saída segura em HTML (proteção XSS).
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('old')) {
    /** Retorna valor antigo de formulário (após erro de validação), se existir. */
    function old(string $key, $default = '')
    {
        return $_SESSION['old'][$key] ?? $default;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $cleanPath = ltrim($path, '/');
        $url = BASE_URL . '/assets/' . $cleanPath;
        $file = ROOT_PATH . '/public/assets/' . $cleanPath;

        // Força o navegador (e a CDN da Hostinger, que cacheia estáticos por
        // 7 dias) a buscar a versão nova sempre que o arquivo CSS/JS mudar.
        // Preferimos filemtime() (atualiza sozinho a cada deploy), mas SEMPRE
        // colocamos alguma versão na URL — em alguns ambientes de hospedagem
        // is_file()/filemtime() podem falhar silenciosamente (ex: restrição
        // de open_basedir) mesmo com o arquivo sendo servido normalmente pelo
        // servidor web; nesse caso caímos para ASSET_VERSION (config.php),
        // que é atualizada manualmente a cada publicação.
        $version = null;
        if (is_file($file)) {
            $version = @filemtime($file) ?: null;
        }
        if ($version === null && defined('ASSET_VERSION')) {
            $version = ASSET_VERSION;
        }
        if ($version !== null) {
            $url .= '?v=' . $version;
        }

        return $url;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return BASE_URL . '/' . ltrim($path, '/');
    }
}

if (!function_exists('format_phone')) {
    /** Formata telefone brasileiro: (DD) 9XXXX-XXXX ou (DD) XXXX-XXXX */
    function format_phone(?string $phone): string
    {
        if (!$phone) {
            return '';
        }
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
        }
        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
        }
        return $phone;
    }
}

if (!function_exists('format_cpf')) {
    /** Formata CPF: XXX.XXX.XXX-XX */
    function format_cpf(?string $cpf): string
    {
        if (!$cpf) {
            return '';
        }
        $digits = preg_replace('/\D/', '', $cpf);
        if (strlen($digits) !== 11) {
            return $cpf;
        }
        return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
    }
}

if (!function_exists('format_cep')) {
    /** Formata CEP: XXXXX-XXX */
    function format_cep(?string $cep): string
    {
        if (!$cep) {
            return '';
        }
        $digits = preg_replace('/\D/', '', $cep);
        if (strlen($digits) !== 8) {
            return $cep;
        }
        return substr($digits, 0, 5) . '-' . substr($digits, 5);
    }
}

if (!function_exists('format_money')) {
    /** Formata valor decimal para R$ 1.234,56 */
    function format_money($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }
}

if (!function_exists('format_date')) {
    /** Formata data (Y-m-d ou Y-m-d H:i:s) para d/m/Y */
    function format_date(?string $date, bool $withTime = false): string
    {
        if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
            return '-';
        }
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return '-';
        }
        return $withTime ? date('d/m/Y H:i', $timestamp) : date('d/m/Y', $timestamp);
    }
}

if (!function_exists('time_ago')) {
    /** Retorna string relativa simples: "há 2 dias", "há 5 minutos" etc. */
    function time_ago(?string $date): string
    {
        if (!$date) {
            return '-';
        }
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return '-';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'agora mesmo';
        }
        if ($diff < 3600) {
            $m = floor($diff / 60);
            return 'há ' . $m . ' min';
        }
        if ($diff < 86400) {
            $h = floor($diff / 3600);
            return 'há ' . $h . ' hora' . ($h > 1 ? 's' : '');
        }
        $d = floor($diff / 86400);
        return 'há ' . $d . ' dia' . ($d > 1 ? 's' : '');
    }
}

if (!function_exists('status_label')) {
    /** Retorna rótulo amigável (pt-BR) para o status do lead */
    function status_label(?string $status): string
    {
        $labels = [
            'novo'                 => 'Novo',
            'primeiro_contato'     => 'Primeiro Contato',
            'tentando_contato'     => 'Tentando Contato',
            'em_negociacao'        => 'Em Negociação',
            'documentacao'         => 'Documentação',
            'aguardando_cliente'   => 'Aguardando Cliente',
            'aguardando_aprovacao' => 'Aguardando Aprovação',
            'aprovado'             => 'Aprovado',
            'fechado'              => 'Fechado',
            'perdido'              => 'Perdido',
            'sem_interesse'        => 'Sem Interesse',
            'sem_entrada'          => 'Sem Entrada',
            'numero_invalido'      => 'Número Inválido',
            'nao_responde'         => 'Não Responde',
            'bloqueou'             => 'Bloqueou',
            'duplicado'            => 'Duplicado',
        ];

        return $labels[$status] ?? ($status ?: '-');
    }
}

if (!function_exists('status_color')) {
    /** Retorna classe de cor Bootstrap para badge de status */
    function status_color(?string $status): string
    {
        $colors = [
            'novo'                 => 'primary',
            'primeiro_contato'     => 'info',
            'tentando_contato'     => 'warning',
            'em_negociacao'        => 'info',
            'documentacao'         => 'secondary',
            'aguardando_cliente'   => 'warning',
            'aguardando_aprovacao' => 'warning',
            'aprovado'             => 'success',
            'fechado'              => 'success',
            'perdido'              => 'danger',
            'sem_interesse'        => 'danger',
            'sem_entrada'          => 'danger',
            'numero_invalido'      => 'dark',
            'nao_responde'         => 'secondary',
            'bloqueou'             => 'dark',
            'duplicado'            => 'dark',
        ];

        return $colors[$status] ?? 'secondary';
    }
}

if (!function_exists('source_label')) {
    function source_label(?string $source): string
    {
        $labels = [
            'facebook'         => 'Facebook',
            'instagram'        => 'Instagram',
            'google'           => 'Google Ads',
            'indicacao'        => 'Indicação',
            'site'             => 'Site',
            'landing_page'     => 'Landing Page',
            'organico'         => 'Orgânico',
            'cadastro_manual'  => 'Cadastro Manual',
            'whatsapp'         => 'WhatsApp',
            'importacao_csv'   => 'Importação CSV',
            'api'              => 'API',
            'webhook'          => 'Webhook',
            'outros'           => 'Outros',
        ];
        return $labels[$source] ?? ($source ?: '-');
    }
}

if (!function_exists('interest_label')) {
    function interest_label(?string $interest): string
    {
        $labels = [
            'imovel'        => 'Imóvel',
            'veiculo'       => 'Veículo',
            'caminhao'      => 'Caminhão',
            'moto'          => 'Moto',
            'maquinario'    => 'Maquinário',
            'agronegocio'   => 'Agronegócio',
            'construcao'    => 'Construção',
            'capital_giro'  => 'Capital de Giro',
            'investimento'  => 'Investimento',
            'quitacao'      => 'Quitação',
            'outros'        => 'Outros',
        ];
        return $labels[$interest] ?? ($interest ?: '-');
    }
}

if (!function_exists('brazilian_states')) {
    /** Lista de UFs do Brasil */
    function brazilian_states(): array
    {
        return [
            'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
            'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
            'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
            'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
            'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
        ];
    }
}

if (!function_exists('log_activity')) {
    /**
     * Registra uma linha na tabela activity_log (auditoria).
     * Usado em ações críticas: login, logout, criar/editar/excluir lead,
     * mudança de status no kanban, CRUD de usuários/configurações etc.
     */
    function log_activity(string $action, ?string $details = null): void
    {
        require_once APP_PATH . '/models/ActivityLog.php';
        try {
            $model = new ActivityLog();
            $model->add(Auth::id(), $action, $details, $_SERVER['REMOTE_ADDR'] ?? null);
        } catch (Throwable $e) {
            // Nunca deixa uma falha de log quebrar o fluxo principal da aplicação
            error_log('Falha ao registrar activity_log: ' . $e->getMessage());
        }
    }
}

if (!function_exists('render_pagination')) {
    /**
     * Renderiza a navegação de paginação (Bootstrap "Anterior / 1 2 3 / Próxima"),
     * preservando a querystring de filtros atuais ao trocar de página. Extraído
     * como helper reaproveitável (Fase 5) a partir do padrão já usado em
     * app/views/leads/index.php e app/views/logs/index.php, para não duplicar
     * o mesmo bloco de HTML em cada nova listagem paginada.
     *
     * @param int    $page        Página atual (1-based)
     * @param int    $totalPages  Total de páginas
     * @param string $baseUrl     URL absoluta da listagem (ex: url('usuarios'))
     * @param array  $queryParams Filtros/ordenação atuais a preservar na querystring
     */
    function render_pagination(int $page, int $totalPages, string $baseUrl, array $queryParams = []): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $queryParams = array_filter($queryParams, fn($v) => $v !== '' && $v !== null);

        $html = '<nav class="mt-3"><ul class="pagination justify-content-center">';
        for ($p = 1; $p <= $totalPages; $p++) {
            $query = array_merge($queryParams, ['page' => $p]);
            $url = e($baseUrl . '?' . http_build_query($query));
            $active = $p === $page ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $url . '">' . $p . '</a></li>';
        }
        $html .= '</ul></nav>';

        return $html;
    }
}

if (!function_exists('days_since_contact_label')) {
    /**
     * Rótulo relativo do último contato de um lead (Fase 7 - auditoria UX),
     * reaproveitado na listagem de Leads e no card do Kanban. Retorna
     * "nunca contatado" quando last_contact_at é NULL.
     */
    function days_since_contact_label(?string $lastContactAt): string
    {
        if (!$lastContactAt) {
            return 'nunca contatado';
        }
        $timestamp = strtotime($lastContactAt);
        if (!$timestamp) {
            return 'nunca contatado';
        }
        $days = (int) floor((time() - $timestamp) / 86400);
        if ($days <= 0) {
            return 'hoje';
        }
        if ($days === 1) {
            return 'há 1 dia';
        }
        return 'há ' . $days . ' dias';
    }
}

if (!function_exists('days_since_contact_is_stale')) {
    /** true quando o lead nunca foi contatado ou o último contato passou do limiar (padrão 5 dias) */
    function days_since_contact_is_stale(?string $lastContactAt, int $thresholdDays = 5): bool
    {
        if (!$lastContactAt) {
            return true;
        }
        $timestamp = strtotime($lastContactAt);
        if (!$timestamp) {
            return true;
        }
        $days = (int) floor((time() - $timestamp) / 86400);
        return $days > $thresholdDays;
    }
}

if (!function_exists('score_badge_class')) {
    /** Classe de cor Bootstrap para o badge de lead_score: verde >=70, amarelo 40-69, cinza <40 */
    function score_badge_class($score): string
    {
        $score = (int) $score;
        if ($score >= 70) {
            return 'success';
        }
        if ($score >= 40) {
            return 'warning';
        }
        return 'secondary';
    }
}

if (!function_exists('temperature_label')) {
    /** Rótulo amigável (pt-BR) para a temperatura do lead */
    function temperature_label(?string $temperature): string
    {
        $labels = [
            'frio'         => 'Frio',
            'morno'        => 'Morno',
            'quente'       => 'Quente',
            'muito_quente' => 'Muito Quente',
        ];
        return $labels[$temperature] ?? '-';
    }
}

if (!function_exists('temperature_badge_class')) {
    /**
     * Classe CSS (ver public/assets/css/app.css, seção "Temperatura do lead")
     * para o badge de temperatura: frio=azul claro, morno=amarelo, quente=laranja,
     * muito_quente=vermelho.
     */
    function temperature_badge_class(?string $temperature): string
    {
        $classes = [
            'frio'         => 'tc-badge-temp-frio',
            'morno'        => 'tc-badge-temp-morno',
            'quente'       => 'tc-badge-temp-quente',
            'muito_quente' => 'tc-badge-temp-muito-quente',
        ];
        return $classes[$temperature] ?? 'tc-badge-temp-none';
    }
}

if (!function_exists('chat_date_label')) {
    /** Rótulo de divisor de data para bolhas de chat/atendimento: "Hoje", "Ontem" ou d/m/Y. */
    function chat_date_label(?string $date): string
    {
        if (!$date) {
            return '';
        }
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return '';
        }
        $day = date('Y-m-d', $timestamp);
        if ($day === date('Y-m-d')) {
            return 'Hoje';
        }
        if ($day === date('Y-m-d', strtotime('-1 day'))) {
            return 'Ontem';
        }
        return date('d/m/Y', $timestamp);
    }
}

if (!function_exists('flash')) {
    /** Define ou recupera mensagem flash de sessão (uma única exibição) */
    function flash(string $key, ?string $message = null)
    {
        Auth::start();
        if ($message !== null) {
            $_SESSION['flash'][$key] = $message;
            return null;
        }

        $value = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $value;
    }
}
