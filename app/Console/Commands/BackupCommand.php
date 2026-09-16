<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Make tonight's copy of the shop.
 *
 * Run by the scheduler every night, and by the "Back up now" button on the
 * settings screen. `--force` makes a copy even when nightly backups have been
 * switched off, which is what the button does.
 */
#[Signature('supermart:backup {--force : Make a copy even if nightly backups are switched off}')]
#[Description('Write a copy of the whole database to the backup folder')]
class BackupCommand extends Command
{
    public function handle(BackupService $backups): int
    {
        if (! $this->option('force') && ! (bool) Setting::read('backup.enabled')) {
            $this->components->info('Nightly backups are switched off in Settings.');

            return self::SUCCESS;
        }

        try {
            $copy = $backups->run();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s written to %s (%s).',
            $copy['file'],
            $backups->folder(),
            $this->size($copy['bytes']),
        ));

        $gone = $backups->prune();

        if ($gone > 0) {
            $this->components->info(sprintf('%d older %s thrown away.', $gone, $gone === 1 ? 'copy' : 'copies'));
        }

        return self::SUCCESS;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? round($bytes / 1_048_576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
