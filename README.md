# Telegram Voice-to-Text MCP MVP

Isolated PHP 8.3 and SQLite intake/delivery service for testing a Telegram voice transcription workflow. It receives voice/audio messages, stores a private temporary recording, creates an order, exposes MCP tools, and delivers a submitted transcript. It does not transcribe audio on its own.

## Feasibility boundaries

- Telegram intake and delivery require an isolated HTTPS deployment and a bot token; no live deployment or Telegram test has been performed.
- The stateless Streamable HTTP MCP endpoint implements JSON-RPC initialization, tools, notification and batch handling, bearer authentication, and Origin allowlisting. It uses a small direct implementation instead of the official PHP SDK, which is currently experimental and would add PSR dependencies to the shared-hosting MVP. MCP Inspector and ChatGPT connector compatibility remain untested.
- MCP tools return audio as an MCP audio content block up to 3 MiB and provide a short-lived signed HTTPS URL as fallback. This does not prove that a given ChatGPT client passes MCP audio content to its transcription capability.
- OpenAI documents full custom MCP connections for Business and Enterprise/Edu; Pro supports read/fetch only. Plus is not listed for custom MCP connections. Skill availability also depends on plan and workspace settings. See [custom MCP requirements](https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt) and [Skills availability](https://help.openai.com/en/articles/20001066-skills-in-chatgpt).
- Paid ChatGPT conversations can accept supported audio uploads, but this does not provide an event-triggered transcription API. A ChatGPT Skill is user-triggered here; the full workflow is not automated.
- No paid transcription API is included. Live end-to-end tests with Telegram and ChatGPT are NOT TESTED.

## Architecture

Telegram webhook -> PHP endpoint -> SQLite and private audio storage -> MCP tools -> manually triggered ChatGPT Skill -> transcript submission -> Telegram delivery.

Incoming orders are atomically claimed with a lease. Delivery has a separate status and chunk cursor. Repeated identical transcript submission does not redeliver completed content. Audio links are signed, order-scoped, and expire after five minutes. Transcript text is not written to logs.

## Requirements and configuration

PHP 8.3 with pdo_sqlite, curl, fileinfo, and mbstring. No runtime Composer dependencies.

Set configuration through PHP-FPM environment variables or a private-config.php file in the project root, outside the public document root. Start from private-config.example.php. Set APP_BASE_URL to the HTTPS origin only. Set MCP_ALLOWED_ORIGINS to an explicit comma-separated allowlist; include only the site and ChatGPT origins required by the actual connector. Generate independent random values for APP_SECRET, TELEGRAM_WEBHOOK_SECRET, and MCP_BEARER_TOKEN. Never commit private-config.php, credentials, audio, database files, or transcripts.

## Isolated cPanel deployment

1. Create a new subdomain for this MVP. Set its document root to this project's public directory. Do not reuse Crowdio's deployment directory or webhook.
2. Upload the repository outside public_html, for example under /home/CPANEL_USER/private/telegram-voice-mcp-mvp, and point the subdomain document root at that project's public/ directory.
3. Copy private-config.example.php to private-config.php beside src/. Fill in the hostname, independent secrets, Telegram token, and absolute paths for database, audio, and log files.
4. Keep SQLite, audio, logs, and configuration outside the public document root. Set private-config.php to mode 600 and storage directories to mode 700.
5. Select PHP 8.3 and enable pdo_sqlite, curl, fileinfo, and mbstring. Run composer test and composer lint from the project directory.
6. After the endpoint and credentials are configured, set the Telegram webhook URL to https://YOUR-HOST/telegram/webhook with TELEGRAM_WEBHOOK_SECRET. Do not point production traffic at this code before validating the separate deployment.
7. Add a daily cPanel cron job to run scripts/purge-expired.php using the same private configuration.

## HTTP endpoints

- POST /telegram/webhook: Telegram updates protected by X-Telegram-Bot-Api-Secret-Token.
- POST /mcp: Streamable HTTP MCP endpoint protected by Authorization: Bearer.
- GET /audio/{order-id}: short-lived signed audio download.
- GET /health: minimal health response without secrets or paths.

Configure Telegram's webhook only after deployment using the Bot API setWebhook method. Keep the bot token out of shell history and repository files.

## MCP tools

- list_pending_orders: bounded list with minimal metadata.
- get_order: atomically claims an order and returns metadata, a lease token, and signed audio URL.
- get_audio: returns the claimed file as MCP audio content up to 3 MiB; larger files require URL fallback.
- submit_transcript: saves the transcript and delivers it to the original Telegram chat.
- fail_order: records a bounded failure reason for an order that could not be transcribed.

The Telegram token is server-side only. The MCP bearer token grants access to customer audio and transcript writes; use a long random secret and HTTPS.

## Skill and feasibility checks

Upload skill/telegram-voice-transcription-operator/SKILL.md where ChatGPT Skills are enabled and connect the deployed MCP endpoint if the account supports it. First verify tools/list and a synthetic test submission/delivery. Then send a short known-sentence voice recording, verify the client actually receives playable audio from get_audio, transcribe it, submit it, and compare with the known sentence. Record duration, processing time, component, manual steps, correctness, and delivery status. If the MCP client only exposes a link or text, do not claim that transcription occurred. The Skill is manually invoked and does not run automatically on webhook arrival.

## Tests and current status

Automated tests cover duplicate Telegram update handling, rejected file type, size and duration limits, missing audio, bearer authorization logic, invalid order ID, MCP initialization/tool listing/audio content, repeated transcript submission, delivery failure retry, long transcript chunking, and cross-connection claim exclusivity.

The current environment has no PHP or Composer runtime, so PHP syntax checks and the test suite have NOT BEEN RUN here. GitHub Actions is configured to run them on pushes and pull requests. Live HTTP authorization, MCP Inspector compatibility, ChatGPT audio processing, Telegram delivery, cPanel deployment, and full automation are NOT TESTED.

Replacing the old repository contents does not deploy or alter any live Crowdio hosting. The new code contains no bot token or production data. Audio retention is controlled by RETENTION_DAYS; the cleanup script removes expired files and corresponding order/transcript rows.


## Connect ChatGPT with OAuth

Enter the deployed HTTPS URL ending in /mcp in ChatGPT's custom plugin form
and select OAuth. On the authorization page hosted on your own bot domain,
enter the MCP_BEARER_TOKEN from private-config.php as the operator key.
ChatGPT receives a separate, short lived access token and a rotating refresh
token. Existing clients can still send the configured static bearer token.

If an existing deployment is updated manually, replace src/App.php and
public/.htaccess and add src/McpOAuth.php. Keep the private configuration,
database, audio directory and Telegram webhook. The OAuth table is created
on first use. Use a fresh, random MCP_BEARER_TOKEN for the operator key.
