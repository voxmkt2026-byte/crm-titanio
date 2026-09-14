<?php
$analysis=is_array($call['analysis_payload']??null)?$call['analysis_payload']:[];
$canAnalyze=$canAnalyze??Auth::can('calls.reanalyze');
$canCorrect=$canCorrect??Auth::can('calls.manage');
$audioAvailable=!in_array($call['recording_status']??'',['expired','discarded','unavailable'],true);
$title=trim((string)($call['lead_name']??''))?:'Ligação sem lead associado';
$phone=(string)($call['normalized_phone']??$call['contact_phone']??'');
$duration=max(0,(int)($call['duration']??0));
$scores=is_array($analysis['scores']??null)?$analysis['scores']:[];
$score=$scores['overall']??$call['overall_score']??null;
$human=static fn($text)=>ucfirst(str_replace('_',' ',(string)$text));
$list=static function($items):void {
    if(!is_array($items)||!$items){echo '<p class="cw-muted">Nenhum item identificado.</p>';return;}
    echo '<ul class="cw-insight-list">';
    foreach($items as $item){
        echo '<li>';
        if(is_array($item)){
            foreach($item as $key=>$value)if(is_scalar($value)&&trim((string)$value)!==''){
                $labels=['point'=>'Ponto de melhoria','objection'=>'Objeção','evidence'=>'Evidência','recommended_action'=>'Ação recomendada','suggested_response'=>'Resposta sugerida','impact'=>'Impacto'];
                echo '<div><strong>'.e($labels[$key]??ucfirst(str_replace('_',' ',(string)$key))).':</strong> '.e($value).'</div>';
            }
        }else echo e(is_scalar($item)?$item:'');
        echo '</li>';
    }echo '</ul>';
};
?>
<article class="cw-detail" data-cw-call="<?= (int)$call['id'] ?>">
    <div class="cw-detail-heading">
        <div><div class="cw-detail-kicker"><span class="cw-pill"><?= e($account['name']??'Linha 1') ?></span><span class="cw-muted">LIGAÇÃO #<?= (int)$call['id'] ?></span></div><h2><?= e($title) ?></h2><div class="cw-phone"><?= e($phone!==''?format_phone($phone):'Número indisponível') ?><?php if($phone!==''): ?><button type="button" class="cw-text-button" data-cw-copy="<?= e($phone) ?>" aria-label="Copiar telefone">Copiar</button><?php endif; ?></div></div>
        <?php if($score!==null): ?><div class="cw-score"><strong><?= (int)$score ?><small>/10</small></strong><span>Qualidade da conversa</span></div><?php endif; ?>
    </div>
    <div class="cw-meta-grid">
        <div><span>Data e hora</span><strong><?= e(format_datetime($call['started_at']??null)) ?></strong></div>
        <div><span>Atendente</span><strong><?= e($call['agent_name']??$call['agent_external_key']??'Não identificado') ?></strong></div>
        <div><span>Duração</span><strong><?= sprintf('%02d:%02d',intdiv($duration,60),$duration%60) ?></strong></div>
        <div><span>Situação</span><strong><?= ($call['outcome']??'')==='invalid_number'?'Número incorreto':e(call_status_label($call['hangup_cause']??null)) ?></strong></div>
    </div>
    <section class="cw-player">
        <div class="cw-player-heading"><div><span class="cw-eyebrow">ÁUDIO DA CONVERSA</span><h3><?= $audioAvailable?'Ouça cada detalhe':'Gravação indisponível' ?></h3></div><span class="cw-private"><i class="fa-solid fa-lock" aria-hidden="true"></i> Acesso protegido</span></div>
        <?php if($audioAvailable): ?><audio controls preload="none" src="<?= e(url('ligacoes/'.$call['id'].'/audio')) ?>" aria-label="Gravação da ligação"></audio><p class="cw-muted">O áudio pode ser ouvido antes da análise de IA.</p><?php else: ?><p class="cw-muted">A gravação expirou, foi descartada ou não está disponível. Os registros existentes continuam preservados.</p><?php endif; ?>
        <p class="cw-audio-error" data-cw-audio-error role="alert" hidden>Não foi possível carregar o áudio. Tente novamente ou verifique a API e o armazenamento nas configurações.</p>
    </section>
    <div class="cw-detail-actions">
        <?php $phoneActionLead=['id'=>$call['lead_id']??0,'phone'=>$phone]; $phoneActionClass='cw-button cw-button-secondary'; require __DIR__.'/../partials/_phone_action.php'; ?>
        <?php if($canAnalyze&&$audioAvailable): ?><form class="cw-analysis-form" method="post" action="<?= e(url('ligacoes/'.$call['id'].'/analisar')) ?>"><?= Csrf::field() ?><input type="hidden" name="force" value="<?= $analysis?'1':'0' ?>"><button type="submit" class="cw-button cw-button-primary"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> <?= $analysis?'Analisar novamente':'Gerar análise com IA' ?></button></form><?php endif; ?>
        <?php if(!empty($call['lead_id'])): ?><a class="cw-button cw-button-secondary" href="<?= e(url('leads/'.$call['lead_id'])) ?>">Abrir perfil do lead ↗</a><?php endif; ?>
        <a class="cw-text-button" href="<?= e(url('ligacoes/'.$call['id'])) ?>">Ver página completa ↗</a>
    </div>
    <div class="cw-tabs" role="tablist" aria-label="Informações da conversa">
        <button type="button" role="tab" id="cw-tab-summary" aria-controls="cw-pane-summary" aria-selected="true" data-cw-tab="summary">Resumo</button>
        <button type="button" role="tab" id="cw-tab-analysis" aria-controls="cw-pane-analysis" aria-selected="false" tabindex="-1" data-cw-tab="analysis">Análise IA</button>
        <button type="button" role="tab" id="cw-tab-transcript" aria-controls="cw-pane-transcript" aria-selected="false" tabindex="-1" data-cw-tab="transcript">Transcrição</button>
    </div>
    <section role="tabpanel" id="cw-pane-summary" aria-labelledby="cw-tab-summary" data-cw-pane="summary">
        <?php if(!$analysis): ?><div class="cw-pending"><span class="cw-empty-icon">✧</span><h3><?= ($call['analysis_status']??'')==='failed'?'A análise precisa de uma nova tentativa':'Transforme a conversa em próximos passos' ?></h3><p>Gere a análise para ver resumo, oportunidades, objeções e sugestões de abordagem.</p><?php if(!empty($call['last_error_code'])): ?><code><?= e($call['last_error_code']) ?></code><?php endif; ?></div><?php else: ?>
        <section class="cw-insight"><h3>Resumo da conversa</h3><p class="cw-prose"><?= nl2br(e($call['summary']??$analysis['summary']??'')) ?></p></section>
        <div class="cw-signals"><?php foreach(['lead_temperature'=>'Temperatura','sales_stage'=>'Etapa','sentiment'=>'Sentimento'] as $field=>$label): ?><span class="cw-signal"><?= e($label) ?><strong><?= e($human($call[$field]??$analysis[$field]??'Não informado')) ?></strong></span><?php endforeach; ?></div>
        <section class="cw-next-step"><span class="cw-eyebrow">CONTINUIDADE DA NEGOCIAÇÃO</span><h3>Próximos passos</h3><?php $list($analysis['next_steps']??[]); ?></section>
        <?php if(!empty($analysis['recommended_approach'])): ?><section class="cw-insight"><h3>Abordagem recomendada</h3><p class="cw-prose"><?= nl2br(e($analysis['recommended_approach'])) ?></p></section><?php endif; ?>
        <?php endif; ?>
    </section>
    <section role="tabpanel" id="cw-pane-analysis" aria-labelledby="cw-tab-analysis" data-cw-pane="analysis" hidden>
        <?php if(!$analysis): ?><div class="cw-pending"><h3>Análise ainda não disponível</h3><p>Use “Gerar análise com IA” para avaliar esta conversa.</p></div><?php else: ?>
        <section class="cw-insight"><h3>Pontuação da abordagem</h3><div class="cw-scores-grid"><?php foreach(['overall'=>'Desempenho geral','opening'=>'Abertura','discovery'=>'Descoberta','argumentation'=>'Argumentação','value_proposition'=>'Proposta de valor','objection_handling'=>'Objeções','closing'=>'Fechamento','follow_up'=>'Follow-up'] as $key=>$label): $value=max(0,min(10,(int)($scores[$key]??0))); ?><div><div class="cw-score-label"><span><?= e($label) ?></span><strong><?= $value ?>/10</strong></div><meter min="0" max="10" value="<?= $value ?>" aria-label="<?= e($label) ?>"><?= $value ?></meter></div><?php endforeach; ?></div></section>
        <?php foreach(['customer_needs'=>'Necessidades do cliente','buying_signals'=>'Sinais de compra','strengths'=>'Pontos fortes','improvements'=>'Oportunidades de melhoria','objections'=>'Objeções e respostas','discovery_questions'=>'Perguntas para aprofundar','risks'=>'Riscos comerciais','compliance_alerts'=>'Pontos de atenção'] as $key=>$label): ?><section class="cw-insight"><h3><?= e($label) ?></h3><?php $list($analysis[$key]??[]); ?></section><?php endforeach; ?>
        <?php foreach(['sales_script'=>'Script para a próxima conversa','follow_up_plan'=>'Plano de acompanhamento'] as $key=>$label): if(!empty($analysis[$key])&&is_array($analysis[$key])): ?><section class="cw-insight"><h3><?= e($label) ?></h3><?php foreach($analysis[$key] as $part=>$value): ?><h4><?= e($human($part)) ?></h4><?php if(is_array($value))$list($value);else echo '<p class="cw-prose">'.nl2br(e($value)).'</p>'; endforeach; ?></section><?php endif; endforeach; ?>
        <?php endif; ?>
    </section>
    <section role="tabpanel" id="cw-pane-transcript" aria-labelledby="cw-tab-transcript" data-cw-pane="transcript" hidden><section class="cw-insight"><h3>Transcrição completa</h3><p class="cw-transcript"><?= e($call['transcript']??$analysis['transcript']??'A transcrição ficará disponível após a análise.') ?></p></section></section>
    <?php if($canCorrect): ?>
    <details class="cw-corrections"><summary>Corrigir associação ou número <span>Alterações com histórico e opção de desfazer</span></summary>
        <form class="cw-correction-form" action="<?= e(url('ligacoes/'.$call['id'].'/corrigir')) ?>" method="post">
            <?= Csrf::field() ?>
            <label>O que deseja corrigir?<select name="action" data-cw-correction-action><option value="link">Associar a outro lead</option><?php if($canDetach??false): ?><option value="unlink">Desvincular deste lead</option><option value="invalid">Marcar número como incorreto</option><?php endif; ?><option value="undo">Desfazer a última correção</option></select></label>
            <div data-cw-lead-picker><label>Encontre o lead correto<input type="search" data-cw-lead-search placeholder="Nome ou telefone do lead" autocomplete="off"></label><select name="lead_id" data-cw-lead-results aria-label="Lead correto"><option value="">Busque e selecione um lead</option></select></div>
            <label>Motivo da correção<textarea name="reason" minlength="50" maxlength="2000" rows="3" required placeholder="Descreva o que foi conferido e por que este registro precisa ser corrigido."></textarea></label><small class="cw-muted" data-cw-reason-count>Mínimo de 50 caracteres.</small>
            <label class="cw-confirm"><input type="checkbox" required> Confirmo a correção. O áudio, a análise e o cadastro do lead não serão apagados.</label>
            <button type="submit" class="cw-button cw-button-secondary">Confirmar correção</button>
        </form>
    </details>
    <?php endif; ?>
</article>
