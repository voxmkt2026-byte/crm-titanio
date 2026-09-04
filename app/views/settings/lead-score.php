<?php
/**
 * app/views/settings/lead-score.php
 * Configuração dos critérios/pesos do Lead Score automático (Fase 2),
 * incluindo o peso por origem/forma de captação (Fase 5).
 */
function tc_score_rules_table(array $rules, bool $isOrigin): void
{
    ?>
    <div class="table-responsive">
        <table class="table tc-table mb-0">
            <thead>
                <tr>
                    <th><?= $isOrigin ? 'Origem' : 'Critério' ?></th>
                    <th>Descrição</th>
                    <th style="width:140px;">Peso</th>
                    <th style="width:100px;">Ativo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rules)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Nenhuma regra cadastrada<?= $isOrigin ? ' ainda. Rode a migration_fase5.sql para criar os pesos por origem.' : '.' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rules as $rule): ?>
                    <tr class="<?= (int) $rule['ativo'] === 0 ? 'text-muted' : '' ?>">
                        <td class="fw-semibold"><code><?= e($rule['criterio']) ?></code></td>
                        <td style="font-size:0.85rem;"><?= e($rule['descricao']) ?></td>
                        <td>
                            <input type="number" class="form-control form-control-sm"
                                   name="rules[<?= (int) $rule['id'] ?>][peso]"
                                   value="<?= (int) $rule['peso'] ?>" step="1">
                        </td>
                        <td>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox"
                                       name="rules[<?= (int) $rule['id'] ?>][ativo]" value="1"
                                       <?= (int) $rule['ativo'] === 1 ? 'checked' : '' ?>>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>

<div class="tc-insight-card mb-3">
    <i class="fa-solid fa-circle-info"></i>
    <span>
        O Lead Score (0 a 100) é calculado automaticamente sempre que um lead é criado, editado, recebe uma observação
        ou é atualizado por importação CSV. Cada critério abaixo soma (ou subtrai, se o peso for negativo) pontos
        quando é atendido. Desmarque "Ativo" para desligar um critério sem perder o peso configurado.
    </span>
</div>

<form method="POST" action="<?= e(url('configuracoes/lead-score/update')) ?>">
    <?= Csrf::field() ?>

    <div class="tc-card mb-3">
        <div class="tc-card-header">Critérios gerais</div>
        <div class="tc-card-body p-0">
            <?php tc_score_rules_table($groups['geral'] ?? [], false); ?>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header d-flex align-items-center justify-content-between">
            <span>Peso por origem / forma de captação</span>
            <span class="badge bg-secondary">Fase 5</span>
        </div>
        <div class="tc-card-body">
            <p class="text-muted mb-3" style="font-size:0.85rem;">
                Cada origem tem um peso próprio, refletindo a conversão histórica de cada canal: Indicação converte
                mais que todos, Landing Page própria converte melhor que WhatsApp direto, e Google Ads converte
                melhor que Meta Ads (Facebook/Instagram). Ajuste os pesos abaixo conforme a realidade comercial da
                Titanium Consultoria mudar. O critério "origem_qualificada" (Fase 2, genérico) foi substituído por
                estes e permanece apenas para histórico — mantenha-o desativado.
            </p>
        </div>
        <div class="tc-card-body p-0">
            <?php tc_score_rules_table($groups['origem'] ?? [], true); ?>
        </div>
    </div>

    <button type="submit" class="btn btn-tc-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Critérios</button>
</form>
