<?php

namespace Sgrjr\Dispatch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Sync verb: GET the remote's JSON-LD snapshot and import it locally.
 * `config('dispatch.sync.*')` controls the endpoint; an empty remote_url is
 * a deliberate no-op (not every install has a paired remote), so this exits
 * 0 with a notice rather than failing.
 */
class DispatchPull extends Command
{
    protected $signature = 'dispatch:pull
        {--path=storage/app/dispatch-tasks.jsonld : Where to write the fetched JSON-LD snapshot, resolved relative to the app base path}
        {--dry-run : Fetch + write the file, but do not import to the local DB}';

    protected $description = 'Pull the remote task snapshot into the local DB via the configured Dispatch sync endpoint.';

    public function handle(): int
    {
        $remoteUrl = rtrim((string) config('dispatch.sync.remote_url'), '/');

        if ($remoteUrl === '') {
            $this->info('No remote configured; skipping.');

            return self::SUCCESS;
        }

        $endpoint = $remoteUrl.'/snapshot';
        $this->line("Fetching {$endpoint} …");

        $response = $this->client()->get($endpoint);

        if (! $response->successful()) {
            $this->error("HTTP {$response->status()}: ".substr($response->body(), 0, 500));

            return self::FAILURE;
        }

        $path = base_path($this->option('path'));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $body = $response->body();
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $body = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }
        file_put_contents($path, $body);

        $taskCount = is_array($decoded['tasks'] ?? null) ? count($decoded['tasks']) : '?';
        $labelCount = is_array($decoded['labels'] ?? null) ? count($decoded['labels']) : '?';
        $this->info("Wrote {$path} ({$taskCount} tasks, {$labelCount} labels).");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — local DB not modified. Run `php artisan dispatch:import` to apply.');

            return self::SUCCESS;
        }

        return $this->call('dispatch:import', ['path' => $this->option('path')]);
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
