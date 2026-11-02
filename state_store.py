"""Encrypted checkpoint for short GitHub Actions payment sessions.

Only ciphertext is written to GitHub. The bot token is the encryption key material;
export/decrypt before rotating the token. Use a real database for public service.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen


class GitHubState:
    def __init__(self, bot_token, repository, github_token):
        from cryptography.fernet import Fernet
        key = hashlib.sha256(b'crowdio-order-state-v1\0' + bot_token.strip().encode()).digest()
        self.cipher = Fernet(base64.urlsafe_b64encode(key))
        self.url = f'https://api.github.com/repos/{repository}/contents/.runtime/orders.enc'
        self.github_token = github_token
        self.sha = None
        self.digest = None

    def request(self, method='GET', data=None):
        req = Request(self.url, method=method,
                      data=json.dumps(data).encode() if data is not None else None,
                      headers={'Authorization': 'Bearer ' + self.github_token,
                               'Accept': 'application/vnd.github+json',
                               'Content-Type': 'application/json', 'User-Agent': 'Crowdio'})
        try:
            with urlopen(req, timeout=20) as response:
                return json.load(response)
        except HTTPError as exc:
            if method == 'GET' and exc.code == 404:
                return None
            raise RuntimeError(f'Encrypted state storage unavailable (HTTP {exc.code})') from None

    def restore(self, path):
        result = self.request()
        if result is None:
            return
        self.sha = result['sha']
        encrypted = base64.b64decode(result['content'])
        try:
            plain = self.cipher.decrypt(encrypted)
        except Exception:
            raise RuntimeError('Cannot decrypt saved state. Restore the original state key before starting.') from None
        if not plain.startswith(b'SQLite format 3\0'):
            raise RuntimeError('Saved state is not a SQLite database')
        Path(path).parent.mkdir(parents=True, exist_ok=True)
        Path(path).write_bytes(plain)
        os.chmod(path, 0o600)
        self.digest = hashlib.sha256(plain).digest()

    def save(self, connection):
        plain = connection.serialize()
        digest = hashlib.sha256(plain).digest()
        if digest == self.digest:
            return
        body = {'message': 'Save encrypted order checkpoint [skip ci]',
                'content': base64.b64encode(self.cipher.encrypt(plain)).decode(), 'branch': 'main'}
        if self.sha:
            body['sha'] = self.sha
        result = self.request('PUT', body)
        self.sha = result['content']['sha']
        self.digest = digest
