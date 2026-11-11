<?php
declare(strict_types=1);
namespace App;
use PDO;
final class OrderService {
 private const MIME_EXT=['audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/opus'=>'opus','audio/mpeg'=>'mp3','audio/mp4'=>'m4a','audio/aac'=>'aac','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/flac'=>'flac','audio/webm'=>'webm'];
 public function __construct(private readonly PDO $db,private readonly Config $cfg,private readonly TelegramGateway $telegram,private readonly Logger $log) {}
 public function receive(array $update): string {
  $updateId=filter_var($update['update_id']??null,FILTER_VALIDATE_INT);if($updateId===false||$updateId<0)throw new \InvalidArgumentException('Invalid update id.');
  $existing=$this->db->prepare('SELECT id FROM orders WHERE update_id=?');$existing->execute([$updateId]);if($id=$existing->fetchColumn())return (string)$id;
  $msg=$update['message']??$update['edited_message']??null;if(!is_array($msg)||!is_array($msg['chat']??null))throw new \InvalidArgumentException('Unsupported update.');
  $chatId=(string)($msg['chat']['id']??'');if($chatId===''||isset($msg['text'])&&$msg['text']==='/start'){if($chatId!=='')$this->telegram->sendMessage($chatId,'سلام! یک پیام صوتی یا فایل صوتی بفرستید تا متن آن آماده شود.');return '';}
  $audio=$msg['voice']??$msg['audio']??null;if(!is_array($audio))return '';
  $fileId=$audio['file_id']??null;$mime=strtolower((string)($audio['mime_type']??(isset($msg['voice'])?'audio/ogg':'')));$duration=(int)($audio['duration']??0);$size=(int)($audio['file_size']??0);
  if(!is_string($fileId)||$fileId===''||!isset(self::MIME_EXT[$mime]))throw new \InvalidArgumentException('Unsupported audio format.');
  if($duration<1||$duration>$this->cfg->maxDurationSeconds)throw new \InvalidArgumentException('Audio duration is outside configured limits.');
  if($size>$this->cfg->maxAudioBytes)throw new \InvalidArgumentException('Audio exceeds configured size limit.');
  if(!is_dir($this->cfg->storagePath)&&!mkdir($this->cfg->storagePath,0700,true)&&!is_dir($this->cfg->storagePath))throw new \RuntimeException('Cannot create private audio directory.');
  @chmod($this->cfg->storagePath,0700);$id=bin2hex(random_bytes(16));$tmp=$this->cfg->storagePath.'/'.$id.'.part';$path=$this->cfg->storagePath.'/'.$id.'.'.self::MIME_EXT[$mime];
  $download=$this->telegram->download($fileId,$tmp,$this->cfg->maxAudioBytes);$finfo=new \finfo(FILEINFO_MIME_TYPE);$detected=(string)$finfo->file($tmp);
  if(!isset(self::MIME_EXT[$detected])||!$this->mimeCompatible($mime,$detected)){@unlink($tmp);throw new \InvalidArgumentException('Audio content does not match its declared type.');}
  if(!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Cannot finalize private audio file.');}@chmod($path,0600);
  try{$q=$this->db->prepare('INSERT INTO orders(id,update_id,chat_id,telegram_user_id,telegram_file_id,audio_path,mime_type,byte_size,duration_seconds,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$now=time();$q->execute([$id,$updateId,$chatId,(string)($msg['from']['id']??''),$fileId,$path,$mime,$download['bytes'],$duration,'pending',$now,$now]);}
  catch(\Throwable $e){@unlink($path);$q=$this->db->prepare('SELECT id FROM orders WHERE update_id=?');$q->execute([$updateId]);$already=$q->fetchColumn();if($already)return (string)$already;throw $e;}
  try{$this->telegram->sendMessage($chatId,'پیام صوتی دریافت شد و در صف تبدیل به متن قرار گرفت.');}catch(\Throwable $e){$this->log->error('receipt_ack_failed',['order_id'=>$id]);}
  return $id;
 }
 private function mimeCompatible(string $expected,string $actual): bool { return $expected===$actual||($expected==='audio/ogg'&&in_array($actual,['application/ogg','audio/opus'],true))||($expected==='audio/wav'&&$actual==='audio/x-wav')||($expected==='audio/x-wav'&&$actual==='audio/wav'); }
 public function pending(int $limit=20): array { $q=$this->db->prepare("SELECT id,mime_type,byte_size,duration_seconds,created_at FROM orders WHERE status='pending' ORDER BY created_at LIMIT ?");$q->bindValue(1,max(1,min(50,$limit)),PDO::PARAM_INT);$q->execute();return $q->fetchAll(); }
 public function claim(string $id): array {
  if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new \InvalidArgumentException('Invalid order id.');
  $lease=bin2hex(random_bytes(24));$now=time();$this->db->beginTransaction();try{$q=$this->db->prepare("UPDATE orders SET status='processing',lease_token=?,lease_until=?,updated_at=? WHERE id=? AND (status='pending' OR (status='processing' AND lease_until<?))");$q->execute([$lease,$now+900,$now,$id,$now]);if($q->rowCount()!==1){$this->db->rollBack();throw new \RuntimeException('Order is not pending or is already claimed.');}$q=$this->db->prepare('SELECT id,mime_type,byte_size,duration_seconds,audio_path,lease_token FROM orders WHERE id=?');$q->execute([$id]);$order=$q->fetch();if(!$order||!is_file($order['audio_path'])){$q=$this->db->prepare("UPDATE orders SET status='failed',failure_reason='Audio file is missing.',updated_at=? WHERE id=?");$q->execute([time(),$id]);$this->db->commit();throw new \RuntimeException('Order audio file is missing; order marked failed.');}$this->db->commit();$order['audio_url']=$this->audioUrl($id,$order['audio_path']);unset($order['audio_path']);return $order;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function audioUrl(string $id,string $path): string { $expires=time()+300;$sig=hash_hmac('sha256',$id.'|'.$expires.'|'.$path,$this->cfg->appSecret);return $this->cfg->baseUrl.'/audio/'.$id.'?expires='.$expires.'&sig='.$sig; }
 public function audioForClaim(string $id,string $lease,int $maxInlineBytes=3145728): array { $q=$this->db->prepare("SELECT audio_path,mime_type,byte_size FROM orders WHERE id=? AND status='processing' AND lease_token=? AND lease_until>=?");$q->execute([$id,$lease,time()]);$o=$q->fetch();if(!$o||!is_file($o['audio_path']))throw new \RuntimeException('Order lease is invalid or audio is missing.');if((int)$o['byte_size']>$maxInlineBytes)throw new \RuntimeException('Audio is too large for inline MCP delivery; use the expiring audio URL.');return $o; }
 public function resolveAudio(string $id,int $expires,string $sig): ?array { if($expires<time()||$expires>time()+300||!preg_match('/^[a-f0-9]{32}$/',$id))return null;$q=$this->db->prepare('SELECT audio_path,mime_type FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();if(!$o)return null;$want=hash_hmac('sha256',$id.'|'.$expires.'|'.$o['audio_path'],$this->cfg->appSecret);return hash_equals($want,$sig)&&is_file($o['audio_path'])?['path'=>$o['audio_path'],'mime'=>$o['mime_type']]:null; }
 public function fail(string $id,string $lease,string $reason): void { $q=$this->db->prepare("UPDATE orders SET status='failed',failure_reason=?,updated_at=? WHERE id=? AND status='processing' AND lease_token=?");$q->execute([mb_substr(strip_tags($reason),0,500),time(),$id,$lease]);if($q->rowCount()!==1)throw new \RuntimeException('Order lease is invalid or expired.'); }
 public function submit(string $id,string $lease,string $transcript,?string $language=null,?array $metadata=null): array {
  if(trim($transcript)===''||mb_strlen($transcript)>50000)throw new \InvalidArgumentException('Transcript must contain 1–50,000 characters.');$this->db->beginTransaction();
  try{$q=$this->db->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();if(!$o)throw new \InvalidArgumentException('Unknown order.');
   if($o['status']==='completed'){if(!hash_equals((string)$o['transcript'],$transcript))throw new \RuntimeException('Order already has a different transcript.');$this->db->commit();}
   else{if($o['status']!=='processing'||!hash_equals((string)$o['lease_token'],$lease)||((int)$o['lease_until']<time()))throw new \RuntimeException('Order lease is invalid or expired.');$q=$this->db->prepare("UPDATE orders SET status='completed',transcript=?,language=?,processing_metadata=?,delivery_status='pending',delivery_next_chunk=0,updated_at=? WHERE id=? AND status='processing' AND lease_token=?");$q->execute([$transcript,$language,isset($metadata)?json_encode($metadata,JSON_INVALID_UTF8_SUBSTITUTE):null,time(),$id,$lease]);if($q->rowCount()!==1)throw new \RuntimeException('Order was concurrently modified.');$this->db->commit();}
  }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  return $this->deliver($id);
 }
 public function deliver(string $id): array {
  $q=$this->db->prepare('SELECT chat_id,transcript,delivery_status,delivery_next_chunk FROM orders WHERE id=? AND status=\'completed\'');$q->execute([$id]);$o=$q->fetch();if(!$o)throw new \InvalidArgumentException('Unknown completed order.');if($o['delivery_status']==='sent')return ['status'=>'completed','delivery_status'=>'sent'];
  $q=$this->db->prepare("UPDATE orders SET delivery_status='sending',updated_at=? WHERE id=? AND (delivery_status IN ('pending','failed') OR (delivery_status='sending' AND updated_at<?))");$q->execute([time(),$id,time()-120]);if($q->rowCount()!==1)return ['status'=>'completed','delivery_status'=>'sending'];$parts=$this->splitText((string)$o['transcript'],3500);$start=(int)$o['delivery_next_chunk'];
  try{for($i=$start;$i<count($parts);$i++){$this->telegram->sendMessage((string)$o['chat_id'],$parts[$i]);$q=$this->db->prepare('UPDATE orders SET delivery_next_chunk=?,updated_at=? WHERE id=?');$q->execute([$i+1,time(),$id]);}$q=$this->db->prepare("UPDATE orders SET delivery_status='sent',delivered_at=?,updated_at=? WHERE id=?");$q->execute([time(),time(),$id]);return ['status'=>'completed','delivery_status'=>'sent'];}
  catch(\Throwable $e){$q=$this->db->prepare("UPDATE orders SET delivery_status='failed',delivery_error=?,updated_at=? WHERE id=?");$q->execute([mb_substr($e->getMessage(),0,500),time(),$id]);$this->log->error('transcript_delivery_failed',['order_id'=>$id]);return ['status'=>'completed','delivery_status'=>'failed','retryable'=>true];}
 }
 public static function splitText(string $s,int $max): array { $s=trim($s);if($s==='')return [''];$out=[];while(mb_strlen($s)>$max){$cut=mb_strrpos(mb_substr($s,0,$max+1),' ');if($cut===false||$cut<$max*0.6)$cut=$max;$out[]=mb_substr($s,0,$cut);$s=ltrim(mb_substr($s,$cut));}if($s!=='')$out[]=$s;return $out; }
}
