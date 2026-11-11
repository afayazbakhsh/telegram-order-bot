<?php
declare(strict_types=1);
namespace App;
use PDO;
final class Database {
 private PDO $pdo;
 public function __construct(string $path) { $dir=dirname($path); if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) throw new \RuntimeException('Cannot create database directory.'); $this->pdo=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;'); $this->pdo->exec((string)file_get_contents(dirname(__DIR__).'/schema.sql')); @chmod($path,0600); }
 public function pdo(): PDO { return $this->pdo; }
}
