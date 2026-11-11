<?php
declare(strict_types=1);
namespace App;
final class Logger {
 public function __construct(private readonly string $path) {}
 public function error(string $event,array $context=[]): void { $safe=array_intersect_key($context,array_flip(['order_id','update_id','http_status','error_code'])); $line=json_encode(['time'=>gmdate(DATE_ATOM),'level'=>'error','event'=>$event,'context'=>$safe],JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL; $dir=dirname($this->path); if(!is_dir($dir))@mkdir($dir,0700,true); @file_put_contents($this->path,$line,FILE_APPEND|LOCK_EX); @chmod($this->path,0600); }
}
