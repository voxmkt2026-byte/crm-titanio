<?php

class WebsiteCrawlerService
{
    private const MAX_BYTES = 2097152;

    public function extract(string $url,int $redirects=0): array
    {
        if($redirects>3)throw new RuntimeException('A página excedeu o limite seguro de redirecionamentos.');
        $url=$this->validatedUrl($url);$body='';$location='';
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'TitaniumWikiBot/1.0 (+https://titaniumconsultorias.com.br/)',CURLOPT_HTTPHEADER=>['Accept: text/html, text/plain;q=0.9'],CURLOPT_HEADERFUNCTION=>function($ch,$header)use(&$location){if(stripos($header,'Location:')===0)$location=trim(substr($header,9));return strlen($header);},CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>self::MAX_BYTES)return 0;$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$error=curl_error($ch);curl_close($ch);
        if($code>=300&&$code<400&&$location!==''){$next=$this->absoluteUrl($url,$location);return $this->extract($next,$redirects+1);}
        if(!$ok||$code<200||$code>=300)throw new RuntimeException($error?:'O site respondeu com HTTP '.$code.'.');
        if(stripos($type,'text/html')===false&&stripos($type,'text/plain')===false)throw new RuntimeException('Somente páginas HTML ou texto podem ser analisadas.');
        if(stripos($type,'text/plain')!==false)return['title'=>parse_url($url,PHP_URL_HOST),'content'=>'<p>'.nl2br(htmlspecialchars(trim($body),ENT_QUOTES,'UTF-8')).'</p>'];
        libxml_use_internal_errors(true);$dom=new DOMDocument();$dom->loadHTML('<?xml encoding="utf-8" ?>'.$body,LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($dom);
        foreach($xp->query('//script|//style|//noscript|//svg|//form|//nav|//footer|//iframe') as $node)$node->parentNode->removeChild($node);
        $title=trim((string)($xp->query('//title')->item(0)?->textContent??parse_url($url,PHP_URL_HOST)));
        $root=$xp->query('//main')->item(0)?:$xp->query('//article')->item(0)?:$xp->query('//body')->item(0);
        // Lista de tags ampliada (antes só h1-h3/p/li, perdia tabelas, listas de definição,
        // citações e h4-h6 — causa comum de "não puxou todas as informações da página").
        // Tags de bloco (h1-h6, blockquote) preservam a própria tag; o resto vira <p>.
        $blockTags=['h1','h2','h3','h4','h5','h6','blockquote'];
        $html='';$seen=[];
        if($root){
            foreach($xp->query('.//h1|.//h2|.//h3|.//h4|.//h5|.//h6|.//p|.//li|.//td|.//th|.//dt|.//dd|.//blockquote|.//figcaption|.//summary',$root) as $node){
                $text=trim(preg_replace('/\s+/u',' ',$node->textContent));
                if($text===''||mb_strlen($text)<3)continue;
                // Evita duplicar o mesmo texto quando um nó pai (ex: <li>) e um filho
                // (ex: <p> dentro do <li>) seriam capturados separadamente pela query.
                $hash=md5($text);
                if(isset($seen[$hash]))continue;
                $seen[$hash]=true;
                $tag=strtolower($node->nodeName);
                $tag=in_array($tag,$blockTags,true)?$tag:'p';
                $html.='<'.$tag.'>'.htmlspecialchars($text,ENT_QUOTES,'UTF-8').'</'.$tag.'>';
                if(strlen($html)>200000)break;
            }
        }
        if(mb_strlen(strip_tags($html))<80)throw new RuntimeException('A página não apresentou conteúdo textual suficiente.');
        return['title'=>mb_substr($title,0,255),'content'=>$html];
    }

    public function validate(string $url): string
    {
        return $this->validatedUrl($url);
    }

    private function absoluteUrl(string $base,string $location): string
    {
        if(str_starts_with($location,'https://'))return $this->validatedUrl($location);
        $p=parse_url($base);if(str_starts_with($location,'/'))return $this->validatedUrl('https://'.$p['host'].$location);
        $dir=rtrim(str_replace('\\','/',dirname($p['path']??'/')),'/');return $this->validatedUrl('https://'.$p['host'].$dir.'/'.$location);
    }

    private function validatedUrl(string $url): string
    {
        $url=trim($url);$parts=parse_url($url);if(!$parts||($parts['scheme']??'')!=='https'||empty($parts['host']))throw new InvalidArgumentException('Informe uma URL HTTPS válida.');
        if(isset($parts['user'])||isset($parts['pass'])||isset($parts['port']))throw new InvalidArgumentException('URL com credenciais ou porta personalizada não é permitida.');
        $host=strtolower(rtrim($parts['host'],'.'));if($host==='localhost'||str_ends_with($host,'.local'))throw new InvalidArgumentException('Este endereço não é permitido.');
        $ips=gethostbynamel($host)?:[];if(!$ips)throw new InvalidArgumentException('Domínio não encontrado.');foreach($ips as $ip)if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)throw new InvalidArgumentException('Endereço de rede privada não é permitido.');
        return $url;
    }
}
