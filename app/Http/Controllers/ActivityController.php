<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\ActivityAction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Who did what, and when.
 *
 * Every action that moves money or stock leaves a row behind it. This screen
 * is that trail read back in plain words — the answer to "who cancelled that
 * bill?" without anyone having to open the database.
 *
 * The rows are never edited and never deleted from here. A log that can be
 * tidied up is not worth keeping.
 */
class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('supervise');

        $from = $this->date($request->query('from'), today()->subDays(6));
        $to = $this->date($request->query('to'), today());

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $area = (string) $request->query('area', '');
        $term = trim((string) $request->query('q', ''));

        $entries = ActivityLog::query()
            ->with('user')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($request->filled('user'), fn (Builder $query) => $query->where('user_id', $request->integer('user')))
            ->when($area !== '', function (Builder $query) use ($area): void {
                $query->where(function (Builder $query) use ($area): void {
                    foreach (ActivityAction::patterns($area) as $pattern) {
                        $query->orWhere('action', 'like', $pattern);
                    }

                    // An unknown area must match nothing rather than everything.
                    if (ActivityAction::patterns($area) === []) {
                        $query->whereRaw('1 = 0');
                    }
                });
            })
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('action', 'like', '%'.$term.'%')
                        ->orWhere('subject_type', 'like', '%'.$term.'%')
                        ->orWhere('ip', 'like', $term.'%')
                        ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.$term.'%'));
                });
            })
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('activity.index', [
            'entries' => $entries,
            'people' => User::query()->orderBy('name')->pluck('name', 'id'),
            'areas' => ActivityAction::areas(),
            'from' => $from,
            'to' => $to,
            'filters' => [
                'user' => $request->query('user'),
                'area' => $area,
                'q' => $term,
            ],
        ]);
    }

    /**
     * A date typed into the address bar, or the sensible one when it is
     * missing or nonsense.
     */
    private function date(mixed $value, Carbon $fallback): Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback->copy();
        }

        return rescue(
            fn (): Carbon => Carbon::parse($value)->startOfDay(),
            $fallback->copy(),
            false,
        );
    }
}
