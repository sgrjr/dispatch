<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Sync verb: (re-)export the local JSON-LD snapshot and POST it to the
 * remote's apply endpoint. `config('dispatch.sync.*')` controls the endpoint;
 * an empty remote_url is a deliberate no-op, so this exits 0 with a notice
 * rather than failing.
 */
class DispatchPush extends Command
{
    protected $signature = 'dispatch:push
        {--path=storage/app/dispatch-tasks.jsonld : JSON-LD file to upload, resolved relative to the app base path (re-exported from the local DB first)}
        {--skip-export : Use the existing file as-is; do not re-export from the local DB}';

    protected $description = 'Push the local task snapshot to the configured Dispatch remote via the sync endpoint.';

    public function handle(): int
    {
        $remoteUrl = rtrim((string) config('dispatch.sync.remote_url'), '/');

        if ($remoteUrl === '') {
            $this->info('No remote configured; skipping.');

            return self::SUCCESS;
        }

        $path = base_path($this->option('path'));

        if (! $this->option('skip-export')) {
            $this->call('dispatch:export', ['--path' => $this->option('path')]);
        }

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            $this->error('File is not valid JSON.');

            return self::FAILURE;
        }

        $endpoint = $remoteUrl.'/apply';
        $this->line("Pushing to {$endpoint} …");

        $response = $this->client()->asJson()->post($endpoint, $payload);

        if (! $response->successful()) {
            $this->error("HTTP {$response->status()}: ".substr($response->body(), 0, 500));

            return self::FAILURE;
        }

        $body = $response->json();
        $this->info('Pushed.');
        if (isset($body['summary']) && is_array($body['summary'])) {
            foreach ($body['summary'] as $k => $v) {
                $this->line("  {$k}: {$v}");
            }
        }

        return self::SUCCESS;
    }

    protected function client(): PendingRequest
    {
        $client = Http::acceptJson()->timeout((int) config('dispatch.sync.timeout', 30));

        if ($token = config('dispatch.sync.token')) {
            $client = $client->withToken($token);
        }

        if (! config('dispatch.sync.verify_ssl', true)) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }
}
