<?php
/**
 * app/controllers/ImportController.php
 * Importação em lote de leads via CSV (Fase 4): upload -> mapeamento de
 * colunas -> processamento síncrono (criação ou atualização por duplicidade)
 * -> relatório com resumo e erros/avisos linha a linha.
 *
 * Sem Composer/libs externas: leitura nativa via fgetcsv(). Suporte a
 * .xlsx binário fica para uma fase futura (ver README.md) — o usuário deve
 * exportar a planilha como CSV a partir do Excel/Google Sheets.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/LeadHistory.php';
require_once APP_PATH . '/models/Import.php';
require_once APP_PATH . '/models/ImportError.php';
require_once APP_PATH . '/models/LeadScore.php';

class ImportController extends Controller
{
    /** Limite de linhas processadas por importação (síncrona, sem fila/cron) */
    private const MAX_ROWS = 2000;

    /** Tamanho máximo aceito para o arquivo CSV (bytes) */
    private const MAX_FILE_SIZE = 15 * 1024 * 1024; // 15 MB

    private Lead $leadModel;
    private LeadHistory $historyModel;
    private Import $importModel;
    private ImportError $importErrorModel;
    private LeadScore $scoreModel;

    /** Campos do lead disponíveis para mapeamento das colunas do CSV */
    private array $fieldOptions = [
        'name'               => 'Nome',
        'ddd'                => 'DDD',
        'phone'              => 'Telefone',
        'whatsapp'           => 'WhatsApp',
        'email'              => 'E-mail',
        'cpf'                => 'CPF',
        'city'               => 'Cidade',
        'state'              => 'Estado (UF)',
        'zipcode'            => 'CEP',
        'source'             => 'Origem',
        'campaign'           => 'Campanha',
        'adset'              => 'Conjunto de anúncios',
        'ad'                 => 'Anúncio',
        'utm_source'         => 'UTM Source',
        'utm_medium'         => 'UTM Medium',
        'utm_campaign'       => 'UTM Campaign',
        'utm_content'        => 'UTM Content',
        'utm_term'           => 'UTM Term',
        'interest'           => 'Interesse',
        'desired_value'      => 'Valor desejado',
        'has_down_payment'   => 'Possui entrada (sim/não)',
        'down_payment_value' => 'Valor da entrada',
        'income_range'       => 'Faixa de renda',
        'profession'         => 'Profissão',
        'company'            => 'Empresa',
        'status'             => 'Status',
        'notes'              => 'Observações',
        'internal_notes'     => 'Observações internas',
    ];

    public function __construct()
    {
        $this->leadModel = new Lead();
        $this->historyModel = new LeadHistory();
        $this->importModel = new Import();
        $this->importErrorModel = new ImportError();
        $this->scoreModel = new LeadScore();
    }

    /** Recalcula o lead_score automático (Fase 2) de um lead recém-criado/atualizado pela importação */
    private function recalculateScore(int $leadId): void
    {
        // Lógica compartilhada em LeadScore::recalculateForLead() (Fase 5),
        // também reaproveitada por LeadController e AgendaController.
        $this->scoreModel->recalculateForLead($leadId);
    }

    /** Tela 1: upload do arquivo CSV */
    public function index(): void
    {
        $this->requireLogin();

        $this->view('import/index', [
            'pageTitle'      => 'Importar Leads',
            'recentImports'  => $this->importModel->recent(10),
        ]);
    }

    /** Recebe o upload, valida, guarda em storage/imports/ e mostra o mapeamento de colunas */
    public function preview(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        if (empty($_FILES['csv_file']) || ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Selecione um arquivo CSV válido para importar.');
            $this->redirect('importar');
            return;
        }

        $file = $_FILES['csv_file'];
        $originalName = (string) $file['name'];
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            flash('error', 'Formato não suportado. Envie um arquivo .csv (exporte do Excel/Google Sheets como CSV).');
            $this->redirect('importar');
            return;
        }

        if ($file['size'] <= 0 || $file['size'] > self::MAX_FILE_SIZE) {
            flash('error', 'Arquivo vazio ou maior que o limite de 15MB.');
            $this->redirect('importar');
            return;
        }

        $this->ensureImportsDir();
        $storedName = bin2hex(random_bytes(16)) . '.csv';
        $destination = STORAGE_PATH . '/imports/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            flash('error', 'Não foi possível salvar o arquivo enviado. Tente novamente.');
            $this->redirect('importar');
            return;
        }

        $handle = fopen($destination, 'r');
        if (!$handle) {
            flash('error', 'Não foi possível ler o arquivo enviado.');
            $this->redirect('importar');
            return;
        }

        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = $this->detectDelimiter((string) $firstLine);

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false || $header === null) {
            fclose($handle);
            unlink($destination);
            flash('error', 'O arquivo CSV está vazio ou não pôde ser interpretado.');
            $this->redirect('importar');
            return;
        }
        // Remove BOM UTF-8 do primeiro cabeçalho, se presente
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $previewRows = [];
        $totalRows = 0;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $totalRows++;
            if (count($previewRows) < 5) {
                $previewRows[] = $row;
            }
        }
        fclose($handle);

        $guessedMapping = [];
        foreach ($header as $index => $label) {
            $guessedMapping[$index] = $this->guessField((string) $label);
        }

        $this->view('import/mapping', [
            'pageTitle'        => 'Importar Leads - Mapeamento de colunas',
            'storedFilename'   => $storedName,
            'originalFilename' => $originalName,
            'delimiterToken'   => $this->delimiterToToken($delimiter),
            'header'           => $header,
            'previewRows'      => $previewRows,
            'totalRows'        => $totalRows,
            'fieldOptions'     => $this->fieldOptions,
            'guessedMapping'   => $guessedMapping,
            'maxRows'          => self::MAX_ROWS,
        ]);
    }

    /** Executa a importação de fato a partir do mapeamento escolhido pelo usuário */
    public function processar(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $storedName = (string) $this->input('stored_filename', '');
        $originalName = (string) $this->input('original_filename', 'importacao.csv');
        $mapping = $this->input('mapping', []);
        $delimiter = $this->tokenToDelimiter((string) $this->input('delimiter', 'comma'));

        if (!preg_match('/^[a-f0-9]{32}\.csv$/', $storedName)) {
            flash('error', 'Arquivo de importação inválido ou expirado. Envie novamente.');
            $this->redirect('importar');
            return;
        }

        $path = STORAGE_PATH . '/imports/' . $storedName;
        if (!is_file($path)) {
            flash('error', 'Arquivo de importação não encontrado (pode ter expirado). Envie novamente.');
            $this->redirect('importar');
            return;
        }

        if (!is_array($mapping) || empty(array_filter($mapping, fn($f) => $f !== '' && $f !== 'ignorar'))) {
            flash('error', 'Mapeie pelo menos uma coluna antes de processar a importação.');
            $this->redirect('importar');
            return;
        }

        $importId = $this->importModel->create(Auth::id(), $originalName, 0);

        $createdCount = 0;
        $updatedCount = 0;
        $errorCount = 0;
        $processedCount = 0;
        $rowNumber = 1; // linha 1 = cabeçalho

        $handle = fopen($path, 'r');
        if (!$handle) {
            $this->importModel->markFailed($importId);
            flash('error', 'Não foi possível reabrir o arquivo para processamento.');
            $this->redirect('importar');
            return;
        }

        // Descarta a linha de cabeçalho (o mapeamento já foi definido por posição de coluna)
        fgetcsv($handle, 0, $delimiter);

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            if ($this->isEmptyRow($row)) {
                continue;
            }

            if ($processedCount >= self::MAX_ROWS) {
                $this->importErrorModel->add(
                    $importId,
                    $rowNumber,
                    null,
                    'Limite de ' . self::MAX_ROWS . ' linhas por importação atingido; as linhas restantes não foram processadas.'
                );
                $errorCount++;
                break;
            }

            $processedCount++;

            try {
                $mapped = $this->applyMapping($row, $mapping);

                if (empty(array_filter($mapped, fn($v) => $v !== null && $v !== ''))) {
                    continue; // linha sem nenhum dado útil após o mapeamento
                }

                $warnings = $this->validateRow($mapped);

                $normalized = $this->normalizeRow($mapped);

                $duplicate = $this->leadModel->findDuplicate(
                    $normalized['phone'] ?? null,
                    $normalized['whatsapp'] ?? null,
                    $normalized['cpf'] ?? null,
                    $normalized['email'] ?? null
                );

                if ($duplicate) {
                    // Campos vazios do CSV mantêm o valor atual; só sobrescreve o que veio preenchido
                    $updateData = array_filter($normalized, fn($v) => $v !== null && $v !== '');
                    if (!empty($updateData)) {
                        $this->leadModel->update((int) $duplicate['id'], $updateData);
                        $this->historyModel->add(
                            (int) $duplicate['id'],
                            Auth::id(),
                            'dado_alterado',
                            'Dados atualizados via importação CSV (' . $originalName . ', linha ' . $rowNumber . ').'
                        );
                    }
                    $this->recalculateScore((int) $duplicate['id']);
                    $updatedCount++;
                } else {
                    $normalized['source'] = 'importacao_csv';
                    $result = $this->leadModel->createWithLeadCode($normalized);
                    $this->historyModel->add(
                        $result['id'],
                        Auth::id(),
                        'criacao',
                        'Lead criado via importação CSV (' . $originalName . ', linha ' . $rowNumber . '). Código: ' . $result['lead_code'] . '.'
                    );
                    $this->recalculateScore($result['id']);
                    $createdCount++;
                }

                if (!empty($warnings)) {
                    $this->importErrorModel->add($importId, $rowNumber, $mapped, 'Aviso: ' . implode(' ', $warnings));
                    $errorCount++;
                }
            } catch (Throwable $e) {
                error_log('ImportController::processar - linha ' . $rowNumber . ': ' . $e->getMessage());
                $this->importErrorModel->add($importId, $rowNumber, $row, 'Erro ao processar a linha: ' . $e->getMessage());
                $errorCount++;
            }
        }

        fclose($handle);
        @unlink($path);

        $this->importModel->finish($importId, $processedCount, $createdCount, $updatedCount, $errorCount);

        log_activity(
            'leads_importados',
            'Importação #' . $importId . ' (' . $originalName . '): ' . $createdCount . ' criados, ' .
            $updatedCount . ' atualizados, ' . $errorCount . ' com erro/aviso.'
        );

        flash('success', 'Importação concluída: ' . $createdCount . ' lead(s) criado(s), ' . $updatedCount . ' atualizado(s).');
        $this->redirect('importar/relatorio/' . $importId);
    }

    /** Relatório de uma importação específica */
    public function relatorio(string $id): void
    {
        $this->requireLogin();

        $import = $this->importModel->findWithUser((int) $id);
        if (!$import) {
            flash('error', 'Importação não encontrada.');
            $this->redirect('importar');
            return;
        }

        $this->view('import/report', [
            'pageTitle' => 'Relatório de Importação',
            'import'    => $import,
            'errors'    => $this->importErrorModel->forImport((int) $id),
        ]);
    }

    /** Exporta os erros/avisos de uma importação em CSV */
    public function exportarErros(string $id): void
    {
        $this->requireLogin();

        $import = $this->importModel->findWithUser((int) $id);
        if (!$import) {
            flash('error', 'Importação não encontrada.');
            $this->redirect('importar');
            return;
        }

        $errors = $this->importErrorModel->forImport((int) $id);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="importacao-' . (int) $id . '-erros.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Linha', 'Mensagem', 'Dados da linha'], ';');
        foreach ($errors as $err) {
            fputcsv($out, [$err['row_num'], $err['error_message'], $err['raw_data']], ';');
        }
        fclose($out);
        exit;
    }

    // ---------------------------------------------------------------
    // Helpers internos
    // ---------------------------------------------------------------

    /** Converte o caractere delimitador em um token seguro para tráfego via campo hidden de formulário */
    private function delimiterToToken(string $delimiter): string
    {
        $map = [',' => 'comma', ';' => 'semicolon', "\t" => 'tab'];
        return $map[$delimiter] ?? 'comma';
    }

    private function tokenToDelimiter(string $token): string
    {
        $map = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"];
        return $map[$token] ?? ',';
    }

    private function ensureImportsDir(): void
    {
        $dir = STORAGE_PATH . '/imports';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function isEmptyRow(?array $row): bool
    {
        if (!$row) {
            return true;
        }
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    /** Detecta se o CSV usa vírgula, ponto e vírgula ou tab como separador */
    private function detectDelimiter(string $sampleLine): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0];
        foreach (array_keys($candidates) as $delim) {
            $candidates[$delim] = substr_count($sampleLine, $delim);
        }
        arsort($candidates);
        $best = array_key_first($candidates);
        return $candidates[$best] > 0 ? $best : ',';
    }

    /** Tenta adivinhar o campo do lead a partir do texto do cabeçalho da coluna */
    private function guessField(string $headerLabel): string
    {
        $normalized = $this->stripAccents(mb_strtolower(trim($headerLabel)));

        $guesses = [
            'name'             => ['nome'],
            'ddd'              => ['ddd'],
            'whatsapp'         => ['whats'],
            'phone'            => ['telefone', 'celular', 'fone', 'tel'],
            'email'            => ['email', 'e-mail'],
            'cpf'              => ['cpf'],
            'city'             => ['cidade', 'municipio'],
            'state'            => ['estado', ' uf', 'uf'],
            'zipcode'          => ['cep'],
            'source'           => ['origem', 'fonte'],
            'campaign'         => ['campanha'],
            'interest'         => ['interesse'],
            'desired_value'    => ['valor desejado', 'valor'],
            'has_down_payment' => ['possui entrada', 'tem entrada'],
            'income_range'     => ['renda'],
            'profession'       => ['profiss'],
            'company'          => ['empresa'],
            'status'           => ['status', 'situacao'],
            'notes'            => ['observa', 'obs'],
        ];

        foreach ($guesses as $field => $needles) {
            foreach ($needles as $needle) {
                if (strpos($normalized, $needle) !== false) {
                    return $field;
                }
            }
        }

        return 'ignorar';
    }

    private function stripAccents(string $value): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ];
        return strtr($value, $map);
    }

    /** Aplica o mapeamento coluna->campo em uma linha bruta do CSV */
    private function applyMapping(array $row, array $mapping): array
    {
        $data = [];
        foreach ($mapping as $columnIndex => $field) {
            if ($field === '' || $field === 'ignorar' || !isset($this->fieldOptions[$field])) {
                continue;
            }
            $value = trim((string) ($row[(int) $columnIndex] ?? ''));
            $data[$field] = $value !== '' ? $value : null;
        }
        return $data;
    }

    /** Validações leves (não bloqueiam a importação, apenas geram avisos) */
    private function validateRow(array $data): array
    {
        $warnings = [];

        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $warnings[] = 'E-mail com formato inválido.';
        }
        if (!empty($data['phone'])) {
            $digits = preg_replace('/\D/', '', $data['phone']);
            if (strlen($digits) < 10 || strlen($digits) > 11) {
                $warnings[] = 'Telefone com formato inválido.';
            }
        }
        if (!empty($data['whatsapp'])) {
            $digits = preg_replace('/\D/', '', $data['whatsapp']);
            if (strlen($digits) < 10 || strlen($digits) > 11) {
                $warnings[] = 'WhatsApp com formato inválido.';
            }
        }
        if (!empty($data['cpf'])) {
            $digits = preg_replace('/\D/', '', $data['cpf']);
            if (strlen($digits) !== 11) {
                $warnings[] = 'CPF com formato inválido.';
            }
        }

        return $warnings;
    }

    /** Normaliza os valores mapeados para o formato esperado pela tabela leads */
    private function normalizeRow(array $data): array
    {
        $normalized = [];

        foreach ($data as $field => $value) {
            if ($value === null || $value === '') {
                $normalized[$field] = null;
                continue;
            }

            switch ($field) {
                case 'phone':
                case 'whatsapp':
                case 'cpf':
                case 'zipcode':
                case 'ddd':
                    $digits = preg_replace('/\D/', '', $value);
                    $normalized[$field] = $digits !== '' ? $digits : null;
                    break;
                case 'has_down_payment':
                    $normalized[$field] = $this->parseBoolean($value) ? 1 : 0;
                    break;
                case 'desired_value':
                case 'down_payment_value':
                    $num = str_replace('.', '', $value);
                    $num = str_replace(',', '.', $num);
                    $normalized[$field] = is_numeric($num) ? (float) $num : null;
                    break;
                case 'state':
                    $normalized[$field] = strtoupper(substr($value, 0, 2));
                    break;
                case 'source':
                    $normalized[$field] = $this->normalizeSourceValue($value);
                    break;
                case 'status':
                    $normalized[$field] = $this->normalizeStatusValue($value);
                    break;
                default:
                    $normalized[$field] = $value;
            }
        }

        return $normalized;
    }

    private function parseBoolean(string $value): bool
    {
        $value = mb_strtolower(trim($value));
        return in_array($value, ['1', 'sim', 's', 'yes', 'true', 'verdadeiro'], true);
    }

    /** Aceita tanto o slug (ex: "facebook") quanto o rótulo em pt-BR (ex: "Facebook") vindo do CSV */
    private function normalizeSourceValue(string $value): ?string
    {
        $slug = strtolower(trim($value));
        $valid = ['facebook','instagram','google','indicacao','site','landing_page','organico','cadastro_manual','whatsapp','importacao_csv','api','webhook','outros'];
        if (in_array($slug, $valid, true)) {
            return $slug;
        }

        // Chaves já sem acento, comparadas contra o rótulo também sem acento (ver stripAccents)
        $byLabel = [
            'facebook' => 'facebook', 'instagram' => 'instagram', 'google ads' => 'google', 'google' => 'google',
            'indicacao' => 'indicacao', 'site' => 'site', 'landing page' => 'landing_page',
            'organico' => 'organico', 'whatsapp' => 'whatsapp', 'cadastro manual' => 'cadastro_manual',
        ];
        $normalizedLabel = $this->stripAccents(mb_strtolower(trim($value)));
        return $byLabel[$normalizedLabel] ?? 'outros';
    }

    private function normalizeStatusValue(string $value): ?string
    {
        $slug = strtolower(trim($value));
        $valid = [
            'novo','primeiro_contato','tentando_contato','em_negociacao','documentacao',
            'aguardando_cliente','aguardando_aprovacao','aprovado','fechado','perdido',
            'sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado',
        ];
        return in_array($slug, $valid, true) ? $slug : null;
    }
}
