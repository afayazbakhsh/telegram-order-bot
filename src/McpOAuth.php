<?php
declare(strict_types=1);

namespace App;

final class McpOAuth
{
    private const CLIENT_AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];
    public function __construct(private Config $config, private \PDO $db) {}

    public function handle(string $path, string $method): bool
    {
        $base = $this->config->baseUrl;
        if ($method === 'GET' && in_array($path, [
            '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-protected-resource',
        ], true)) {
            $this->json(['resource' => $base . '/mcp', 'authorization_servers' => [$base],
                'bearer_methods_supported' => ['header'], 'scopes_supported' => ['mcp']]);
        } elseif ($method === 'GET' && $path === '/.well-known/oauth-authorization-server') {
            $this->json(['issuer' => $base, 'authorization_endpoint' => $base . '/oauth/authorize',
                'token_endpoint' => $base . '/oauth/token', 'registration_endpoint' => $base . '/oauth/register',
                'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => self::CLIENT_AUTH_METHODS,
                'scopes_supported' => ['mcp']]);
        } elseif ($method === 'POST' && $path === '/oauth/register') {
            $this->register();
        } elseif (in_array($method, ['GET', 'POST'], true) && $path === '/oauth/authorize') {
            $this->authorize($method);
        } elseif ($method === 'POST' && $path === '/oauth/token') {
            $this->exchange();
        } else {
            return false;
        }
        return true;
    }

    public function accepts(string $header): bool
    {
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $match)) {
            return false;
        }
        $this->createStorage();
        $query = $this->db->prepare("SELECT 1 FROM oauth_credentials WHERE hash = ? AND kind = 'access' AND expires > ?");
        $query->execute([hash('sha256', $match[1]), time()]);
        return (bool) $query->fetchColumn();
    }

    private function register(): void
    {
        $input = json_decode((string) file_get_contents('php://input', false, null, 0, 8192), true);
        $uris = $input['redirect_uris'] ?? null;
        $method = $input['token_endpoint_auth_method'] ?? 'client_secret_basic';
        if (!is_array($uris) || !array_is_list($uris) || count($uris) < 1 || count($uris) > 8
            || !in_array($method, self::CLIENT_AUTH_METHODS, true)) {
            $this->json(['error' => 'invalid_client_metadata'], 400);
            return;
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !$this->allowedRedirect($uri)) {
                $this->logRejectedRedirect($uri);
                $this->json(['error' => 'invalid_redirect_uri'], 400);
                return;
            }
        }

        $encoded = $this->base64url(json_encode(['redirects' => $uris, 'method' => $method], JSON_THROW_ON_ERROR));
        $client = $encoded . '.' . $this->sign('client', $encoded);
        $response = ['client_id' => $client, 'redirect_uris' => $uris, 'token_endpoint_auth_method' => $method,
            'response_types' => ['code'], 'grant_types' => ['authorization_code', 'refresh_token']];
        if ($method !== 'none') {
            $response['client_secret'] = $this->sign('client-secret', $client);
            $response['client_secret_expires_at'] = 0;
        }
        $this->json($response, 201);
    }

    private function authorize(string $method): void
    {
        $client = $this->field($_GET, 'client_id');
        $redirect = $this->field($_GET, 'redirect_uri');
        $challenge = $this->field($_GET, 'code_challenge');
        $state = $this->field($_GET, 'state');
        if (!$this->clientMatches($client, $redirect)
            || $this->field($_GET, 'response_type') !== 'code'
            || $this->field($_GET, 'code_challenge_method') !== 'S256'
            || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)
            || !$this->validResource($_GET)
            || !in_array($this->field($_GET, 'scope'), ['', 'mcp'], true)) {
            $this->json(['error' => 'invalid_request'], 400);
            return;
        }

        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'");
        $binding = http_build_query([$client, $redirect, $challenge, $state]);
        if ($method === 'POST') {
            [$deadline, $signature] = array_pad(explode('.', $this->field($_POST, 'approval'), 2), 2, '');
            if (!ctype_digit($deadline) || (int) $deadline < time()
                || !hash_equals($this->sign('approval', $deadline . ':' . $binding), $signature)
                || !hash_equals($this->config->mcpToken, $this->field($_POST, 'operator_key'))) {
                $this->json(['error' => 'access_denied'], 403);
                return;
            }
            $this->createStorage();
            $code = $this->issue('code', $client, 120, $redirect, $challenge);
            $parameters = ['code' => $code];
            if ($state !== '') {
                $parameters['state'] = $state;
            }
            header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?')
                . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), true, 303);
            return;
        }

        $deadline = (string) (time() + 600);
        $approval = $deadline . '.' . $this->sign('approval', $deadline . ':' . $binding);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><title>Connect voice bot</title><h1>Connect the voice bot to ChatGPT</h1>'
            . '<form method="post"><input type="hidden" name="approval" value="' . $approval . '">'
            . '<label>Operator key <input type="password" name="operator_key" required></label> '
            . '<button type="submit">Authorize</button></form>';
    }

    private function exchange(): void
    {
        $grant = $this->field($_POST, 'grant_type');
        $client = $this->authenticatedClient();
        if ($client === null) {
            $this->json(['error' => 'invalid_client'], 401);
            return;
        }
        $redirect = $this->field($_POST, 'redirect_uri');
        $verifier = $this->field($_POST, 'code_verifier');
        if (!$this->validResource($_POST)) {
            $this->json(['error' => 'invalid_target'], 400);
            return;
        }
        if ($grant === 'authorization_code' && $this->clientMatches($client, $redirect)
            && preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
            $kind = 'code';
            $credential = $this->field($_POST, 'code');
            $challenge = $this->base64url(hash('sha256', $verifier, true));
        } elseif ($grant === 'refresh_token') {
            $kind = 'refresh';
            $credential = $this->field($_POST, 'refresh_token');
            $redirect = $challenge = '';
        } else {
            $this->json(['error' => 'invalid_grant'], 400);
            return;
        }

        $this->createStorage();
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('DELETE FROM oauth_credentials
                WHERE hash = ? AND kind = ? AND client = ? AND expires > ? AND redirect = ? AND challenge = ?');
            $query->execute([hash('sha256', $credential), $kind, $client, time(), $redirect, $challenge]);
            if ($query->rowCount() !== 1) {
                $this->db->rollBack();
                $this->json(['error' => 'invalid_grant'], 400);
                return;
            }
            $cleanup = $this->db->prepare('DELETE FROM oauth_credentials WHERE expires <= ?');
            $cleanup->execute([time()]);
            $access = $this->issue('access', $client, 3600);
            $refresh = $this->issue('refresh', $client, 30 * 86400);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        $this->json(['access_token' => $access, 'refresh_token' => $refresh,
            'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'mcp']);
    }

    private function createStorage(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS oauth_credentials (
            hash TEXT PRIMARY KEY, kind TEXT NOT NULL, client TEXT NOT NULL, expires INTEGER NOT NULL,
            redirect TEXT NOT NULL, challenge TEXT NOT NULL
        )');
    }

    private function issue(string $kind, string $client, int $ttl, string $redirect = '', string $challenge = ''): string
    {
        $secret = bin2hex(random_bytes(32));
        $query = $this->db->prepare('INSERT INTO oauth_credentials VALUES (?, ?, ?, ?, ?, ?)');
        $query->execute([hash('sha256', $secret), $kind, $client, time() + $ttl, $redirect, $challenge]);
        return $secret;
    }

    private function logRejectedRedirect(mixed $uri): void
    {
        $parts = is_string($uri) ? parse_url($uri) : false;
        $parts = is_array($parts) ? $parts : [];
        $entry = [
            'time' => gmdate(DATE_ATOM),
            'event' => 'oauth_registration_redirect_rejected',
            'scheme' => substr($parts['scheme'] ?? '', 0, 16),
            'host' => substr($parts['host'] ?? '', 0, 253),
            'path' => substr($parts['path'] ?? '', 0, 255),
            'port' => $parts['port'] ?? null,
            'has_query' => isset($parts['query']),
            'has_fragment' => isset($parts['fragment']),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (@file_put_contents($this->config->logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false) {
            @chmod($this->config->logPath, 0600);
        }
    }

    private function allowedRedirect(string $redirect): bool
    {
        return in_array($redirect, [
            'https://chatgpt.com/connector_platform_oauth_redirect',
            'https://chat.openai.com/connector_platform_oauth_redirect',
        ], true) || preg_match('#\Ahttps://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]{1,128}\z#', $redirect) === 1;
    }

    private function clientMatches(string $client, string $redirect): bool
    {
        $registration = $this->clientRegistration($client);
        return $registration !== null && in_array($redirect, $registration['redirects'], true);
    }

    private function clientRegistration(string $client): ?array
    {
        [$encoded, $signature] = array_pad(explode('.', $client, 2), 2, '');
        if ($encoded === '' || !hash_equals($this->sign('client', $encoded), $signature)) {
            return null;
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $registration = json_decode($decoded, true);
        if (is_array($registration)) {
            return $registration;
        }
        return $this->allowedRedirect($decoded) ? ['redirects' => [$decoded], 'method' => 'none'] : null;
    }

    private function authenticatedClient(): ?string
    {
        $client = $this->field($_POST, 'client_id');
        $secret = $this->field($_POST, 'client_secret');
        $method = $secret === '' ? 'none' : 'client_secret_post';
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $header, $match)) {
            $decoded = base64_decode($match[1], true);
            if ($decoded === false || !str_contains($decoded, ':') || $secret !== '') {
                return null;
            }
            [$basicClient, $secret] = array_map('urldecode', explode(':', $decoded, 2));
            if ($client !== '' && $client !== $basicClient) {
                return null;
            }
            $client = $basicClient;
            $method = 'client_secret_basic';
        }
        $registration = $this->clientRegistration($client);
        if ($registration === null || $registration['method'] !== $method) {
            return null;
        }
        if ($method !== 'none' && !hash_equals($this->sign('client-secret', $client), $secret)) {
            return null;
        }
        return $client;
    }

    private function validResource(array $input): bool
    {
        return !isset($input['resource']) || $input['resource'] === $this->config->baseUrl . '/mcp';
    }

    private function field(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? $input[$key] : '';
    }

    private function sign(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose . ':' . $value, $this->config->mcpToken);
    }

    private function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
