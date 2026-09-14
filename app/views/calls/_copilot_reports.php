<?php
// Read-only presentation: optional tables or unavailable AI never break lead/call pages.
if (!isset($copilotReports)) {
 $copilotReports=[];
 try {
  if (Auth::can('calls.ai')) {
   require_once APP_PATH.'/models/IntegrationCredential.php';
   require_once APP_PATH.'/services/Calls/CopilotService.php';
   $copilotConfig=new IntegrationConfig(new IntegrationCredential(),SecretVault::fromFile(ROOT_PATH.'/config/integration.key'),IntegrationConfig::legacyEnvironment(ROOT_PATH.'/ligacao/.env'));
   $copilotService=new CopilotService(Database::getInstance(),$copilotConfig);
   $copilotReports=isset($copilotLeadId)
    ?$copilotService->readReportsForLead((int)$copilotLeadId,(int)Auth::id(),Auth::hasRole(['admin','supervisor']))
    :$copilotService->readReportsForCall((int)($copilotCallId??0),(int)Auth::id(),Auth::hasRole(['admin','supervisor']));
  }
 }catch(Throwable){$copilotReports=[];}
}
?>
<?php if ($copilotReports): ?>
<section class="card my-3"><div class="card-body">
 <h2 class="h5"><i class="fa-solid fa-wand-magic-sparkles me-2" aria-hidden="true"></i>Inteligência das conversas</h2>
 <p class="small text-muted">Inferências da IA baseadas na conversa disponível. Não substituem a confirmação com o cliente.</p>
 <?php foreach ($copilotReports as $copilotReport): $copilotInsight=$copilotReport['insight']??[]; ?>
 <details class="border rounded p-2 mb-2">
  <summary class="small fw-semibold"><?= e(($copilotReport['status']??'')==='completed'?'Relatório da conversa':(($copilotReport['status']??'')==='partial'?'Relatório parcial':'Sem conteúdo suficiente')) ?> · <?= e($copilotReport['created_at']??'') ?> UTC</summary>
  <?php if ($copilotInsight): ?>
  <p class="mt-2 mb-2"><?= nl2br(e($copilotInsight['summary']??'')) ?></p>
  <?php if (isset($copilotInsight['score'])): ?><p class="small">Score estimado: <?= e($copilotInsight['score']) ?>/100</p><?php endif; ?>
  <?php if (isset($copilotInsight['conversion_estimate'])): ?><p class="small text-muted">Conversão estimada: <?= e($copilotInsight['conversion_estimate']) ?>%. Estimativa da IA, não probabilidade validada.</p><?php endif; ?>
  <?php foreach (['interests'=>'Interesses','pains'=>'Dores','needs'=>'Necessidades','desires'=>'Desejos','urgencies'=>'Urgência','restrictions'=>'Restrições','risks'=>'Riscos','next_steps'=>'Próximos passos','strengths'=>'Pontos fortes','improvements'=>'Pontos a desenvolver'] as $copilotKey=>$copilotLabel): ?>
   <?php if (!empty($copilotInsight[$copilotKey])&&is_array($copilotInsight[$copilotKey])): ?><h3 class="h6 mt-3"><?= e($copilotLabel) ?></h3><ul class="small"><?php foreach ($copilotInsight[$copilotKey] as $copilotItem): if(!is_string($copilotItem))continue; ?><li><?= e($copilotItem) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php endforeach; ?>
  <?php foreach (['suggested_response'=>'Resposta sugerida','next_question'=>'Próxima pergunta','closing_signal'=>'Sinal de fechamento','outcome'=>'Resultado inferido'] as $copilotKey=>$copilotLabel): ?>
   <?php if (!empty($copilotInsight[$copilotKey])&&is_string($copilotInsight[$copilotKey])): ?><h3 class="h6"><?= e($copilotLabel) ?></h3><p class="small"><?= nl2br(e($copilotInsight[$copilotKey])) ?></p><?php endif; ?>
  <?php endforeach; ?>
  <?php foreach (($copilotInsight['objections']??[]) as $copilotObjection): if(!is_array($copilotObjection))continue; ?><div class="border-start ps-2 mb-2"><strong class="small"><?= e($copilotObjection['category']??'Objeção') ?></strong><p class="small mb-1"><?= e($copilotObjection['evidence']??'') ?></p><p class="small"><?= e($copilotObjection['suggestion']??'') ?></p></div><?php endforeach; ?>
  <?php else: ?><p class="small text-muted mt-2">Consulte a análise da gravação quando estiver disponível.</p><?php endif; ?>
 </details>
 <?php endforeach; ?>
</div></section>
<?php endif; unset($copilotReports,$copilotLeadId,$copilotCallId,$copilotService,$copilotConfig,$copilotInsight,$copilotReport,$copilotKey,$copilotLabel,$copilotItem,$copilotObjection); ?>
