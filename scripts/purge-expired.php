<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use App\Config;use App\Database;
$cfg=Config::fromEnvironment();$db=(new Database($cfg->dbPath))->pdo();$cutoff=time()-$cfg->retentionDays*86400;
$q=$db->prepare('SELECT id,audio_path FROM orders WHERE updated_at<?');$q->execute([$cutoff]);$rows=$q->fetchAll();
foreach($rows as $row){if(is_file($row['audio_path']))@unlink($row['audio_path']);$d=$db->prepare('DELETE FROM orders WHERE id=?');$d->execute([$row['id']]);}
echo count($rows)." expired order(s) purged.\n";
