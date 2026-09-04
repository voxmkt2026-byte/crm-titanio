# Vox Insights — Api4Com

Sistema simples em PHP, HTML e JavaScript para consultar ligações da Api4Com, buscar por número, ouvir gravações e gerar transcrição/análise por IA.

## Recursos

- Histórico paginado da Api4Com.
- Busca por origem, destino ou Bina.
- Reprodução segura das gravações sem expor o token.
- Análise comercial completa para vendas da Titanium Consultoria: resumo executivo, temperatura do lead, etapa do funil e notas de 0 a 10.
- Diagnóstico com pontos fortes, melhorias de abordagem, necessidades, sinais de compra, objeções e respostas sugeridas.
- Script para a próxima conversa, perguntas de descoberta, plano de follow-up com mensagem pronta, riscos, alertas e transcrição.
- Cache local: uma ligação não é cobrada novamente até que você escolha **Analisar novamente**.
- Interface responsiva, sem login e sem banco de dados.

## Requisitos

- XAMPP com PHP 8.0 ou superior.
- Extensões PHP `curl`, `dom`, `fileinfo`, `json` e `mbstring` habilitadas.
- Acesso à internet no computador que executa o Apache.

## Instalação no XAMPP

1. Copie esta pasta para:

   ```text
   C:\xampp\htdocs\requisicao
   ```

2. Confirme em `C:\xampp\php\php.ini` que estas linhas estão habilitadas, sem `;` no início:

   ```ini
   extension=curl
   extension=fileinfo
   extension=mbstring
   ```

3. Reinicie o Apache pelo painel do XAMPP.

4. O arquivo `.env` desta cópia já contém a configuração local da Api4Com. Antes de publicar ou compartilhar a pasta, revogue o token enviado na conversa, gere outro em [Tokens Api4Com](https://app.api4com.com/user/tokens) e atualize:

   ```dotenv
   API4COM_TOKEN=seu_novo_token
   ```

5. Abra:

   ```text
   http://localhost/requisicao/
   ```

## Configurar a análise de IA

O histórico e o áudio funcionam sem IA. Para ativar a análise, crie uma chave no [Google AI Studio](https://aistudio.google.com/apikey), abra `.env` e preencha:

```dotenv
GEMINI_API_KEY=sua_chave
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_MAX_INLINE_AUDIO_BYTES=14680064
```

O backend envia o áudio para `POST /interactions` e solicita, em uma única requisição estruturada, a transcrição e a análise comercial. A orientação é específica para cartas contempladas e créditos, mas a IA não inventa condições que não estejam na gravação. O modelo padrão possui nível gratuito sujeito aos limites da conta. Consulte a [documentação oficial de áudio do Gemini](https://ai.google.dev/gemini-api/docs/audio) e a [tabela de preços e limites](https://ai.google.dev/gemini-api/docs/pricing).

O envio inline é limitado a uma requisição total menor que 20 MB. Por segurança, o sistema aceita até 14 MB de áudio por análise. O player continua funcionando normalmente para gravações maiores.

> A chave da IA e o token da Api4Com ficam somente no PHP. Eles nunca são enviados ao JavaScript do navegador.

## Executar os testes

No PowerShell, dentro da pasta do projeto:

```powershell
C:\xampp\php\php.exe tests\run.php
```

Os testes usam serviços falsos. Eles não consomem a Api4Com nem geram cobranças de IA.

## Estrutura

```text
api/                 Endpoints JSON e áudio
assets/              Interface CSS e JavaScript
src/                 Clientes e regras do backend
storage/analyses/    Cache das análises
storage/tmp/         Áudios temporários
tests/               Testes automatizados
index.php            Página principal
.env                  Segredos locais (ignorado pelo Git)
```

## Segurança

Este projeto não possui login. Use somente no seu computador ou em uma rede interna confiável. Não encaminhe a porta do Apache para a internet.

As regras `.htaccess` bloqueiam acesso direto a `.env`, `src`, `storage`, `tests` e `docs`. Para funcionarem, o Apache deve permitir overrides. No XAMPP, abra `C:\xampp\apache\conf\httpd.conf`, localize o bloco de `C:/xampp/htdocs` e confirme:

```apache
AllowOverride All
```

Reinicie o Apache depois de alterar essa opção.

## Solução de problemas

### “O sistema ainda não foi completamente configurado”

Confirme se `.env` existe ao lado de `index.php` e se `API4COM_TOKEN` possui um valor válido.

### Token inválido ou erro 401

Gere um token novo no painel da Api4Com e substitua apenas o valor de `API4COM_TOKEN`. Não adicione `Bearer`; a Api4Com recebe o token diretamente no cabeçalho `Authorization`.

### Erro de certificado cURL

Atualize o XAMPP. Se o Windows continuar sem certificados, baixe um pacote CA confiável e configure `curl.cainfo` e `openssl.cafile` no `php.ini`. Não desative a validação TLS.

### Erro 403 ao abrir o projeto

Verifique se a URL termina em `/requisicao/` e se o Apache tem permissão para ler a pasta. Um 403 ao tentar abrir `.env`, `src` ou `storage` é esperado e indica que a proteção funciona.

### Erro 500 relacionado ao `.htaccess`

Confirme que o Apache 2.4 está em uso e que os módulos `rewrite` e `headers` estão habilitados. As regras de cabeçalhos são opcionais; a proteção local em cada diretório continua ativa.

### Gravação não toca

Algumas gravações podem não existir ou ter expirado na origem. Confira também se `curl` está habilitado e se o servidor tem acesso à URL retornada pela Api4Com.

### Áudio muito grande

O limite padrão é 25 MB. Ajuste com cautela:

```dotenv
MAX_AUDIO_BYTES=52428800
```

### IA ainda não configurada

Preencha `GEMINI_API_KEY`. Se sua conta não tiver acesso ao modelo padrão, altere `GEMINI_MODEL` para um modelo com suporte a entrada de áudio disponível no seu projeto.

### Limite gratuito do Gemini atingido

O nível gratuito possui limites por minuto e por dia. Aguarde a renovação da cota ou consulte os limites da sua chave no Google AI Studio.

### A primeira análise demora

O servidor baixa a gravação e o Gemini transcreve e analisa em uma única chamada. As próximas aberturas usam o cache. O botão **Analisar novamente** ignora o cache e consome novamente a cota.
