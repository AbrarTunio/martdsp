<?php

namespace App\Http\Controllers;

use App\Services\InsightService;
use App\Support\Insights\InsightRegistry;
use App\Support\Insights\InsightScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Explain this page": builds the page's figures, gets them explained, and
 * sends back just the panel.
 */
class InsightController extends Controller
{
    public function __construct(private readonly InsightService $insights) {}

    public function show(Request $request, string $page): View
    {
        Gate::authorize('use-insights');

        abort_unless(InsightRegistry::has($page), 404);

        $scope = InsightScope::fromRequest($request, $request->user());

        return view('insights.panel', [
            'insight' => $this->insights->explain($page, $scope, $request->user(), $request->boolean('refresh')),
            'page' => $page,
            'scope' => $scope,
        ]);
    }
}
