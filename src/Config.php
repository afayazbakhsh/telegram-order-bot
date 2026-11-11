<?php
declare(strict_types=1);
namespace App;
final class Config {
 public function __construct(public readonly string $baseUrl, public readonly string $appSecret, public readonly string $telegramToken, public readonly string $webhookSecret, public readonly string $mcpToken, public readonly string $dbPath, public readonly string $storagePath, public readonly string $logPath, public readonly int $maxAudioBytes=20971520, public readonly int $maxDurationSeconds=300, public readonly int $retentionDays=7, public readonly array $allowedOrigins=[]) {}
 public static function fromEnvironment(): self {
  $file=dirname(__DIR__).'/private-config.php';$local=is_file($file)?require $file:[];
  if(!is_array($local))throw new \RuntimeException('private-config.php must return an array.');
  $get=static function(string $k, ?string $d=null)use($local): string { $v=getenv($k);if($v!==false)return $v;if(isset($local[$k])&&is_scalar($local[$k]))return (string)$local[$k];if($d!==null)return $d;throw new \RuntimeException("Missing environment setting: $k"); };
  $baseUrl=rtrim($get('APP_BASE_URL'),'/');$origins=array_values(array_filter(array_map('trim',explode(',',$get('MCP_ALLOWED_ORIGINS',$baseUrl))),static fn($v)=>$v!==''));
  $c=new self($baseUrl,$get('APP_SECRET'),$get('TELEGRAM_BOT_TOKEN'),$get('TELEGRAM_WEBHOOK_SECRET'),$get('MCP_BEARER_TOKEN'),$get('DB_PATH'),$get('STORAGE_PATH'),$get('LOG_PATH'),(int)$get('MAX_AUDIO_BYTES','20971520'),(int)$get('MAX_DURATION_SECONDS','300'),(int)$get('RETENTION_DAYS','7'),$origins);
  foreach([$c->appSecret,$c->webhookSecret,$c->mcpToken] as $s) if(strlen($s)<32) throw new \RuntimeException('Secrets must each be at least 32 characters.');
  if(!str_starts_with($c->baseUrl,'https://')) throw new \RuntimeException('APP_BASE_URL must use HTTPS.');
  if(parse_url($c->baseUrl,PHP_URL_PATH)!==null&&parse_url($c->baseUrl,PHP_URL_PATH)!=='/') throw new \RuntimeException('APP_BASE_URL must be the site origin without a path.');
  foreach($c->allowedOrigins as $origin)if(!str_starts_with($origin,'https://')||parse_url($origin,PHP_URL_PATH)!==null&&parse_url($origin,PHP_URL_PATH)!=='/')throw new \RuntimeException('Allowed MCP origins must be HTTPS origins without paths.');
  if($c->maxAudioBytes<1||$c->maxDurationSeconds<1||$c->retentionDays<1) throw new \RuntimeException('Limits must be positive.');
  return $c;
 }
}
