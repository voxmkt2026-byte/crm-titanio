<?php
/**
 * app/models/LeadScore.php
 * Lead Score automático configurável (Fase 2; peso por origem na Fase 5).
 * As regras (critério + peso + ativo) ficam na tabela lead_score_rules
 * (ver database/sql/migration_fase2.sql e database/sql/migration_fase5.sql)
 * e podem ser editadas pela tela Configurações > Lead Score
 * (app/controllers/LeadScoreController.php). calculate() soma os pesos dos
 * critérios atendidos e retorna um score limitado entre 0 e 100.
 */

require_once APP_PATH . '/core/Model.php';

class LeadScore extends Model
{
    protected string $table = 'lead_score_rules';

    /** Interesses considerados de alto valor/qualificados para o critério "interesse_qualificado" */
    private array $highValueInterests = ['imovel', 'maquinario', 'agronegocio', 'investimento'];

    public function allRules(): array
    {
        $stmt = $this->db->query("SELECT * FROM lead_score_rules ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    public function activeRulesMap(): array
    {
        $stmt = $this->db->query("SELECT criterio, peso FROM lead_score_rules WHERE ativo = 1");
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['criterio']] = (int) $row['peso'];
        }
        return $map;
    }

    public function updateRule(int $id, int $peso, bool $ativo): bool
    {
        $stmt = $this->db->prepare("UPDATE lead_score_rules SET peso = :peso, ativo = :ativo WHERE id = :id");
        return $stmt->execute([
            ':peso'  => $peso,
            ':ativo' => $ativo ? 1 : 0,
            ':id'    => $id,
        ]);
    }

    /**
     * Retorna todas as regras já agrupadas para a tela de configuração
     * (Fase 5): "origem" (critérios "origem_*", incluindo o antigo
     * "origem_qualificada" desativado) e "geral" (todos os demais).
     * Usado por app/controllers/LeadScoreController.php.
     */
    public function rulesGroupedForSettings(): array
    {
        $groups = ['geral' => [], 'origem' => []];
        foreach ($this->allRules() as $rule) {
            $group = strpos($rule['criterio'], 'origem_') === 0 ? 'origem' : 'geral';
            $groups[$group][] = $rule;
        }
        return $groups;
    }

    /**
     * Recalcula e persiste o lead_score de um lead a partir dos dados atuais
     * dele + quantidade de interações no histórico. Método reaproveitado por
     * LeadController, ImportController e AgendaController para não duplicar
     * a mesma lógica de "buscar lead -> contar histórico -> calcular ->
     * salvar" em cada controller.
     */
    public function recalculateForLead(int $leadId): int
    {
        require_once APP_PATH . '/models/Lead.php';
        require_once APP_PATH . '/models/LeadHistory.php';

        $leadModel = new Lead();
        $lead = $leadModel->find($leadId);
        if (!$lead) {
            return 0;
        }

        $historyModel = new LeadHistory();
        $interactionCount = count($historyModel->forLead($leadId));

        $score = $this->calculate(array_merge($lead, [
            'interaction_count' => $interactionCount,
        ]));

        $leadModel->update($leadId, ['lead_score' => $score]);

        return $score;
    }

    /**
     * Calcula o lead_score (0-100) a partir dos dados atuais do lead.
     *
     * @param array $leadData Espera as chaves: has_down_payment, desired_value,
     *              interest, source, temperature, last_contact_at, created_at,
     *              interaction_count (opcional, quantidade de registros em lead_history)
     */
    public function calculate(array $leadData): int
    {
        $rules = $this->activeRulesMap();
        $score = 0;

        $hasDownPayment = !empty($leadData['has_down_payment']);
        $desiredValue   = (float) ($leadData['desired_value'] ?? 0);
        $interest       = $leadData['interest'] ?? null;
        $source         = $leadData['source'] ?? null;
        $temperature    = $leadData['temperature'] ?? null;
        $lastContactAt  = $leadData['last_contact_at'] ?? null;
        $createdAt      = $leadData['created_at'] ?? date('Y-m-d H:i:s');
        $interactions   = (int) ($leadData['interaction_count'] ?? 0);

        if (isset($rules['entrada_disponivel']) && $hasDownPayment) {
            $score += $rules['entrada_disponivel'];
        }

        if (isset($rules['valor_alto']) && $desiredValue >= 100000) {
            $score += $rules['valor_alto'];
        }

        if (isset($rules['interesse_qualificado']) && $interest && in_array($interest, $this->highValueInterests, true)) {
            $score += $rules['interesse_qualificado'];
        }

        // Peso por forma de captação (Fase 5): cada origem tem seu próprio
        // critério "origem_<source>" em lead_score_rules (ver
        // database/sql/migration_fase5.sql), com pesos coerentes com a
        // conversão histórica de cada canal (indicação > landing page >
        // Google Ads > Meta Ads/site/orgânico > WhatsApp direto > manual
        // > importação/API/webhook > outros). Se a migration_fase5 ainda
        // não tiver sido rodada, a regra simplesmente não existe no mapa
        // e nada é somado aqui (comportamento gracioso, sem quebrar).
        if ($source) {
            $sourceCriterio = 'origem_' . $source;
            if (isset($rules[$sourceCriterio])) {
                $score += $rules[$sourceCriterio];
            }
        }

        if ($temperature === 'quente' || $temperature === 'muito_quente') {
            if (isset($rules['temperatura_quente'])) {
                $score += $rules['temperatura_quente'];
            }
        } elseif ($temperature === 'frio') {
            if (isset($rules['temperatura_fria'])) {
                $score += $rules['temperatura_fria'];
            }
        }

        if (isset($rules['interacoes_frequentes']) && $interactions >= 3) {
            $score += $rules['interacoes_frequentes'];
        }

        // Penalidades por tempo sem contato
        $referenceTime = $lastContactAt ?: $createdAt;
        $timestamp = strtotime((string) $referenceTime);
        if ($timestamp) {
            $hoursSince = (time() - $timestamp) / 3600;

            if (!$lastContactAt && $hoursSince >= 120 && isset($rules['tempo_espera_critico'])) {
                $score += $rules['tempo_espera_critico'];
            } elseif (!$lastContactAt && $hoursSince >= 48 && isset($rules['sem_contato_recente'])) {
                $score += $rules['sem_contato_recente'];
            }
        }

        return (int) max(0, min(100, $score));
    }
}
