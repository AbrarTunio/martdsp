<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-settings');

        return view('settings.index', [
            'values' => Setting::values(),
            'paperWidths' => config('supermart.paper_widths'),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $before = Setting::values();

        Setting::writeMany($request->validated('settings'));

        $after = Setting::values();

        /** Only log what actually changed, so the audit trail stays readable. */
        $changed = array_keys(array_diff_assoc(
            array_map(fn (mixed $v): string => (string) $v, $after),
            array_map(fn (mixed $v): string => (string) $v, $before),
        ));

        if ($changed !== []) {
            ActivityLog::record(
                'settings.updated',
                before: array_intersect_key($before, array_flip($changed)),
                after: array_intersect_key($after, array_flip($changed)),
            );
        }

        return back()->with('status', __('Settings saved.'));
    }
}
