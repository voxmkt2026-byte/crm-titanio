<?php
declare(strict_types=1);
require_once __DIR__.'/../Integration/IntegrationConfig.php';

final class CallAccounts
{
    public static function key(string $key): string
    {
        if (!in_array($key,['api4com','api4com_2'],true)) throw new InvalidArgumentException('Conta de ligações inválida.');
        return $key;
    }

    public static function forAccount(IntegrationConfig $config,string $key): IntegrationConfig
    {
        return $config->forCallAccount(self::key($key));
    }

    public static function all(IntegrationConfig $config): array
    {
        $result=[];
        foreach (['api4com'=>['Linha 1','#2563eb'],'api4com_2'=>['Linha 2','#7c3aed']] as $key=>[$name,$color]) {
            $scoped=self::forAccount($config,$key);
            $configuredName=trim((string)$scoped->get('api4com','name',$name));
            $configuredColor=(string)$scoped->get('api4com','color',$color);
            $result[$key]=['key'=>$key,'name'=>$configuredName!==''?mb_substr($configuredName,0,60):$name,
                'color'=>preg_match('/^#[0-9a-fA-F]{6}$/D',$configuredColor)?$configuredColor:$color,
                'configured'=>trim((string)$scoped->get('api4com','token',''))!==''];
        }
        return $result;
    }
}
