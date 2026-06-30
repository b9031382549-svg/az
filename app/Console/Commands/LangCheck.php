<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Reports UI translation keys (from __() / @lang() in Blade views) that are
 * missing from the configured locale JSON files. No network/LLM — a static scan
 * so the UI never silently falls back to English for a locale.
 */
class LangCheck extends Command
{
    protected $signature = 'lang:check {--locales=az,ru : Comma-separated locales to check}';

    protected $description = 'Find UI strings used in views but missing from lang/{locale}.json';

    public function handle(): int
    {
        $keys = $this->keysInViews();
        $this->info(count($keys).' translatable string(s) found in views.');

        $locales = array_filter(array_map('trim', explode(',', (string) $this->option('locales'))));
        $missingTotal = 0;

        foreach ($locales as $locale) {
            $path = lang_path($locale.'.json');
            $translations = File::exists($path)
                ? (array) json_decode(File::get($path), true)
                : [];

            $missing = array_values(array_filter($keys, fn ($k) => ! array_key_exists($k, $translations)));
            $missingTotal += count($missing);

            if (empty($missing)) {
                $this->line("  <info>{$locale}</info>: complete ✓");

                continue;
            }

            $this->warn("  {$locale}: ".count($missing)." missing");
            foreach ($missing as $k) {
                $this->line('    · '.$k);
            }
        }

        return $missingTotal === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, string> */
    private function keysInViews(): array
    {
        $keys = [];
        $files = array_merge(
            File::allFiles(resource_path('views')),
            File::allFiles(app_path()),
        );
        foreach ($files as $file) {
            $name = $file->getFilename();
            if (! str_ends_with($name, '.blade.php') && ! str_ends_with($name, '.php')) {
                continue;
            }
            $src = $file->getContents();
            // Match the first string literal passed to the translation helper.
            if (preg_match_all('/(?:__|@lang)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $src, $m)) {
                foreach ($m[1] as $raw) {
                    $quote = $raw[0];
                    $inner = substr($raw, 1, -1);
                    // unescape \' or \" and \\
                    $inner = str_replace(['\\'.$quote, '\\\\'], [$quote, '\\'], $inner);
                    $keys[$inner] = true;
                }
            }
        }

        return array_keys($keys);
    }
}
