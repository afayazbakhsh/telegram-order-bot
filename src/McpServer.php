<?php
declare(strict_types=1);
namespace App;
final class McpServer {
 public function __construct(private readonly OrderService $orders) {}
 public function handle(array $request): array {
  $id=$request['id']??null;$method=$request['method']??null;if(!is_string($method))return $this->error($id,-32600,'Invalid request.');
  $params=is_array($request['params']??null)?$request['params']:[];
  try {
   if($method==='initialize')return ['jsonrpc'=>'2.0','id'=>$id,'result'=>['protocolVersion'=>'2025-03-26','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'telegram-voice-mcp-mvp','version'=>'0.1.0']]];
   if($method==='notifications/initialized'||str_starts_with($method,'notifications/'))return [];
   if($method==='ping')return ['jsonrpc'=>'2.0','id'=>$id,'result'=>new \stdClass()];
   if($method==='tools/list')return ['jsonrpc'=>'2.0','id'=>$id,'result'=>['tools'=>$this->toolDefinitions()]];
   if($method==='tools/call')return ['jsonrpc'=>'2.0','id'=>$id,'result'=>$this->callTool((string)($params['name']??''),is_array($params['arguments']??null)?$params['arguments']:[])];
   return $this->error($id,-32601,'Method not found.');
  } catch(\InvalidArgumentException $e){return $this->error($id,-32602,$e->getMessage());}
    catch(\Throwable $e){return ['jsonrpc'=>'2.0','id'=>$id,'result'=>['content'=>[['type'=>'text','text'=>$e->getMessage()]],'isError'=>true]];}
 }
 private function callTool(string $name,array $a): array {
  $result=match($name){
   'list_pending_orders'=>['orders'=>$this->orders->pending(isset($a['limit'])?(int)$a['limit']:20)],
   'get_order'=>$this->orders->claim((string)($a['order_id']??'')),
   'get_audio'=>$this->getAudio($a),
   'submit_transcript'=>$this->orders->submit((string)($a['order_id']??''),(string)($a['lease_token']??''),(string)($a['transcript']??''),isset($a['language'])?(string)$a['language']:null,is_array($a['processing_metadata']??null)?$a['processing_metadata']:null),
   'fail_order'=>$this->failOrder($a),
   default=>throw new \InvalidArgumentException('Unknown tool.')
  };
  if($name==='get_audio')return ['content'=>[['type'=>'audio','data'=>$result['data'],'mimeType'=>$result['mime_type']],['type'=>'text','text'=>json_encode(['order_id'=>$result['order_id'],'mime_type'=>$result['mime_type'],'byte_size'=>$result['byte_size']],JSON_UNESCAPED_SLASHES)]],'isError'=>false];
  return ['content'=>[['type'=>'text','text'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)]],'isError'=>false];
 }
 private function getAudio(array $a): array { $id=(string)($a['order_id']??'');$f=$this->orders->audioForClaim($id,(string)($a['lease_token']??''));$data=file_get_contents($f['audio_path']);if(!is_string($data))throw new \RuntimeException('Could not read private audio.');return ['order_id'=>$id,'mime_type'=>$f['mime_type'],'byte_size'=>(int)$f['byte_size'],'data'=>base64_encode($data)]; }
 private function failOrder(array $a): array { $this->orders->fail((string)($a['order_id']??''),(string)($a['lease_token']??''),(string)($a['reason']??'Unspecified processing error.'));return ['status'=>'failed']; }
 private function toolDefinitions(): array { return [
  ['name'=>'list_pending_orders','description'=>'List pending voice transcription orders. Returns minimal metadata without chat identifiers.','inputSchema'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50]],'additionalProperties'=>false]],
  ['name'=>'get_order','description'=>'Claim one pending order and return its metadata, short-lived audio URL, and lease token. Process it once.','inputSchema'=>['type'=>'object','properties'=>['order_id'=>['type'=>'string','pattern'=>'^[a-f0-9]{32}$']],'required'=>['order_id'],'additionalProperties'=>false]],
  ['name'=>'get_audio','description'=>'Return the claimed order audio as an MCP audio content block when the file is at most 3 MiB. Requires the order lease token.','inputSchema'=>['type'=>'object','properties'=>['order_id'=>['type'=>'string'],'lease_token'=>['type'=>'string']],'required'=>['order_id','lease_token'],'additionalProperties'=>false]],
  ['name'=>'submit_transcript','description'=>'Save a transcript for a claimed order and deliver it to the original Telegram chat. Identical retries are idempotent.','inputSchema'=>['type'=>'object','properties'=>['order_id'=>['type'=>'string'],'lease_token'=>['type'=>'string'],'transcript'=>['type'=>'string','minLength'=>1,'maxLength'=>50000],'language'=>['type'=>'string'],'processing_metadata'=>['type'=>'object']],'required'=>['order_id','lease_token','transcript'],'additionalProperties'=>false]],
  ['name'=>'fail_order','description'=>'Mark a claimed order failed when transcription cannot be completed.','inputSchema'=>['type'=>'object','properties'=>['order_id'=>['type'=>'string'],'lease_token'=>['type'=>'string'],'reason'=>['type'=>'string','maxLength'=>500]],'required'=>['order_id','lease_token','reason'],'additionalProperties'=>false]]
 ]; }
 private function error(mixed $id,int $code,string $message): array { return ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]]; }
}
