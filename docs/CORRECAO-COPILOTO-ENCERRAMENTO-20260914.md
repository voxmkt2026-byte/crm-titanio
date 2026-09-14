# Correção do copiloto e do encerramento — 14/09/2026

## Instalação no servidor

1. Faça backup dos arquivos que serão substituídos. Aguarde o fim das chamadas abertas.
2. Extraia o ZIP na raiz do CRM, preservando as pastas `app/` e `public/` e, se presente no pacote, `ligacao/`.
3. Acesse **Configurações → Copiloto → Modelo de análise**, informe **gemini-3.6-flash** e salve. O modelo anterior, `gemini-2.5-flash`, foi recusado pelo Google nesta conta. Atualizar os arquivos não substitui modelos já salvos pelo administrador.
4. Não altere o modelo de transcrição que está funcionando, nem os tokens. Feche o popup antigo e atualize o CRM com **Ctrl + F5** antes de abrir o telefone novamente.

Não é necessário importar banco ou executar migration. Não substitua `.env`, `config/`, `storage/` ou a base de produção. O pacote não contém credenciais nem dados de clientes.

## Comportamento esperado

- O copiloto recebe a transcrição e atualiza resumo, sugestões, próxima pergunta, objeções e indicadores durante a conversa, sem precisar encerrar a chamada.
- A análise usa lotes: respeita o intervalo configurado (atualmente 20 segundos no localhost), acrescido do processamento do provedor. A transcrição aparece antes da análise.
- Falhas de modelo, chave, cota e sobrecarga passam a ter avisos específicos. Uma falha do provedor não é apresentada como ausência de áudio e não interrompe a chamada.
- O encerramento consulta o registro exato da operadora quando a resposta inicial é inconclusiva. Nenhuma tentativa é liberada apenas porque houve erro de rede ou porque outro número encerrou uma chamada.
- Se a operadora ainda não confirmar o fim, a pendência permanece protegida para evitar chamadas duplicadas. Os botões de conferir e encerrar continuam disponíveis.

## Verificação

O Gemini retornou uma análise de diálogo fictício usando o transporte real do CRM. Testes automatizados verificam insights antes da finalização, recuperação dos avisos, consentimento, permissões, isolamento da telefonia e regressões de encerramento. Não foram feitas chamadas para clientes nesses testes.

O localhost já recebeu o modelo atualizado, mantendo a chave, o modelo de transcrição e o intervalo existentes. No servidor, o passo 3 precisa ser feito pela configuração administrativa.

Referência técnica do controle de baixa latência usado no Gemini 3: [documentação oficial de thinkingLevel](https://ai.google.dev/gemini-api/docs/generate-content/thinking).
