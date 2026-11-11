<?php
declare(strict_types=1);
namespace App;
final class App {
 private OrderService $orders; private Logger $log; private McpOAuth $oauth;
 public function __construct(private readonly Config $cfg) { $db=new Database($cfg->dbPath);$this->oauth=new McpOAuth($cfg,$db->pdo());$this->log=new Logger($cfg->logPath);$tg=new TelegramClient($cfg->telegramToken);$this->orders=new OrderService($db->pdo(),$cfg,$tg,$this->log); }
 public function handle(): void {
  $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$method=$_SERVER['REQUEST_METHOD']??'GET';
  try {
   if($this->oauth->handle($path,$method))return;
   if($path==='/health'&&$method==='GET'){$this->json(['status'=>'ok']);return;}
   if($path==='/telegram/webhook'&&$method==='POST'){$this->webhook();return;}
   if($path==='/mcp'&&$method==='POST'){$this->mcp();return;}
   if($path==='/mcp'&&in_array($method,['GET','DELETE'],true)){http_response_code(405);header('Allow: POST, GET');return;}
   if(preg_match('#^/audio/([a-f0-9]{32})$#',$path,$m)&&$method==='GET'){$this->audio($m[1]);return;}
   http_response_code(404);$this->json(['error'=>'not_found']);
  } catch(\InvalidArgumentException $e){http_response_code(400);$this->json(['error'=>$e->getMessage()]);}
    catch(\Throwable $e){$this->log->error('request_failed',['http_status'=>500]);http_response_code(500);$this->json(['error'=>'internal_error']);}
 }
 private function webhook(): void {
  if(!hash_equals($this->cfg->webhookSecret,(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??''))){http_response_code(401);$this->json(['error'=>'unauthorized']);return;}
  $raw=file_get_contents('php://input',false,null,0,$this->cfg->maxAudioBytes);$update=json_decode((string)$raw,true);if(!is_array($update)){http_response_code(400);$this->json(['error'=>'invalid_json']);return;}
  try{$this->orders->receive($update);$this->json(['ok'=>true]);}catch(\InvalidArgumentException $e){$this->json(['ok'=>true]);}catch(\Throwable $e){$this->log->error('webhook_processing_failed',['update_id'=>$update['update_id']??null]);http_response_code(500);$this->json(['error'=>'processing_failed']);}
 }
 private function mcp(): void {
  $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');if(!McpAuth::authorized($auth,$this->cfg->mcpToken)&&!$this->oauth->accepts($auth)){http_response_code(401);header('WWW-Authenticate: Bearer resource_metadata="'.$this->cfg->baseUrl.'/.well-known/oauth-protected-resource/mcp"');$this->json(['error'=>'unauthorized']);return;}
  $origin=(string)($_SERVER['HTTP_ORIGIN']??'');if($origin!==''&&!in_array($origin,$this->cfg->allowedOrigins,true)){http_response_code(403);$this->json(['error'=>'origin_forbidden']);return;}
  $accept=(string)($_SERVER['HTTP_ACCEPT']??'');if(stripos($accept,'application/json')===false||stripos($accept,'text/event-stream')===false){http_response_code(406);$this->json(['error'=>'accept_must_include_json_and_event_stream']);return;}
  if(stripos((string)($_SERVER['CONTENT_TYPE']??''),'application/json')!==0){http_response_code(415);$this->json(['error'=>'content_type_must_be_application_json']);return;}
  $raw=file_get_contents('php://input',false,null,0,1024*1024);$request=json_decode((string)$raw,true);if(!is_array($request)){http_response_code(400);$this->json(['error'=>'invalid_json']);return;}
  $server=new McpServer($this->orders);if(array_is_list($request)){if($request===[]){http_response_code(400);$this->json(['error'=>'empty_batch']);return;}$responses=[];foreach($request as $message){if(!is_array($message)){http_response_code(400);$this->json(['error'=>'invalid_batch_item']);return;}$response=$server->handle($message);if($response!==[])$responses[]=$response;}$out=$responses;}else{$out=$server->handle($request);}
  header('MCP-Protocol-Version: 2025-03-26');header('Cache-Control: no-cache, no-transform');if($out===[]){http_response_code(202);return;}$this->json($out);
 }
 private function audio(string $id): void {
  $resolved=$this->orders->resolveAudio($id,(int)($_GET['expires']??0),(string)($_GET['sig']??''));if(!$resolved){http_response_code(404);$this->json(['error'=>'not_found']);return;}
  header('Content-Type: '.$resolved['mime']);header('Content-Length: '.filesize($resolved['path']));header('Content-Disposition: attachment; filename="'.$id.'.audio"');header('Cache-Control: private, no-store');readfile($resolved['path']);
 }
 private function json(array $data): void { header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); }
}
