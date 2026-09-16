<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBackupSettingsRequest;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\BackupService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The nightly copy of the shop.
 *
 * One screen the owner can point at a USB drive and then forget about, with
 * the copies listed so they can see for themselves that it is happening.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(): View
    {
        Gate::authorize('manage-backups');

        return view('settings.backups', [
            'files' => $this->backups->files(),
            'folder' => $this->backups->folder(),
            'problem' => $this->backups->problem(),
            'lastRun' => $this->backups->lastRun(),
            'values' => $this->loggable(),
        ]);
    }

    public function update(UpdateBackupSettingsRequest $request): RedirectResponse
    {
        $before = $this->loggable();

        Setting::writeMany($request->settings());

        ActivityLog::record('settings.backup_updated', before: $before, after: $this->loggable());

        return redirect()
            ->route('settings.backups.index')
            ->with('status', __('Saved. The next copy goes to :folder.', ['folder' => $this->backups->folder()]));
    }

    /**
     * Make a copy right now — before a stock take, or to take one home.
     */
    public function store(): RedirectResponse
    {
        Gate::authorize('manage-backups');

        try {
            $copy = $this->backups->run();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['backup' => $exception->getMessage()]);
        }

        $this->backups->prune();

        return back()->with('status', __('Copied. :file is in :folder.', [
            'file' => $copy['file'],
            'folder' => $this->backups->folder(),
        ]));
    }

    /**
     * The four backup settings, for the screen and for the activity log.
     *
     * @return array{enabled: bool, folder: string, keep_days: int, hour: int}
     */
    private function loggable(): array
    {
        return [
            'enabled' => (bool) Setting::read('backup.enabled'),
            'folder' => (string) Setting::read('backup.folder'),
            'keep_days' => (int) Setting::read('backup.keep_days'),
            'hour' => (int) Setting::read('backup.hour'),
        ];
    }

    public function download(string $name): BinaryFileResponse
    {
        Gate::authorize('manage-backups');

        $path = $this->backups->find($name);

        abort_if($path === null, 404);

        ActivityLog::record('backup.downloaded', after: ['file' => basename($path)]);

        return response()->download($path);
    }

    public function destroy(string $name): RedirectResponse
    {
        Gate::authorize('manage-backups');

        $path = $this->backups->find($name);

        abort_if($path === null, 404);

        @unlink($path);

        ActivityLog::record('backup.deleted', after: ['file' => basename($path)]);

        return back()->with('status', __(':file was deleted.', ['file' => basename($path)]));
    }
}
