<?php

namespace Callcocam\Rastro\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Puts in the project the guide a coding agent follows to write the help
 * tree (rastro-ajuda.json) that rastro:help-push sends to the central.
 */
class HelpGuide extends Command
{
    public const FILE = 'RASTRO-AJUDA.md';

    protected $signature = 'rastro:help-guide {--force : Overwrite the guide if it is already there}';

    protected $description = 'Create RASTRO-AJUDA.md: the guide for your coding agent to write the help tree';

    public function handle(): int
    {
        $target = base_path(self::FILE);

        if (File::exists($target) && ! $this->option('force')) {
            $this->warn(__('rastro::rastro.guide_exists', ['file' => self::FILE]));

            return self::SUCCESS;
        }

        File::copy(__DIR__.'/../../resources/stubs/rastro-ajuda.md', $target);

        $this->info(__('rastro::rastro.guide_created', ['file' => self::FILE]));
        $this->line(__('rastro::rastro.guide_next', ['file' => self::FILE, 'output' => HelpPush::FILE]));

        return self::SUCCESS;
    }
}
