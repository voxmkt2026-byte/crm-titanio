<?php
/**
 * app/core/Mailer.php
 * Cliente SMTP simples implementado com sockets nativos do PHP
 * (fsockopen/stream_socket_enable_crypto), sem PHPMailer/Composer.
 * Suporta autenticação AUTH LOGIN e criptografia STARTTLS ou SSL/TLS direto,
 * lendo as credenciais salvas em Configurações (tabela `settings`).
 *
 * Uso:
 *   $mailer = Mailer::fromSettings();
 *   if ($mailer->isConfigured()) {
 *       $mailer->send('destino@exemplo.com', 'Assunto', '<p>Corpo em HTML</p>');
 *   }
 *
 * Em caso de qualquer falha (SMTP não configurado, host fora do ar,
 * credenciais inválidas etc.) o método send() nunca lança exceção para
 * quem chamou: ele registra o erro em storage/logs/mail.log e retorna false,
 * para nunca quebrar o fluxo principal da aplicação (criação de lead,
 * atribuição de responsável, "esqueci minha senha" etc.).
 */

class Mailer
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $fromEmail;
    private string $fromName;
    /** '', 'tls' (STARTTLS) ou 'ssl' (conexão já criptografada, ex: porta 465) */
    private string $encryption;
    private int $timeout = 15;

    /** @var resource|null */
    private $socket = null;

    public function __construct(array $config)
    {
        $this->host       = (string) ($config['host'] ?? '');
        $this->port       = (int) ($config['port'] ?? 587);
        $this->user       = (string) ($config['user'] ?? '');
        $this->pass       = (string) ($config['pass'] ?? '');
        $this->fromEmail  = (string) ($config['from_email'] ?? '');
        $this->fromName   = (string) ($config['from_name'] ?? 'Titanium CRM');
        $this->encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
    }

    /** Monta o Mailer a partir das credenciais salvas em Configurações. */
    public static function fromSettings(): self
    {
        require_once APP_PATH . '/models/Setting.php';
        $settingModel = new Setting();
        $map = $settingModel->allAsMap();

        return new self([
            'host'       => $map['smtp_host'] ?? '',
            'port'       => $map['smtp_port'] ?? 587,
            'user'       => $map['smtp_user'] ?? '',
            'pass'       => $map['smtp_pass'] ?? '',
            'from_email' => $map['smtp_from_email'] ?? ($map['smtp_user'] ?? ''),
            'from_name'  => $map['smtp_from_name'] ?? 'Titanium CRM',
            'encryption' => $map['smtp_encryption'] ?? 'tls',
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->user !== '' && $this->pass !== '' && $this->fromEmail !== '';
    }

    /**
     * Envia um e-mail com corpo HTML. Retorna true/false; nunca lança exceção.
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $toName = null): bool
    {
        if (!$this->isConfigured()) {
            $this->log('Envio abortado: SMTP não configurado em Configurações (host/usuário/senha/remetente).');
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->log('Envio abortado: e-mail de destino inválido (' . $to . ').');
            return false;
        }

        try {
            $this->connect();
            $this->handshake();

            if ($this->encryption === 'tls') {
                $this->command("STARTTLS\r\n", [220]);
                $this->enableCrypto();
                $this->handshake(); // EHLO deve ser reenviado após STARTTLS
            }

            $this->authenticate();
            $this->sendEnvelope($to, $subject, $htmlBody, $toName);
            $this->command("QUIT\r\n", [221], false);
            $this->disconnect();

            return true;
        } catch (Throwable $e) {
            $this->log('Falha ao enviar e-mail para ' . $to . ': ' . $e->getMessage());
            error_log('Mailer: ' . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }

    private function connect(): void
    {
        $host = $this->encryption === 'ssl' ? 'ssl://' . $this->host : $this->host;

        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($host, $this->port, $errno, $errstr, $this->timeout);

        if (!$this->socket) {
            throw new RuntimeException('Não foi possível conectar ao servidor SMTP ' . $this->host . ':' . $this->port . ' (' . $errstr . ')');
        }

        stream_set_timeout($this->socket, $this->timeout);
        $this->readResponse([220]);
    }

    private function handshake(): void
    {
        $domain = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $this->command("EHLO {$domain}\r\n", [250]);
    }

    private function enableCrypto(): void
    {
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        $enabled = @stream_socket_enable_crypto($this->socket, true, $method);
        if ($enabled !== true) {
            throw new RuntimeException('Falha ao habilitar TLS (STARTTLS) na conexão SMTP.');
        }
    }

    private function authenticate(): void
    {
        $this->command("AUTH LOGIN\r\n", [334]);
        $this->command(base64_encode($this->user) . "\r\n", [334]);
        $this->command(base64_encode($this->pass) . "\r\n", [235]);
    }

    private function sendEnvelope(string $to, string $subject, string $htmlBody, ?string $toName): void
    {
        $this->command('MAIL FROM:<' . $this->fromEmail . ">\r\n", [250]);
        $this->command('RCPT TO:<' . $to . ">\r\n", [250, 251]);
        $this->command("DATA\r\n", [354]);

        $boundaryDate = date('r');
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $fromHeader = $this->fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->fromEmail . '>'
            : $this->fromEmail;
        $toHeader = $toName
            ? '=?UTF-8?B?' . base64_encode($toName) . '?= <' . $to . '>'
            : $to;

        $headers = [
            'Date: ' . $boundaryDate,
            'From: ' . $fromHeader,
            'To: ' . $toHeader,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Titanium CRM',
        ];

        // Escapa linhas que começam com "." (transparência SMTP, RFC 5321)
        $body = preg_replace('/^\./m', '..', $htmlBody);

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
        $this->write($data);
        $this->readResponse([250]);
    }

    private function command(string $line, array $expectedCodes, bool $expectResponse = true): void
    {
        $this->write($line);
        if ($expectResponse) {
            $this->readResponse($expectedCodes);
        }
    }

    private function write(string $data): void
    {
        if (!$this->socket) {
            throw new RuntimeException('Conexão SMTP não estabelecida.');
        }
        fwrite($this->socket, $data);
    }

    private function readResponse(array $expectedCodes): string
    {
        if (!$this->socket) {
            throw new RuntimeException('Conexão SMTP não estabelecida.');
        }

        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Linha final de uma resposta multiline SMTP: "250 " (com espaço, não hífen)
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new RuntimeException('Sem resposta do servidor SMTP (timeout ou conexão encerrada).');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('Resposta SMTP inesperada: ' . trim($response));
        }

        return $response;
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function log(string $message): void
    {
        $logFile = STORAGE_PATH . '/logs/mail.log';
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
