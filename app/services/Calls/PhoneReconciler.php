<?php
declare(strict_types=1);
/** Sync-only confirmation; never uses temporal proximity or phone matching. */
final class PhoneReconciler {
    public function __construct(private PDO $db){}
    public function reconcile(string $account,int $recordId,array $payload):void {
        try{$this->db->query('SELECT attempt_id FROM phone_attempts LIMIT 0');}catch(PDOException){return;}
        $externalId=(string)($payload['id']??'');if($externalId==='')return;
        $q=$this->db->prepare('SELECT * FROM call_records WHERE id=? AND provider=? AND external_id=?');$q->execute([$recordId,$account,$externalId]);if(!$q->fetch(PDO::FETCH_ASSOC))return;
        // Persisted correlation survives later payloads that omit metadata or use recording IDs.
        $q=$this->db->prepare('SELECT * FROM phone_attempts WHERE account=? AND (provider_call_id=? OR call_record_id=?)');$q->execute([$account,$externalId,$recordId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $attempt=count($rows)===1?$rows[0]:null;
        if(!$attempt){$metadata=$payload['metadata']??[];if(!is_array($metadata))return;$id=$metadata['crm_attempt_id']??null;$token=$metadata['crm_correlation']??null;if(!is_string($id)||!is_string($token))return;
            $q=$this->db->prepare('SELECT * FROM phone_attempts WHERE attempt_id=? AND account=?');$q->execute([$id,$account]);$candidate=$q->fetch(PDO::FETCH_ASSOC);
            if(!$candidate||!hash_equals($candidate['correlation_token'],$token))return;$attempt=$candidate;
        }
        if($attempt['call_record_id']!==null&&(int)$attempt['call_record_id']!==$recordId)return;
        if($attempt['lead_id']!==null)$this->db->prepare("UPDATE call_records SET lead_id=?,user_id=?,link_status='matched' WHERE id=? AND link_status<>'manual'")->execute([$attempt['lead_id'],$attempt['user_id'],$recordId]);
        $this->db->prepare('UPDATE phone_attempts SET call_record_id=?,updated_at=CURRENT_TIMESTAMP WHERE attempt_id=?')->execute([$recordId,$attempt['attempt_id']]);
        // A recorded end supplied by the account's authenticated API confirms completion.
        $ended=$payload['ended_at']??null;if(is_string($ended)&&strtotime($ended)!==false){$this->db->prepare("UPDATE phone_attempts SET state='ended',active_user=NULL,active_extension=NULL,error_code=NULL,ended_at=?,updated_at=CURRENT_TIMESTAMP WHERE attempt_id=?")->execute([date('Y-m-d H:i:s',strtotime($ended)),$attempt['attempt_id']]);}
    }
}
