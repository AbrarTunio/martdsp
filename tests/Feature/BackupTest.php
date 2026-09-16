<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('app/testing-backups');

        $this->tidy();

        Setting::writeMany(['backup.folder' => $this->folder]);
    }

    protected function tearDown(): void
    {
        $this->tidy();

        parent::tearDown();
    }

    public function test_only_the_owner_may_see_the_backup_screen(): void
    {
        $this->actingAs(User::factory()->manager()->create())
            ->get('/settings/backups')
            ->assertForbidden();

        $this->actingAs(User::factory()->owner()->create())
            ->get('/settings/backups')
            ->assertOk()
            ->assertSee('Back up now');
    }

    public function test_a_copy_is_written_and_recorded(): void
    {
        $file = $this->withDatabaseFile();

        $copy = app(BackupService::class)->run();

        $this->assertFileExists($this->folder.DIRECTORY_SEPARATOR.$copy['file']);
        $this->assertSame(filesize($file), $copy['bytes']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'backup.created']);
    }

    public function test_the_owner_can_make_a_copy_from_the_screen(): void
    {
        $this->withDatabaseFile();

        $this->actingAs(User::factory()->owner()->create())
            ->post('/settings/backups')
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertCount(1, app(BackupService::class)->files());
    }

    public function test_a_copy_that_cannot_be_made_says_so_rather_than_failing_silently(): void
    {
        config()->set('database.connections.'.config('database.default').'.database', ':memory:');

        $this->actingAs(User::factory()->owner()->create())
            ->post('/settings/backups')
            ->assertRedirect()
            ->assertSessionHasErrors('backup');

        $this->assertDatabaseHas('activity_logs', ['action' => 'backup.failed']);
    }

    public function test_copies_older_than_the_shop_keeps_are_thrown_away(): void
    {
        Setting::writeMany(['backup.keep_days' => 7]);

        $old = $this->fakeCopy('supermart-2020-01-01-000000.sqlite', now()->subDays(30));
        $recent = $this->fakeCopy('supermart-2026-09-15-000000.sqlite', now()->subDay());

        $this->assertSame(1, app(BackupService::class)->prune());
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_a_copy_can_be_downloaded_and_deleted(): void
    {
        $owner = User::factory()->owner()->create();
        $this->fakeCopy('supermart-2026-09-15-000000.sqlite', now());

        $this->actingAs($owner)
            ->get('/settings/backups/supermart-2026-09-15-000000.sqlite')
            ->assertOk()
            ->assertDownload('supermart-2026-09-15-000000.sqlite');

        $this->actingAs($owner)
            ->delete('/settings/backups/supermart-2026-09-15-000000.sqlite')
            ->assertRedirect();

        $this->assertCount(0, app(BackupService::class)->files());
        $this->assertDatabaseHas('activity_logs', ['action' => 'backup.downloaded']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'backup.deleted']);
    }

    /**
     * A name is checked rather than trusted, so no crafted name can walk out
     * of the backup folder and hand over the shop's .env.
     */
    public function test_a_name_cannot_reach_out_of_the_backup_folder(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get('/settings/backups/'.urlencode('../../../.env'))
            ->assertNotFound();

        $this->assertNull(app(BackupService::class)->find('database.sqlite'));
    }

    public function test_the_nightly_run_stops_when_backups_are_switched_off(): void
    {
        Setting::writeMany(['backup.enabled' => false]);

        $this->artisan('supermart:backup')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['action' => 'backup.created']);
    }

    public function test_the_button_makes_a_copy_even_when_nightly_backups_are_off(): void
    {
        Setting::writeMany(['backup.enabled' => false]);
        $this->withDatabaseFile();

        $this->artisan('supermart:backup --force')->assertSuccessful();

        $this->assertCount(1, app(BackupService::class)->files());
    }

    public function test_the_settings_are_saved_and_the_change_is_logged(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->put('/settings/backups', [
                'enabled' => '1',
                'folder' => 'E:\\SuperMart',
                'keep_days' => 30,
                'hour' => 2,
            ])
            ->assertRedirect(route('settings.backups.index'));

        $this->assertSame('E:\\SuperMart', Setting::read('backup.folder'));
        $this->assertSame(30, Setting::read('backup.keep_days'));
        $this->assertSame(2, Setting::read('backup.hour'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.backup_updated']);
    }

    public function test_a_relative_folder_stays_inside_the_application(): void
    {
        Setting::writeMany(['backup.folder' => 'nightly']);

        $this->assertSame(
            rtrim(str_replace('/', DIRECTORY_SEPARATOR, storage_path('app/nightly')), '\\/'),
            app(BackupService::class)->folder(),
        );
    }

    /**
     * MySQL is what the shop actually runs on, so the command it would be
     * handed is checked here even though the tests use SQLite.
     */
    public function test_mysql_is_dumped_with_a_password_that_cannot_break_the_line(): void
    {
        Process::fake();

        $connection = config('database.default');

        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'supermart',
            'username' => 'root',
            'password' => 'p@ss "word"',
        ]);
        config()->set('supermart.backup.mysqldump', ['mysqldump']);

        $command = app(BackupService::class)->dumpCommand('C:\\copies\\supermart.sql');

        config()->set('database.default', $connection);

        $this->assertContains('--password=p@ss "word"', $command);
        $this->assertContains('--single-transaction', $command);
        $this->assertContains('--result-file=C:\\copies\\supermart.sql', $command);
        $this->assertSame('supermart', end($command));
    }

    public function test_a_database_the_shop_does_not_know_how_to_dump_is_refused(): void
    {
        $connection = config('database.default');

        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.driver', 'pgsql');

        $problem = app(BackupService::class)->problem();

        config()->set('database.default', $connection);

        $this->assertStringContainsString('pgsql', (string) $problem);
    }

    /**
     * Give the connection a real file to copy, since the suite runs in memory.
     */
    private function withDatabaseFile(): string
    {
        $file = storage_path('app/testing-source.sqlite');

        file_put_contents($file, str_repeat('shop', 64));

        config()->set('database.connections.'.config('database.default').'.database', $file);

        return $file;
    }

    private function fakeCopy(string $name, Carbon $when): string
    {
        if (! is_dir($this->folder)) {
            mkdir($this->folder, 0o775, true);
        }

        $path = $this->folder.DIRECTORY_SEPARATOR.$name;

        file_put_contents($path, 'copy');
        touch($path, $when->getTimestamp());

        return $path;
    }

    private function tidy(): void
    {
        foreach ((array) glob($this->folder.DIRECTORY_SEPARATOR.'*') as $path) {
            if (is_string($path)) {
                @unlink($path);
            }
        }

        @rmdir($this->folder);
        @unlink(storage_path('app/testing-source.sqlite'));
    }
}
