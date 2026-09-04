<?php

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/CallRecord.php';
require_once APP_PATH . '/models/IntegrationCredential.php';
require_once APP_PATH . '/services/Calls/CallDashboardMetrics.php';
require_once APP_PATH . '/services/Calls/CallSyncService.php';
require_once APP_PATH . '/services/Calls/CallRecordingArchive.php';
require_once APP_PATH . '/services/Calls/CallLinkRepairService.php';

final class CallController extends Controller
{
    private CallRecord $calls;

    public function __construct()
    {
        $this->calls = new CallRecord();
    }

    private function allowed(array $call): bool
    {
        return Auth::can('calls.view_all')
            || (Auth::can('calls.view_own') && (int) ($call['assigned_to'] ?? 0) === (int) Auth::id());
    }

    private function service(): CallSyncService
    {
        $config = new IntegrationConfig(
            new IntegrationCredential(),
            SecretVault::fromFile(ROOT_PATH . '/config/integration.key'),
            IntegrationConfig::legacyEnvironment(ROOT_PATH . '/ligacao/.env')
        );
        return new CallSyncService(Database::getInstance(), $config);
    }

    public function index(): void
    {
        $this->requireLogin();
        if (!Auth::can('calls.view_all') && !Auth::can('calls.view_own')) {
            http_response_code(403);
            return;
        }
        try {
            (new CallLinkRepairService(Database::getInstance()))->repairUnmatched(2000);
        } catch (Throwable $error) {
            error_log('CallController::repairUnmatched ' . $error->getMessage());
        }
        $all = Auth::can('calls.view_all');
        $filters = [
            'number' => trim((string) $this->input('number', '')),
            'status' => trim((string) $this->input('status', '')),
        ];
        $result = $this->calls->search((int) Auth::id(), $all, $filters, (int) $this->input('page', 1));
        $metrics = (new CallDashboardMetrics(Database::getInstance()))->summary($all ? null : (int) Auth::id());
        $this->view('calls/index', [
            'pageTitle' => 'Ligações',
            'calls' => $result['items'],
            'filters' => $filters,
            'pagination' => ['page' => $result['page'], 'pages' => $result['pages'], 'total' => $result['total']],
            'metrics' => $metrics,
            'syncState' => $this->calls->syncState(),
        ]);
    }

    public function show(string $id): void
    {
        $this->requireLogin();
        $call = $this->calls->detail((int) $id);
        if (!$call) {
            http_response_code(404);
            return;
        }
        if (!$this->allowed($call)) {
            http_response_code(403);
            return;
        }
        $this->view('calls/show', ['pageTitle' => 'Análise da ligação', 'call' => $call]);
    }

    /** Compatibilidade com formulários de versões anteriores do pacote. */
    public function reanalyze(string $id): void
    {
        $this->analyze($id);
    }

    public function analyze(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();
        $call = $this->calls->detail((int) $id);
        if (!$call) {
            $this->json(['success' => false, 'message' => 'Ligação não encontrada.'], 404);
        }
        if (!$this->allowed($call) || !Auth::can('calls.reanalyze')) {
            $this->json(['success' => false, 'message' => 'Sem permissão para analisar esta ligação.'], 403);
        }
        $externalId = (string) ($call['external_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $externalId)) {
            $this->json(['success' => false, 'message' => 'Identificador da ligação inválido. Sincronize novamente.'], 422);
        }

        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        set_time_limit(300);
        ignore_user_abort(true);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        echo str_repeat(' ', 4096);
        @ob_flush();
        flush();

        try {
            require_once ROOT_PATH . '/ligacao/bootstrap.php';
            app_factory()->analysis()->run($externalId, $this->input('force', '0') === '1');
            $this->service()->importCachedAnalysis((int) $id, ROOT_PATH . '/ligacao/storage/analyses');
            echo json_encode(['success' => true, 'redirect' => url('ligacoes/' . $id)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            error_log('CallController::analyze ' . $error->getMessage());
            $code = method_exists($error, 'publicCode') ? (string) $error->publicCode() : $error->getMessage();
            $messages = [
                'RECORDING_NOT_AVAILABLE' => 'Esta ligação não possui gravação disponível.',
                'AI_NOT_CONFIGURED' => 'Configure a chave da IA utilizada pelo módulo de ligações.',
                'AI_RATE_LIMIT' => 'A IA atingiu o limite temporário. Tente novamente em alguns minutos.',
                'AI_ANALYSIS_FAILED' => 'A IA não conseguiu concluir o relatório desta gravação.',
                'API4COM_AUTH_FAILED' => 'O token da Api4Com foi rejeitado.',
                'CALL_NOT_FOUND' => 'A ligação não foi encontrada na Api4Com.',
            ];
            echo json_encode(['success' => false, 'message' => $messages[$code] ?? 'Não foi possível concluir a análise. Consulte o log PHP.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    public function importAnalysis(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();
        $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        $call = $this->calls->detail((int) $id);
        if (!$call) {
            if ($ajax) $this->json(['success' => false, 'message' => 'Ligação não encontrada.'], 404);
            http_response_code(404);
            return;
        }
        if (!$this->allowed($call) || !Auth::can('calls.reanalyze')) {
            if ($ajax) $this->json(['success' => false, 'message' => 'Sem permissão para analisar esta ligação.'], 403);
            http_response_code(403);
            return;
        }

        try {
            $this->service()->importCachedAnalysis((int) $id, ROOT_PATH . '/ligacao/storage/analyses');
            if ($ajax) $this->json(['success' => true, 'redirect' => url('ligacoes/' . $id)]);
            flash('success', 'Análise importada com sucesso.');
        } catch (Throwable $error) {
            error_log('CallController::importAnalysis ' . $error->getMessage());
            $messages = [
                'ANALYSIS_CACHE_MISSING' => 'A análise terminou, mas o relatório ainda não foi localizado. Tente novamente.',
                'ANALYSIS_CACHE_INVALID' => 'O relatório gerado está inválido. Gere a análise novamente.',
                'CALL_NOT_FOUND' => 'Ligação não encontrada.',
            ];
            $message = $messages[$error->getMessage()] ?? 'Não foi possível importar o relatório da análise.';
            if ($ajax) $this->json(['success' => false, 'message' => $message], 422);
            flash('error', $message);
        }
        $this->redirect('ligacoes/' . $id);
    }

    public function audio(string $id): void
    {
        $this->requireLogin();
        $call = $this->calls->detail((int) $id);
        if (!$call || !$this->allowed($call)) {
            http_response_code($call ? 403 : 404);
            return;
        }
        if (($call['recording_status'] ?? '') !== 'stored') {
            try {
                $config = new IntegrationConfig(
                    new IntegrationCredential(),
                    SecretVault::fromFile(ROOT_PATH . '/config/integration.key'),
                    IntegrationConfig::legacyEnvironment(ROOT_PATH . '/ligacao/.env')
                );
                $call = (new CallRecordingArchive(Database::getInstance(), $config))->archive((int) $id);
            } catch (Throwable $error) {
                error_log('CallController::audio ' . $error->getMessage());
                http_response_code(404);
                return;
            }
        }

        $relative = (string) ($call['recording_path'] ?? '');
        $root = realpath(STORAGE_PATH . '/calls');
        $path = realpath(STORAGE_PATH . '/calls/' . ltrim(str_replace('\\', '/', $relative), '/'));
        if (!$root || !$path || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) || !is_file($path)) {
            http_response_code(404);
            return;
        }
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $match)) {
            $start = $match[1] === '' ? 0 : (int) $match[1];
            $end = $match[2] === '' ? $end : min($end, (int) $match[2]);
            if ($start > $end) {
                http_response_code(416);
                return;
            }
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        }
        header('Content-Type: ' . (string) ($call['recording_mime'] ?: 'audio/mpeg'));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . ($end - $start + 1));
        $handle = fopen($path, 'rb');
        fseek($handle, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($handle)) {
            $chunk = fread($handle, min(8192, $left));
            if ($chunk === false) break;
            echo $chunk;
            $left -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }
}
