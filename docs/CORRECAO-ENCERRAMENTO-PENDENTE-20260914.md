# Correção do encerramento pendente — 14/09/2026

Atualização pontual do CRM e do controlador compartilhado com o módulo `ligacao`. Não recria o sistema, não troca tokens e não requer SQL.

## Arquivos de código

- `app/services/Calls/PhoneService.php`
- `public/assets/js/crm-phone-popup.js`
- `ligacao/assets/webphone.js`

Atualize os três juntos, preservando os caminhos da instalação. Se os arquivos públicos já ficam em `public_html`, o conteúdo de `public/` deve seguir o mapeamento atual; não mova `app/` para o diretório público. Faça backup dos três arquivos antes de substituir. Não envie nem substitua banco, `.env`, `config/`, gravações ou armazenamento.

## O que foi corrigido

- Encerrar tentativa pendente também encerra o controlador do telefone, em vez de limpar somente os identificadores da interface.
- A conexão de áudio local é encerrada imediatamente ao clicar, sem aguardar o retorno HTTP da operadora.
- Os controles normal e pendente compartilham a operação para evitar encerramentos duplicados.
- Uma tentativa recuperada após recarregar a página usa a mesma validação de término.
- O CRM considera encerrado o ID de cancelamento da própria tentativa quando a Api4Com confirma que ele já não existe (CALL_NOT_FOUND / 404). Isso não depende de gravação, IA ou publicação do histórico.
- Erros de rede, token, servidor e respostas `ended:false` continuam distintos da confirmação. Não há liberação indiscriminada de outras chamadas ou exclusão de histórico.

A documentação da Api4Com informa que o [ID retornado pelo discador é usado para cancelamento e difere do ID do histórico](https://developers.api4com.com/operations/Dialer.doCall.html), e que [uma chamada já encerrada retorna não encontrada ao tentar desligar](https://developers.api4com.com/operations/Call.hangupCall.html).

## Após atualizar

1. Aguarde o término das chamadas em uso antes de substituir os arquivos.
2. Feche o popup antigo, recarregue o CRM com Ctrl + F5 e abra novamente o telefone. Código já aberto no navegador não se atualiza sozinho.
3. Se havia uma tentativa antiga pendente, use Encerrar tentativa pendente.
4. Com um número de teste autorizado, valide ligar, encerrar e ligar novamente. Confira também encerramento antes de atender e quando a outra pessoa desliga primeiro.

Os testes automatizados utilizam chamadas simuladas e incluem encerramento imediato do SIP, resposta HTTP atrasada, recuperação após reload, preservação de bloqueios sem confirmação, idempotência e restrição ao usuário/tentativa. Não foram feitas ligações para clientes para testar esta atualização.

Nenhuma alteração de estrutura do banco é necessária para esta correção. O pacote completo v2 já inclui estes arquivos; se enviar o completo v2, não é necessário aplicar a correção pontual separadamente.
