<?php
declare(strict_types=1);

final class GitHubState
{
    private string $key;
    private ?string $sha = null;
    private ?string $digest = null;
    private ?array $pending = null;

    public function __construct(string $token, private string $repo, private string $githubToken)
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repo)) {
            throw new RuntimeException('Invalid state repository');
        }
        $this->key = hash('sha256', "crowdio-order-state-v1\0" . $token, true);
    }

    private function request(?array $body = null): ?array
    {
        $curl = curl_init('https://api.github.com/repos/' . $this->repo . '/contents/.runtime/orders.enc');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->githubToken,
                'Accept: application/vnd.github+json', 'Content-Type: application/json', 'User-Agent: Crowdio'],
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status === 404 && $body === null) {
            return null;
        }
        if ($status < 200 || $status >= 300 || !is_string($response)) {
            throw new RuntimeException('Encrypted state storage unavailable');
        }
        return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    }

    public function restore(string $path): void
    {
        $result = $this->request();
        if ($result === null) {
            return;
        }
        $this->sha = $result['sha'];
        $encoded = base64_decode($result['content'], true);
        $token = is_string($encoded) ? base64_decode(strtr($encoded, '-_', '+/'), true) : false;
        if (!is_string($token) || strlen($token) < 73 || $token[0] !== "\x80" ||
            !hash_equals(substr($token, -32), hash_hmac('sha256', substr($token, 0, -32),
                substr($this->key, 0, 16), true))) {
            throw new RuntimeException('Cannot authenticate encrypted SQLite checkpoint');
        }
        $plain = openssl_decrypt(substr($token, 25, -32), 'aes-128-cbc', substr($this->key, 16),
            OPENSSL_RAW_DATA, substr($token, 9, 16));
        if (!is_string($plain) || !str_starts_with($plain, "SQLite format 3\0")) {
            throw new RuntimeException('Invalid SQLite checkpoint');
        }
        $temporary = $path . '.restore';
        if (file_put_contents($temporary, $plain) !== strlen($plain) || !rename($temporary, $path)) {
            throw new RuntimeException('Cannot restore SQLite checkpoint');
        }
    }

    public function save(PDO $db, string $path): void
    {
        if ($this->pending !== null) {
            $this->uploadPending();
        }
        // The CLI holds a single-worker lock and SQLite uses its default rollback
        // journal. VACUUM INTO snapshots committed data independently of the journal mode.
        $snapshot = $path . '.snapshot-' . bin2hex(random_bytes(8));
        try {
            $db->exec('VACUUM INTO ' . $db->quote($snapshot));
            $plain = file_get_contents($snapshot);
        } finally {
            if (is_file($snapshot)) {
                unlink($snapshot);
            }
        }
        if (!is_string($plain)) {
            throw new RuntimeException('Cannot read SQLite snapshot');
        }
        $digest = hash('sha256', $plain);
        if ($digest === $this->digest) {
            return;
        }
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plain, 'aes-128-cbc', substr($this->key, 16), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new RuntimeException('Cannot encrypt SQLite snapshot');
        }
        $token = "\x80" . pack('J', time()) . $iv . $encrypted;
        $token .= hash_hmac('sha256', $token, substr($this->key, 0, 16), true);
        $body = ['message' => 'Save encrypted order checkpoint [skip ci]', 'branch' => 'main',
            'content' => base64_encode(strtr(base64_encode($token), '+/', '-_'))];
        if ($this->sha !== null) {
            $body['sha'] = $this->sha;
        }
        $this->pending = ['body' => $body, 'digest' => $digest];
        $this->uploadPending();
    }

    private function uploadPending(): void
    {
        $body = $this->pending['body'];
        try {
            $this->sha = $this->request($body)['content']['sha'];
        } catch (RuntimeException $error) {
            // The upload can succeed even when its HTTP response is lost.
            $remote = $this->request();
            if (!$remote || preg_replace('/\s+/', '', $remote['content']) !== $body['content']) {
                throw $error;
            }
            $this->sha = $remote['sha'];
        }
        $this->digest = $this->pending['digest'];
        $this->pending = null;
    }
}
