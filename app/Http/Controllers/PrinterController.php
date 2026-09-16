<?php

namespace App\Http\Controllers;

use App\Enums\PrinterConnection;
use App\Http\Requests\StorePrinterRequest;
use App\Http\Requests\UpdatePrinterRequest;
use App\Models\ActivityLog;
use App\Models\Printer;
use App\Models\Register;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The receipt printers the shop owns.
 *
 * Setting one up is guesswork until a slip comes out of it, so this screen is
 * built around the two buttons that end the guessing: Test print and Open
 * drawer. Everything else on it is a field those two buttons prove right.
 */
class PrinterController extends Controller
{
    private const TRACKED = ['name', 'channel', 'target', 'paper', 'cuts', 'has_drawer', 'drawer_pin', 'feed_lines', 'is_active'];

    public function index(): View
    {
        Gate::authorize('manage-settings');

        Register::ensureOne();

        return view('settings.printers.index', [
            'printers' => Printer::query()->withCount('registers')->orderByDesc('is_active')->orderBy('name')->get(),
            'registers' => Register::query()->with('printer')->orderBy('name')->get(),
            'channels' => PrinterConnection::cases(),
            'papers' => Printer::COLUMNS,
        ]);
    }

    public function store(StorePrinterRequest $request): RedirectResponse
    {
        $printer = Printer::query()->create($request->validated());

        ActivityLog::record('printer.created', $printer, after: $printer->only(self::TRACKED));

        return back()->with('status', __(':name was added. Send it a test print before the shop opens.', ['name' => $printer->name]));
    }

    public function update(UpdatePrinterRequest $request, Printer $printer): RedirectResponse
    {
        $before = $printer->only(self::TRACKED);

        $printer->update($request->validated());

        ActivityLog::record('printer.updated', $printer, $before, $printer->only(self::TRACKED));

        return back()->with('status', __(':name was saved.', ['name' => $printer->name]));
    }

    /**
     * A printer a counter still points at is switched off rather than
     * removed, so the counter is not left pointing at nothing mid-shift.
     */
    public function destroy(Printer $printer): RedirectResponse
    {
        Gate::authorize('manage-settings');

        if ($printer->registers()->exists()) {
            $printer->update(['is_active' => false]);

            ActivityLog::record('printer.deactivated', $printer);

            return back()->with('status', __(':name is still set on a counter, so it was switched off rather than removed.', ['name' => $printer->name]));
        }

        ActivityLog::record('printer.deleted', $printer, $printer->only('name'));

        $printer->delete();

        return back()->with('status', __(':name was removed.', ['name' => $printer->name]));
    }
}
