<?php
declare(strict_types=1);
namespace App;
interface TelegramGateway { public function sendMessage(string $chatId,string $text): void; public function download(string $fileId,string $target,int $maxBytes): array; }
