<?php
declare(strict_types=1);

final class CallFailure
{
    public static function code(Throwable $error): string
    {
        $code = method_exists($error, 'publicCode') ? $error->publicCode() : $error->getMessage();
        return is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{1,79}$/D', $code) ? $code : 'CALL_INTERNAL_ERROR';
    }

    public static function message(Throwable $error): string
    {
        $code = self::code($error);
        $messages = [
            'API4COM_AUTH_FAILED' => 'A Api4Com rejeitou o token salvo nas configurações do CRM.',
            'API4COM_NOT_CONFIGURED' => 'Informe o token da Api4Com nas configurações do CRM.',
            'API4COM_RATE_LIMIT' => 'A Api4Com limitou as consultas. Aguarde um minuto e tente novamente.',
            'API4COM_TIMEOUT' => 'A consulta à Api4Com excedeu o tempo limite.',
            'API4COM_DNS_FAILED' => 'O servidor não conseguiu localizar o endereço da Api4Com.',
            'API4COM_TLS_FAILED' => 'O servidor não conseguiu validar o certificado HTTPS da Api4Com.',
            'API4COM_NETWORK_FAILED' => 'O servidor não conseguiu conectar à Api4Com.',
            'API4COM_UNAVAILABLE' => 'A Api4Com respondeu com erro temporário.',
            'API4COM_INVALID_RESPONSE' => 'A Api4Com devolveu um formato de resposta inesperado.',
            'API4COM_INVALID_URL' => 'Confira a URL HTTPS da Api4Com nas configurações.',
            'CALL_NOT_FOUND' => 'Esta ligação não foi localizada na Api4Com.',
            'RECORDING_NOT_AVAILABLE' => 'A Api4Com não disponibiliza mais a gravação desta ligação.',
            'INVALID_RECORDING_URL' => 'A gravação foi recusada porque seu endereço não é autorizado.',
            'RECORDING_DOWNLOAD_FAILED' => 'Não foi possível baixar a gravação da Api4Com.',
            'RECORDING_INVALID_CONTENT' => 'O download não retornou um arquivo de áudio válido.',
            'RECORDING_STORAGE_UNAVAILABLE' => 'O PHP precisa de permissão de escrita na pasta storage do CRM. Verifique também o espaço disponível.',
            'TEMP_FILE_FAILED' => 'Não foi possível criar o áudio temporário. Verifique espaço e escrita em storage.',
            'TEMP_DIRECTORY_FAILED' => 'Não foi possível preparar a pasta temporária em storage.',
            'AUDIO_UNAVAILABLE' => 'O arquivo de áudio está vazio ou não pôde ser lido.',
            'RECORDING_STORE_FAILED' => 'Não foi possível salvar e verificar o áudio. Verifique espaço em disco e permissão de escrita em storage.',
            'RECORDING_EXPIRED' => 'Esta gravação foi removida conforme a política de retenção.',
            'AI_NOT_CONFIGURED' => 'Configure e ative o provedor de IA no CRM.',
            'AI_RATE_LIMIT' => 'O provedor de IA atingiu o limite temporário. Tente novamente mais tarde.',
            'AI_ANALYSIS_NETWORK_FAILED' => 'O provedor de IA não respondeu dentro do prazo.',
            'AI_ANALYSIS_FAILED' => 'O provedor de IA não concluiu o relatório. Confira o modelo configurado.',
            'AUDIO_TOO_LARGE' => 'A gravação excede o tamanho permitido pelo provedor de IA.',
            'ANALYSIS_BUSY' => 'Esta ligação já está sendo analisada. Aguarde a conclusão.',
        ];
        return ($messages[$code] ?? 'A operação não foi concluída. O administrador pode consultar o diagnóstico do servidor.') . ' Código: ' . $code;
    }

    public static function log(string $stage, Throwable $error): void
    {
        // Do not put URLs, provider bodies, credentials, transcripts or SQL in logs.
        error_log('calls stage=' . preg_replace('/[^a-z0-9_]/i', '', $stage) . ' code=' . self::code($error));
    }
}
