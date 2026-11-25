<?php

namespace Callcocam\Rastro\Console;

use Callcocam\Rastro\Central;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Sends the help tree written in this project (rastro-ajuda.json, see
 * rastro:help-guide) to the support central, with the site's key. Everything
 * comes in there as drafts; the team publishes them in the help editor.
 */
class HelpPush extends Command
{
    public const FILE = 'rastro-ajuda.json';

    protected $signature = 'rastro:help-push {file? : The JSON file (default: rastro-ajuda.json at the project root)}';

    protected $description = 'Send the help tree (rastro-ajuda.json) to the support central, as drafts';

    public function handle(Central $central): int
    {
        $path = (string) ($this->argument('file') ?: base_path(self::FILE));

        if (! File::exists($path)) {
            $this->error(__('rastro::rastro.push_missing', ['file' => $path, 'guide' => 'rastro:help-guide']));

            return self::FAILURE;
        }

        $tree = json_decode((string) File::get($path), true);

        if (! is_array($tree) || ! is_array($tree['topics'] ?? null)) {
            $this->error(__('rastro::rastro.push_invalid', ['file' => $path]));

            return self::FAILURE;
        }

        $response = $central->send('POST', 'help/import', $tree, timeout: 60);

        if ($response === null) {
            $this->error(__('rastro::rastro.scan_failed'));

            return self::FAILURE;
        }

        if ($response->status() === 422) {
            $this->error(__('rastro::rastro.push_refused'));

            foreach ((array) $response->json('errors', []) as $where => $messages) {
                $this->line("  {$where}: ".((array) $messages)[0]);
            }

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error(__('rastro::rastro.scan_failed'));

            return self::FAILURE;
        }

        $this->info(__('rastro::rastro.push_done', [
            'created' => (int) $response->json('created'),
            'updated' => (int) $response->json('updated'),
            'kept' => (int) $response->json('kept'),
        ]));

        return self::SUCCESS;
    }
}
