<?php

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/CallRecord.php';
require_once APP_PATH . '/models/IntegrationCredential.php';
require_once APP_PATH . '/services/Calls/CallDashboardMetrics.php';
require_once APP_PATH . '/services/Calls/CallSyncService.php';
require_once APP_PATH . '/services/Calls/CallRecordingArchive.php';
require_once APP_PATH . '/services/Calls/CallLinkRepairService.php';
require_once APP_PATH . '/services/Calls/CallAudioRange.php';

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

    private function config(): IntegrationConfig
    {
        return new IntegrationConfig(
            new IntegrationCredential(),
            SecretVault::fromFile(ROOT_PATH . '/config/integration.key'),
            IntegrationConfig::legacyEnvironment(ROOT_PATH . '/ligacao/.env')
        );

    }

    private function service(): CallSyncService
    {
        return new CallSyncService(Database::getInstance(), $this->config());
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
            $service = $this->service();
            if ($this->input('force', '0') === '1' || ($call['analysis_status'] ?? '') !== 'completed') {
                $service->reanalyze((int) $id);
            }
            echo json_encode(['success' => true, 'redirect' => url('ligacoes/' . $id)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            CallFailure::log('analyze', $error);
            echo json_encode(['success'=>false,'message'=>CallFailure::message($error)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        $call=$this->calls->detail((int)$id);
        if (!$call || !$this->allowed($call)) { http_response_code($call ? 403 : 404); return; }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        set_time_limit(180);
        try {
            $call=CallRecordingArchive::cached($call,new CallMediaStorage(STORAGE_PATH))
                ?? (new CallRecordingArchive(Database::getInstance(),$this->config()))->archive((int)$id);
        } catch (Throwable $error) {
            CallFailure::log('audio',$error);
            if (in_array(CallFailure::code($error),['RECORDING_STORE_FAILED','RECORDING_STORAGE_UNAVAILABLE','TEMP_FILE_FAILED','TEMP_DIRECTORY_FAILED'],true)) {
                $this->streamRemote($call);
                return;
            }
            $this->json(['success'=>false,'message'=>CallFailure::message($error)],502);
            return;
        }
        $path=$call['absolute_recording_path'];
        $size=(int)filesize($path);
        try { [$start,$end,$status]=CallAudioRange::parse($_SERVER['HTTP_RANGE']??null,$size); }
        catch (RangeException) { http_response_code(416); header('Content-Range: bytes */'.$size); return; }
        $handle=@fopen($path,'rb');
        if (!$handle) { http_response_code(503); return; }
        while (ob_get_level()>0) ob_end_clean();
        http_response_code($status);
        if ($status===206) header("Content-Range: bytes {$start}-{$end}/{$size}");
        header('Content-Type: '.$call['recording_mime']);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: '.($end-$start+1));
        fseek($handle,$start);
        $left=$end-$start+1;
        while ($left>0 && !feof($handle)) {
            $chunk=fread($handle,min(8192,$left));
            if ($chunk===false || $chunk==='') break;
            echo $chunk;
            $left-=strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    private function streamRemote(array $call): void
    {
        $started=false;
        try {
            $remote=(new CallsApiClient($this->config()))->find((string)$call['external_id']);
            $url=trim((string)($remote['record_url']??''));
            if ($url==='') throw new RuntimeException('RECORDING_NOT_AVAILABLE');
            CallMediaTransport::http(STORAGE_PATH.'/calls-tmp')->stream(
                $url, [], $_SERVER['HTTP_RANGE']??null,
                function (int $status,array $headers) use (&$started): void {
                    if ($status<200 || $status>=300) return;
                    $mime=strtolower((string)($headers['content-type']??''));
                    if (!str_starts_with($mime,'audio/') && !str_starts_with($mime,'application/octet-stream')) throw new RuntimeException('RECORDING_INVALID_CONTENT');
                    while (ob_get_level()>0) ob_end_clean();
                    $started=true;
                    http_response_code($status);
                    header('Cache-Control: private, max-age=300');
                    header('X-Content-Type-Options: nosniff');
                    foreach (['content-type'=>'Content-Type','content-length'=>'Content-Length','content-range'=>'Content-Range','accept-ranges'=>'Accept-Ranges'] as $source=>$target) {
                        if (isset($headers[$source])) header($target.': '.str_replace(["\r","\n"],'',(string)$headers[$source]));
                    }
                },
                static function (string $chunk): void { echo $chunk; flush(); }
            );
        } catch (Throwable $error) {
            CallFailure::log('stream_audio',$error);
            if (!$started) $this->json(['success'=>false,'message'=>CallFailure::message($error)],502);
        }
    }
}
