<?php if (Auth::can('calls.ai')): ?>
<details id="copilotPanel" class="copilot-panel" data-worklet="<?= e(asset('js/copilot-pcm-worklet.js')) ?>">
 <summary><span aria-hidden="true">✦</span> Inteligência da chamada <small id="copilotBadge">Desativada</small></summary>
 <div class="copilot-body">
  <p id="copilotStatus" class="copilot-status" role="status" aria-live="polite">Verificando disponibilidade…</p>
  <label class="copilot-consent"><input id="copilotConsent" type="checkbox"> Autorizo enviar o áudio desta chamada ao Gemini e confirmo que os participantes foram informados.</label>
  <div class="copilot-actions"><button id="copilotStart" type="button" disabled>Ativar IA</button><button id="copilotStop" type="button" disabled>Encerrar IA e salvar</button></div>
  <div class="copilot-metrics" id="copilotMetrics"></div>
  <p id="copilotEstimate" class="copilot-muted">Indicadores são inferências da conversa, não garantias de conversão.</p>
  <section class="copilot-suggestion"><h3>Sugestão para responder</h3><p id="copilotSuggestion">As sugestões aparecerão após trechos relevantes da conversa.</p><button id="copilotCopy" type="button" disabled>Usar sugestão · copiar</button></section>
  <section><h3>Próxima pergunta</h3><p id="copilotQuestion">—</p></section>
  <section><h3>Resumo e contexto</h3><p id="copilotSummary">Aguardando conversa.</p><div id="copilotContext"></div></section>
  <section><h3>Objeções detectadas</h3><div id="copilotObjections"></div></section>
  <details class="copilot-transcript"><summary>Transcrição ao vivo</summary><div id="copilotTranscript" role="log" aria-label="Transcrição por participante"></div><p id="copilotPartial" class="copilot-muted"></p></details>
  <p class="copilot-muted">A IA não fala com o cliente. Sem áudio ao vivo, a análise da gravação continua disponível após a sincronização.</p>
 </div>
</details>
<?php endif; ?>
