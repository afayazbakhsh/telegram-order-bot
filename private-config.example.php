<?php
// Copy to private-config.php beside src/ and public/. Keep it outside the document root.
return [
    'APP_BASE_URL' => 'https://voice.example.com',
    'APP_SECRET' => 'replace-with-32-or-more-random-characters',
    'TELEGRAM_BOT_TOKEN' => '',
    'TELEGRAM_WEBHOOK_SECRET' => 'replace-with-32-or-more-random-characters',
    'MCP_BEARER_TOKEN' => 'replace-with-a-different-32-or-more-random-secret',
    'MCP_ALLOWED_ORIGINS' => 'https://voice.example.com,https://chatgpt.com,https://chat.openai.com',
    'DB_PATH' => '/home/CPANEL_USER/private/telegram-voice-mcp-mvp/orders.sqlite',
    'STORAGE_PATH' => '/home/CPANEL_USER/private/telegram-voice-mcp-mvp/audio',
    'LOG_PATH' => '/home/CPANEL_USER/private/telegram-voice-mcp-mvp/app.log',
    'MAX_AUDIO_BYTES' => 20971520,
    'MAX_DURATION_SECONDS' => 300,
    'RETENTION_DAYS' => 7,
];
