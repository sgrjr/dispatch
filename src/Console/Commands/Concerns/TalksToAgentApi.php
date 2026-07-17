<?php

namespace Sgrjr\Dispatch\Console\Commands\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared `--remote` client for the agent-loop verbs (§20 Phase 2).
 *
 * Reused by every `--remote` verb (WS1) and the session commands (WS2) so the
 * transport, token handling, and error posture are defined once. The session
 * token lives in a dotfile OUTSIDE the repo, owner-only (0600), and is deleted
 * the moment the server says it's dead (401). Transport is HTTPS-only outside a
 * local environment — a bearer over plaintext would undo the commissioning model.
 *
 * Mix into an Illuminate\Console\Command (uses $this->error/warn/line).
 */
trait TalksToAgentApi
{
    protected function agentBaseUrl(): ?string
    {
        // Fall back to the raw env var when the merged config lacks the nested
        // `agent.remote` key. mergeConfigFrom() is a SHALLOW array_merge: a host
        // that published config/dispatch.php before `agent.remote` existed keeps
        // its own (winning) `agent` block, so the package's `agent.remote` never
        // merges in and config('dispatch.agent.remote.url') is null even with
        // DISPATCH_AGENT_REMOTE_URL set. Republishing --force fixes the config;
        // this fallback means the client works without it.
        $url = trim((string) (config('dispatch.agent.remote.url') ?: env('DISPATCH_AGENT_REMOTE_URL', '')));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    protected function agentTokenPath(): string
    {
        // Same shallow-merge fallback as agentBaseUrl() for the token path.
        $path = config('dispatch.agent.remote.token_path') ?: env('DISPATCH_AGENT_TOKEN_PATH');
        if (is_string($path) && $path !== '') {
            return $path;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return rtrim($home, "/\\").DIRECTORY_SEPARATOR.'.dispatch'.DIRECTORY_SEPARATOR.'agent-token.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function agentTokenFile(): ?array
    {
        $path = $this->agentTokenPath();
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    protected function agentToken(): ?string
    {
        $data = $this->agentTokenFile();

        return is_array($data) ? ($data['token'] ?? null) : null;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    protected function storeToken(array $data): void
    {
        $path = $this->agentTokenPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
    }

    protected function forgetToken(): void
    {
        $path = $this->agentTokenPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    protected function agentClient(): PendingRequest
    {
        $client = Http::acceptJson()->timeout((int) config('dispatch.sync.timeout', 30));

        if ($token = $this->agentToken()) {
            $client = $client->withToken($token);
        }

        return $client;
    }

    /**
     * Guarded request against the agent API. Returns the decoded JSON body, or
     * null after emitting a human error (no remote / no token / plaintext /
     * revoked / HTTP error). On 401 the local token is cleared.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    protected function agentRequest(string $method, string $path, array $payload = [], bool $requireToken = true): ?array
    {
        $base = $this->agentBaseUrl();
        if ($base === null) {
            $this->error('No agent remote configured. Set dispatch.agent.remote.url (DISPATCH_AGENT_REMOTE_URL).');

            return null;
        }

        if (str_starts_with($base, 'http://') && ! app()->environment('local')) {
            $this->error('Refusing plaintext HTTP to the agent API outside a local environment — HTTPS is required.');

            return null;
        }

        if ($requireToken && $this->agentToken() === null) {
            $this->error('No agent session token. Run `dispatch:session:request`, get it approved, then `dispatch:session:status`.');

            return null;
        }

        $url = $base.'/'.ltrim($path, '/');
        $method = strtoupper($method);

        $response = $method === 'GET'
            ? $this->agentClient()->get($url, $payload)
            : $this->agentClient()->send($method, $url, ['json' => $payload]);

        if ($response->status() === 401) {
            $this->forgetToken();
            $this->error('Agent session was revoked or expired (401). Local token cleared — request a new session.');

            return null;
        }

        if (! $response->successful()) {
            $this->error("Agent API HTTP {$response->status()}: ".substr($response->body(), 0, 300));

            return null;
        }

        return $response->json();
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>|null
     */
    protected function agentGet(string $path, array $query = []): ?array
    {
        return $this->agentRequest('GET', $path, $query);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>|null
     */
    protected function agentPost(string $path, array $data = []): ?array
    {
        return $this->agentRequest('POST', $path, $data);
    }
}
