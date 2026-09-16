<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A copy of the whole shop, written to a folder on this computer.
 *
 * Every night the database is dumped to one plain file. That file is the
 * shop: products, sales, khata, drawers, the lot. Anyone can copy it onto a
 * USB stick or a cloud folder, and anyone who knows MySQL can put the shop
 * back from it. Copies older than the chosen number of days are deleted, so
 * the folder does not fill the disk.
 *
 * Nothing here is clever on purpose. A backup that only works when a service
 * is reachable is not a backup for a shop whose internet comes and goes.
 */
class BackupService
{
    /**
     * Where the copies are written. A folder the owner has typed in wins; a
     * relative one is taken as being inside the application's storage folder.
     */
    public function folder(): string
    {
        $chosen = trim((string) Setting::read('backup.folder'));

        if ($chosen === '') {
            return $this->tidy((string) config('supermart.backup.folder'));
        }

        if ($this->isAbsolute($chosen)) {
            return $this->tidy($chosen);
        }

        return $this->tidy(storage_path('app/'.ltrim($chosen, '/\\')));
    }

    /**
     * Make a copy now.
     *
     * @return array{file: string, bytes: int, took_ms: int}
     *
     * @throws RuntimeException when the folder cannot be written to, or the database will not hand its contents over
     */
    public function run(): array
    {
        $started = microtime(true);
        $folder = $this->ensureFolder();
        $path = $folder.DIRECTORY_SEPARATOR.$this->fileName();

        try {
            $this->write($path);
        } catch (RuntimeException $exception) {
            @unlink($path);

            ActivityLog::record('backup.failed', after: ['error' => $exception->getMessage()]);

            throw $exception;
        }

        $bytes = (int) (@filesize($path) ?: 0);

        if ($bytes === 0) {
            @unlink($path);

            $message = __('The copy came out empty, so it has been thrown away rather than left looking like a backup.');

            ActivityLog::record('backup.failed', after: ['error' => $message]);

            throw new RuntimeException($message);
        }

        $result = [
            'file' => basename($path),
            'bytes' => $bytes,
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
        ];

        ActivityLog::record('backup.created', after: $result + ['folder' => $folder]);

        return $result;
    }

    /**
     * Throw away copies older than the shop keeps, and say how many went.
     */
    public function prune(): int
    {
        $keepDays = max(1, (int) Setting::read('backup.keep_days'));
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $gone = 0;

        foreach ($this->files() as $file) {
            if ($file['modified']->getTimestamp() < $cutoff && @unlink($file['path'])) {
                $gone++;
            }
        }

        return $gone;
    }

    /**
     * The copies sitting in the folder, newest first.
     *
     * @return Collection<int, array{name: string, path: string, bytes: int, modified: Carbon}>
     */
    public function files(): Collection
    {
        $pattern = $this->folder().DIRECTORY_SEPARATOR.config('supermart.backup.prefix').'*';

        return collect((array) glob($pattern))
            ->filter(fn ($path): bool => is_string($path) && is_file($path))
            ->map(fn (string $path): array => [
                'name' => basename($path),
                'path' => $path,
                'bytes' => (int) (@filesize($path) ?: 0),
                'modified' => Carbon::createFromTimestamp((int) @filemtime($path)),
            ])
            ->sortByDesc(fn (array $file): int => $file['modified']->getTimestamp())
            ->values();
    }

    /**
     * One copy by its name, or null when it is not there. The name is checked
     * rather than trusted, so a crafted name cannot reach up out of the folder.
     */
    public function find(string $name): ?string
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $name) || ! str_starts_with($name, (string) config('supermart.backup.prefix'))) {
            return null;
        }

        $path = $this->folder().DIRECTORY_SEPARATOR.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * Whether a copy can be made at all — mostly whether mysqldump was found
     * on a Windows PC that never had it on the PATH.
     */
    public function problem(): ?string
    {
        if ($this->driver() === 'sqlite') {
            return null;
        }

        if ($this->driver() !== 'mysql' && $this->driver() !== 'mariadb') {
            return __('Backups are only set up for MySQL and MariaDB. This shop is running on :driver.', ['driver' => $this->driver()]);
        }

        return $this->mysqldump() === null
            ? __('The mysqldump program could not be found on this computer, so a copy cannot be made. It comes with MySQL — in Laragon it sits in bin\\mysql.')
            : null;
    }

    /**
     * What the nightly copy would cost in words, for the settings screen.
     */
    public function lastRun(): ?ActivityLog
    {
        return ActivityLog::query()
            ->whereIn('action', ['backup.created', 'backup.failed'])
            ->latest('id')
            ->first();
    }

    /**
     * @throws RuntimeException when the dump cannot be made
     */
    private function write(string $path): void
    {
        if ($this->driver() === 'sqlite') {
            $this->copySqlite($path);

            return;
        }

        $problem = $this->problem();

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $result = Process::timeout((int) config('supermart.backup.timeout'))
            ->run($this->dumpCommand($path));

        if (! $result->successful()) {
            throw new RuntimeException(__('MySQL would not hand the shop over: :error', [
                'error' => trim($result->errorOutput()) ?: __('the dump program stopped with no reason given'),
            ]));
        }
    }

    /**
     * The mysqldump command, as a list rather than a string so a password with
     * a space or a quote in it cannot break the line.
     *
     * @return list<string>
     */
    public function dumpCommand(string $path): array
    {
        $connection = (array) config('database.connections.'.config('database.default'));

        return array_values(array_filter([
            (string) $this->mysqldump(),
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? 3306),
            '--user='.($connection['username'] ?? 'root'),
            ($connection['password'] ?? '') === '' ? null : '--password='.$connection['password'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--default-character-set=utf8mb4',
            '--result-file='.$path,
            (string) ($connection['database'] ?? ''),
        ], fn (?string $part): bool => $part !== null));
    }

    /**
     * @throws RuntimeException when the file cannot be copied
     */
    private function copySqlite(string $path): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if ($database === '' || $database === ':memory:' || ! is_file($database)) {
            throw new RuntimeException(__('There is no database file on this computer to copy.'));
        }

        if (! @copy($database, $path)) {
            throw new RuntimeException(__('The database file could not be copied into :folder.', ['folder' => dirname($path)]));
        }
    }

    /**
     * @throws RuntimeException when the folder is not there and cannot be made
     */
    private function ensureFolder(): string
    {
        $folder = $this->folder();

        if (! is_dir($folder) && ! @mkdir($folder, 0o775, true) && ! is_dir($folder)) {
            throw new RuntimeException(__('The folder :folder could not be made. Check the drive is plugged in and the name is spelt right.', ['folder' => $folder]));
        }

        if (! is_writable($folder)) {
            throw new RuntimeException(__('Nothing can be written into :folder. Check the drive is not full or read-only.', ['folder' => $folder]));
        }

        return $folder;
    }

    private function fileName(): string
    {
        $extension = $this->driver() === 'sqlite' ? 'sqlite' : 'sql';

        return config('supermart.backup.prefix').now()->format('Y-m-d-His').'.'.$extension;
    }

    /**
     * The first mysqldump that is actually there, out of the places it is
     * usually installed.
     */
    private function mysqldump(): ?string
    {
        foreach ((array) config('supermart.backup.mysqldump') as $candidate) {
            if (! str_contains((string) $candidate, '/') && ! str_contains((string) $candidate, '\\')) {
                if ($this->onThePath((string) $candidate)) {
                    return (string) $candidate;
                }

                continue;
            }

            $found = collect((array) glob((string) $candidate))->last();

            if (is_string($found) && is_file($found)) {
                return $found;
            }
        }

        return null;
    }

    private function onThePath(string $program): bool
    {
        $which = windows_os() ? 'where' : 'command -v';

        return Process::quietly()->run($which.' '.$program)->successful();
    }

    private function driver(): string
    {
        return (string) config('database.connections.'.config('database.default').'.driver');
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private function tidy(string $path): string
    {
        return rtrim(str_replace('/', DIRECTORY_SEPARATOR, $path), '\\/');
    }
}
