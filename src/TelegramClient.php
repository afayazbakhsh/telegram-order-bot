<?php
declare(strict_types=1);
namespace App;
final class TelegramClient implements TelegramGateway {
 public function __construct(private readonly string $token) {}
 public function sendMessage(string $chatId,string $text): void { $this->api('sendMessage',['chat_id'=>$chatId,'text'=>$text]); }
 public function download(string $fileId,string $target,int $maxBytes): array {
  $info=$this->api('getFile',['file_id'=>$fileId]); $remote=$info['file_path']??''; $declared=(int)($info['file_size']??0);
  if(!is_string($remote)||$remote===''||str_contains($remote,'..')||($declared>0&&$declared>$maxBytes)) throw new \RuntimeException('Invalid or oversized Telegram file.');
  $url='https://api.telegram.org/file/bot'.$this->token.'/'.ltrim($remote,'/'); $file=fopen($target,'wb'); if(!$file)throw new \RuntimeException('Cannot open private temporary audio file.');
  $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_FILE=>$file,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOPROGRESS=>false,CURLOPT_PROGRESSFUNCTION=>static function($r,float $total,float $now)use($maxBytes):int{return ($now>$maxBytes||$total>$maxBytes)?1:0;}]);
  $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);fclose($file);$written=is_file($target)?(int)filesize($target):0;
  if($ok===false||$status!==200||$written<1||$written>$maxBytes){@unlink($target);throw new \RuntimeException($written>$maxBytes?'Audio exceeds configured size limit.':'Telegram file download failed: '.($err?:'HTTP '.$status));}
  return ['bytes'=>$written,'telegram_file_size'=>$declared];
 }
 private function api(string $method,array $body): array {
  $ch=curl_init('https://api.telegram.org/bot'.$this->token.'/'.$method);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($body),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
  $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);$data=is_string($raw)?json_decode($raw,true):null;
  if($status!==200||!is_array($data)||!($data['ok']??false))throw new \RuntimeException('Telegram API request failed: '.($err?:'HTTP '.$status));return is_array($data['result']??null)?$data['result']:[];
 }
}
