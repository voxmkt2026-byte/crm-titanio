<?php
declare(strict_types=1);
final class PhoneRequestGuard {
    public static function check(bool $authenticated,bool $authorized,bool $mutation,bool $csrfValid,array $server,string $baseUrl):void {
        if(!$authenticated)throw new PhoneException('AUTH_REQUIRED','Sua sessão expirou. Entre novamente no CRM.',401);
        if(!$authorized)throw new PhoneException('DIAL_FORBIDDEN','Você não tem permissão para usar o telefone.',403);
        if(!$mutation)return;
        if(!$csrfValid)throw new PhoneException('CSRF_INVALID','Token de segurança expirado. Atualize o telefone.',419);
        $origin=(string)($server['HTTP_ORIGIN']??'');
        if($origin===''&&isset($server['HTTP_REFERER']))$origin=(string)$server['HTTP_REFERER'];
        if(self::origin($origin)===null||self::origin($origin)!==self::origin($baseUrl)||($server['HTTP_SEC_FETCH_SITE']??'')==='cross-site')throw new PhoneException('ORIGIN_INVALID','Origem da solicitação inválida.',403);
    }
    private static function origin(string $url):?string {$parts=parse_url($url);if(!$parts||!in_array($parts['scheme']??'',['https','http'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))return null;return strtolower($parts['scheme'].'://'.$parts['host']).':'.($parts['port']??($parts['scheme']==='https'?443:80));}
}
