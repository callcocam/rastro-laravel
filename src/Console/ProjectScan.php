<?php

namespace Callcocam\Rastro\Console;

use Callcocam\Rastro\Central;
use Callcocam\Rastro\Scan\ProjectScanner;
use Illuminate\Console\Command;

/**
 * Reads this project (routes, validation rules, screen texts — never .env,
 * config, data or secrets) and sends it to the support central, whose AI
 * drafts the help options for the team to review. `--show` prints exactly
 * what would be sent and stops: nothing leaves.
 */
class ProjectScan extends Command
{
    protected $signature = 'rastro:scan
        {--locale= : Language of the translations to read (default: the app locale)}
        {--show : Only print what would be sent}';

    protected $description = 'Read this project and send it to the support central to draft the help with AI';

    public function handle(ProjectScanner $scanner, Central $central): int
    {
        $locale = $this->option('locale') ?: null;
        $text = $scanner->render($locale);

        if ($this->option('show')) {
            $this->line($text);

            return self::SUCCESS;
        }

        $response = $central->send('POST', 'scan', ['text' => $text, 'locale' => $locale ?? app()->getLocale()], timeout: 60);

        if ($response === null || ! $response->successful()) {
            $this->error(__('rastro::rastro.scan_failed'));

            return self::FAILURE;
        }

        $this->info(__($response->json('queued') ? 'rastro::rastro.scan_sent' : 'rastro::rastro.scan_sent_no_ai'));

        return self::SUCCESS;
    }
}
