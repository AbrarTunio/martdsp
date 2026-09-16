<?php

namespace App\Http\Controllers;

use App\Enums\DrawerEntryType;
use App\Enums\DrawerStatus;
use App\Http\Requests\OpenDrawerRequest;
use App\Models\DrawerSession;
use App\Models\Printer;
use App\Models\Register;
use App\Models\Setting;
use App\Services\DrawerService;
use App\Support\DrawerSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * The cash drawers: each counter's shift right now, the counts waiting for a
 * manager, every shift before, and one shift told start to finish.
 */
class DrawerController extends Controller
{
    public function __construct(private readonly DrawerService $drawers) {}

    public function index(Request $request): View
    {
        Register::ensureOne();

        $user = $request->user();
        $seesExpected = Gate::allows('see-expected-cash');

        $registers = Register::query()->active()->with('openDrawer.opener')->get();

        $history = DrawerSession::query()
            ->with(['register', 'opener', 'closer'])
            ->when(! $user->supervises(), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('opened_by', $user->id)
                ->orWhere('closed_by', $user->id)))
            ->when($request->filled('register'), fn (Builder $query) => $query->where('register_id', $request->integer('register')))
            ->when($request->filled('status'), fn (Builder $query) => match ($request->query('status')) {
                'waiting' => $query->awaitingApproval(),
                DrawerStatus::Open->value => $query->open(),
                default => $query->where('status', DrawerStatus::Closed),
            })
            ->when($this->date($request->query('date')), fn (Builder $query, string $date) => $query->whereDate('opened_at', $date))
            ->latestFirst()
            ->paginate(20)
            ->withQueryString();

        return view('drawer.index', [
            'registers' => $registers,
            'expected' => $seesExpected
                ? $registers->filter->openDrawer->mapWithKeys(fn (Register $register): array => [
                    $register->id => $register->openDrawer->expectedCashPaisa(),
                ])
                : collect(),
            'suggestedFloats' => $registers->reject->openDrawer->mapWithKeys(fn (Register $register): array => [
                $register->id => $this->drawers->suggestedFloatPaisa($register),
            ]),
            'waiting' => $user->supervises()
                ? DrawerSession::query()->awaitingApproval()->with(['register', 'closer'])->latestFirst()->get()
                : collect(),
            'history' => $history,
            'filters' => $request->only('register', 'status', 'date'),
            'allRegisters' => Register::query()->orderBy('name')->pluck('name', 'id'),
            'seesExpected' => $seesExpected,
        ]);
    }

    public function store(OpenDrawerRequest $request): RedirectResponse
    {
        $register = $request->register();

        try {
            $session = $this->drawers->open($register, $request->user(), $request->floatPaisa(), $request->validated('note'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['float' => $exception->getMessage()])->withInput();
        }

        $message = __(':register is open. Happy selling!', ['register' => $register->name]);

        if ($request->validated('back_to') === 'pos') {
            $request->session()->put(PosController::SESSION_KEY, $register->id);

            return redirect()->route('pos.index')->with('status', $message);
        }

        return redirect()->route('drawer.show', $session)->with('status', $message);
    }

    /**
     * One shift start to finish. Under a blind count the cashier sees what
     * they handled by hand, never the sales that would add up to the answer.
     */
    public function show(DrawerSession $drawer): View
    {
        Gate::authorize('view-drawer', $drawer);

        $seesExpected = Gate::allows('see-expected-cash');
        $handledTypes = [DrawerEntryType::OpeningFloat, ...DrawerEntryType::manual()];

        return view('drawer.show', [
            'drawer' => $drawer,
            'summary' => DrawerSummary::for($drawer),
            'timeline' => $drawer->transactions()
                ->with('user')
                ->when(! $seesExpected, fn (Builder $query) => $query->whereIn('type', $handledTypes))
                ->latest('id')
                ->get(),
            'manualTypes' => DrawerEntryType::manual(),
            'seesExpected' => $seesExpected,
            'counterPrinter' => Printer::forRegister($drawer->register),
        ]);
    }

    /**
     * The shift's report on its own page, sized for the paper: an X-report
     * while the drawer is open, the Z-report once it is closed.
     */
    public function report(Request $request, DrawerSession $drawer): View
    {
        Gate::authorize('view-drawer', $drawer);

        $drawer->loadMissing('register');

        $paper = (string) $request->query('paper', $drawer->register?->paper() ?? Setting::read('receipt.paper_width'));

        return view('drawer.report', [
            'drawer' => $drawer,
            'summary' => DrawerSummary::for($drawer),
            'paper' => in_array($paper, Register::PAPERS, true) ? $paper : '80',
            'autoPrint' => $request->boolean('print'),
            'seesExpected' => Gate::allows('see-expected-cash'),
            'shop' => [
                'name' => (string) Setting::read('shop.name'),
                'address' => (string) Setting::read('shop.address'),
                'phone' => (string) Setting::read('shop.phone'),
            ],
        ]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
