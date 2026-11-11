<?php
declare(strict_types=1);
namespace App;
final class McpAuth {
 public static function authorized(string $header,string $expected): bool {
  return preg_match('/^Bearer\s+(.+)$/i',$header,$m)===1 && hash_equals($expected,$m[1]);
 }
}
