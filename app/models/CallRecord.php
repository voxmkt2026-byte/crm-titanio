<?php
require_once APP_PATH.'/core/Model.php';
final class CallRecord extends Model {
 protected string $table='call_records';
 private string $select="SELECT c.*,l.name lead_name,l.assigned_to,u.name agent_name,a.summary,a.transcript,a.sentiment,a.lead_temperature,a.sales_stage,a.overall_score,a.payload,a.provider analysis_provider,a.provider_model,a.analyzed_at FROM call_records c LEFT JOIN leads l ON l.id=c.lead_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN call_analyses a ON a.call_id=c.id";
 public function recent(int $uid,bool $all,int $limit=100):array{$sql=$this->select." WHERE c.outcome<>'voicemail'".($all?'':' AND l.assigned_to=:uid').' ORDER BY c.started_at DESC LIMIT '.max(1,min(200,$limit));$s=$this->db->prepare($sql);$s->execute($all?[]:[':uid'=>$uid]);return $s->fetchAll();}
 public function detail(int $id):?array{$s=$this->db->prepare($this->select.' WHERE c.id=:id LIMIT 1');$s->execute([':id'=>$id]);$r=$s->fetch();if(!$r)return null;$r['analysis_payload']=json_decode((string)($r['payload']??''),true)?:[];return $r;}
 public function forLead(int $id):array{$s=$this->db->prepare($this->select." WHERE c.lead_id=:id AND c.outcome<>'voicemail' ORDER BY c.started_at DESC");$s->execute([':id'=>$id]);return $s->fetchAll();}
}
