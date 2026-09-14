<?php
declare(strict_types=1);
require_once __DIR__.'/PhoneNormalizer.php';

final class CallWorkspaceService
{
    public function __construct(private PDO $db) {}

    public function list(int $userId,bool $all,string $account,?int $leadId=null,?string $number=null):array
    {
        if (!in_array($account,['api4com','api4com_2'],true)) throw new InvalidArgumentException('Conta inválida.');
        $where=['c.provider=:account'];$params=[':account'=>$account];
        if (!$all) { $where[]='l.assigned_to=:user';$params[':user']=$userId; }
        if ($leadId!==null) { $where[]='c.lead_id=:lead';$params[':lead']=$leadId; }
        if (trim((string)$number)!=='') {
            $candidates=PhoneNormalizer::candidates($number);
            if (!$candidates) return [];
            $holders=[];
            foreach ($candidates as $i=>$phone) { $key=':phone'.$i;$holders[]=$key;$params[$key]=$phone; }
            $where[]='c.normalized_phone IN ('.implode(',',$holders).')';
        }
        $query=$this->db->prepare('SELECT c.id,c.provider,c.lead_id,c.started_at,c.duration,c.contact_phone,c.normalized_phone,c.recording_status,c.analysis_status,c.outcome,l.name lead_name,u.name agent_name,a.overall_score FROM call_records c LEFT JOIN leads l ON l.id=c.lead_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN call_analyses a ON a.call_id=c.id WHERE '.implode(' AND ',$where).' ORDER BY c.started_at DESC,c.id DESC LIMIT 100');
        $query->execute($params);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['id']=(int)$row['id'];$row['lead_id']=empty($row['lead_id'])?null:(int)$row['lead_id'];$row['duration']=(int)$row['duration']; }
        return $rows;
    }

    public function findLeads(int $userId,bool $all,string $search):array
    {
        $search=trim(mb_substr($search,0,100));
        if (mb_strlen($search)<2) return [];
        $where=['(name LIKE :name OR phone LIKE :phone OR whatsapp LIKE :whatsapp)'];
        $digits=preg_replace('/\D+/','',$search);
        $phone=$digits!==''?$digits:$search;
        $params=[':name'=>'%'.$search.'%',':phone'=>'%'.$phone.'%',':whatsapp'=>'%'.$phone.'%'];
        if (!$all) { $where[]='assigned_to=:user';$params[':user']=$userId; }
        $q=$this->db->prepare('SELECT id,name,phone FROM leads WHERE '.implode(' AND ',$where).' ORDER BY name,id LIMIT 20');
        $q->execute($params);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['id']=(int)$row['id'];
        return $rows;
    }

    public function correct(int $id,int $actor,bool $all,string $action,?int $leadId,string $reason):void
    {
        $reason=trim($reason);
        if (mb_strlen($reason)<50 || mb_strlen($reason)>2000) throw new InvalidArgumentException('Explique o motivo com 50 a 2.000 caracteres.');
        if (!in_array($action,['invalid','unlink','link','undo'],true)) throw new InvalidArgumentException('Ação inválida.');
        $this->db->beginTransaction();
        try {
            $q=$this->db->prepare('SELECT c.*,l.assigned_to FROM call_records c LEFT JOIN leads l ON l.id=c.lead_id WHERE c.id=:id'.($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''));
            $q->execute([':id'=>$id]);$call=$q->fetch(PDO::FETCH_ASSOC);
            if (!$call || (!$all && (int)($call['assigned_to']??0)!==$actor)) throw new RuntimeException('Ligação indisponível para este usuário.');
            $before=$this->snapshot($call);$after=$before;
            if ($action==='undo') {
                $q=$this->db->prepare('SELECT details FROM activity_log WHERE action=:action ORDER BY id DESC LIMIT 1');
                $q->execute([':action'=>'call_correction_'.$id]);$audit=json_decode((string)$q->fetchColumn(),true);
                if (!is_array($audit['before']??null)) throw new InvalidArgumentException('Não há correção para desfazer.');
                $after=$this->snapshot($audit['before']);
            } else {
                $after['link_status']='manual';
                $after['lead_id']=$action==='link'?$leadId:null;
                $after['lead_history_id']=null;
                if ($action==='invalid') $after['outcome']='invalid_number';
            }
            if ($action==='link' && (int)$after['lead_id']<=0) throw new InvalidArgumentException('Selecione o lead correto.');
            if (!$all && $after['lead_id']===null) throw new RuntimeException('A remoção de vínculo exige acesso a todas as ligações para permitir a reversão.');
            if ($after['lead_id']!==null) {
                $q=$this->db->prepare('SELECT assigned_to FROM leads WHERE id=:id');
                $q->execute([':id'=>$after['lead_id']]);$lead=$q->fetch(PDO::FETCH_ASSOC);
                if (!$lead || (!$all && (int)$lead['assigned_to']!==$actor)) throw new RuntimeException('Lead indisponível para este usuário.');
            }
            $description='Correção da ligação #'.$id.' ('.$action.'): '.$reason;
            if ($before['lead_id']!==null) $this->history((int)$before['lead_id'],$actor,$description);
            if ($after['lead_id']!==null && $after['lead_id']!==$before['lead_id']) {
                $after['lead_history_id']=$this->history((int)$after['lead_id'],$actor,$description);
                if (!empty($call['started_at'])) {
                    $q=$this->db->prepare('UPDATE leads SET last_contact_at=CASE WHEN last_contact_at IS NULL OR last_contact_at<:a THEN :b ELSE last_contact_at END WHERE id=:id');
                    $q->execute([':a'=>$call['started_at'],':b'=>$call['started_at'],':id'=>$after['lead_id']]);
                }
            }
            $q=$this->db->prepare('UPDATE call_records SET lead_id=:lead,lead_history_id=:history,link_status=:link,outcome=:outcome,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
            $q->execute([':lead'=>$after['lead_id'],':history'=>$after['lead_history_id'],':link'=>$after['link_status'],':outcome'=>$after['outcome'],':id'=>$id]);
            $q=$this->db->prepare('INSERT INTO activity_log(user_id,action,details,created_at) VALUES(:user,:action,:details,CURRENT_TIMESTAMP)');
            $q->execute([':user'=>$actor,':action'=>'call_correction_'.$id,':details'=>json_encode(['call_id'=>$id,'account'=>$call['provider'],'action'=>$action,'reason'=>$reason,'before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function snapshot(array $call):array
    {
        return ['lead_id'=>empty($call['lead_id'])?null:(int)$call['lead_id'],'lead_history_id'=>empty($call['lead_history_id'])?null:(int)$call['lead_history_id'],
            'link_status'=>(string)($call['link_status']??'unmatched'),'outcome'=>(string)($call['outcome']??'pending')];
    }
    private function history(int $lead,int $actor,string $description):int
    {
        $q=$this->db->prepare("INSERT INTO lead_history(lead_id,user_id,type,description,created_at) VALUES(:lead,:user,'ligacao',:description,CURRENT_TIMESTAMP)");
        $q->execute([':lead'=>$lead,':user'=>$actor,':description'=>$description]);
        return (int)$this->db->lastInsertId();
    }
}
