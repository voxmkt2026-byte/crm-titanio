# Atualização de ligações V10 — 04/09/2026

## Instalação na hospedagem

1. Faça uma cópia dos arquivos atuais do CRM antes de substituir os arquivos.
2. Extraia o conteúdo do ZIP V10 sobre as mesmas pastas do CRM. Mescle as pastas e substitua somente os arquivos do pacote; não exclua pastas existentes.
3. Preserve a estrutura atual: app fica no local de app; scripts no de scripts; public/index.php substitui o controlador público já utilizado pelo CRM. Se o conteúdo de public foi instalado diretamente no diretório público da hospedagem, copie esse index.php para lá, mantendo a organização anterior.
4. Inclua TODOS os novos arquivos de app/services/Calls, inclusive a subpasta Media. Copie também storage/.htaccess, que bloqueia acesso web direto ao armazenamento.
5. Abra Configurações → Integrações de Ligações. Na seção Api4Com, clique em **Testar API e armazenamento**. O botão testa os dados JÁ SALVOS; salve alterações de URL/token antes de testar. O diagnóstico cria e remove apenas seus próprios arquivos de prova, não ligações.
6. Clique em **Sincronizar agora**. Abra uma ligação, teste **Ouvir** e depois **Analisar**. Confira o relatório e o mesmo histórico no perfil do lead.

**Não importe nem substitua o banco de dados.** Esta atualização não contém SQL nem exige migration para quem já instalou o módulo de ligações.

**Não precisa reenviar a pasta ligacao.** Ela não foi modificada. Os componentes necessários ao áudio ficam agora dentro de app/services/Calls/Media. A pasta antiga continua sendo utilizada apenas como fonte opcional da configuração inicial e para importação dos relatórios antigos.

Não substitua .env, config/database.php, config/integration.key nem apague gravações existentes. O ZIP não inclui esses arquivos ou dados de clientes. Não envie os testes locais.

## Correções incluídas

- Mesma configuração administrativa da Api4Com em sincronização, áudio e análise. Token salvo no CRM tem prioridade; não há tentativa oculta com outro token.
- Consulta com HTTPS validado e erros distintos de token, DNS, certificado, tempo limite e limite de consultas. Tentativa adicional limitada para falhas transitórias.
- Download com validação HTTP, redirecionamentos, conteúdo, tamanho e integridade. HTML de erro não é gravado como áudio.
- Escrita privada verificada por tamanho e checksum. Se a pasta mensal não aceitar escrita, tenta outra pasta privada dentro de storage, sem depender de renomeação entre sistemas de arquivos.
- Reprodução sem IA; recuperação de cache ausente ou inválido; áudio local independente da chave da integração; suporte a intervalos de reprodução.
- Falhas de escrita durante download são identificadas como armazenamento. Nesses casos o player pode transmitir diretamente da Api4Com, sem arquivar. A análise exige armazenamento privado funcional.
- Uma única execução com o provedor de IA ativo. Relatório completo, indicadores e histórico persistidos. Bloqueio contra análise simultânea da mesma ligação.
- Metadados de áudios antigos são recuperados do arquivo. Retenção zero é respeitada; gravações expiradas não são baixadas novamente.
- Mantidas as correções anteriores de associação por telefone/WhatsApp, com e sem zero inicial, e apresentação no lead.

## Permissões e diagnóstico

O usuário que executa PHP precisa conseguir escrever em storage; deve haver espaço em disco. O código não contorna bloqueios de propriedade da hospedagem nem desativa HTTPS. Não use permissão 777.

Se houver erro de armazenamento no diagnóstico, corrija proprietário/permissão das pastas e espaço disponível na hospedagem. Se houver token rejeitado, confira o token SALVO NO CRM — alterar apenas a pasta antiga não substitui esse token.

Mantenha as rotinas existentes de scripts/calls-sync.php e scripts/calls-cleanup.php. A limpeza foi atualizada para reconhecer também o armazenamento alternativo privado. Esta correção não cria nem altera agendamentos da hospedagem.

## Verificações feitas

- 60 testes locais de regressão: todos passaram.
- MariaDB isolado usando a migration real: relatório, provedor, pontuação, histórico, reanálise, concorrência e descarte de caixa postal.
- Pipeline Gemini e OpenRouter exercitado por HTTP com áudio e respostas sintéticos em servidor local, sem enviar gravações de clientes a provedores de IA.
- Sintaxe PHP e revisão independente dos fluxos de áudio/armazenamento.

Esses testes verificam o código local. A implantação, as permissões e as credenciais da Hostinger precisam ser confirmadas pelo diagnóstico acima após o envio. Não foi realizada alteração remota na hospedagem.

Para reverter os arquivos, restaure o backup feito antes do envio. Não restaure um banco antigo: ele não foi alterado pela instalação deste pacote.
