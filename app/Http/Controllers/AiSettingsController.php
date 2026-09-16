<?php

namespace App\Http\Controllers;

use App\Enums\AiProvider;
use App\Http\Requests\UpdateAiSettingsRequest;
use App\Models\ActivityLog;
use App\Models\AiInsightRun;
use App\Models\Setting;
use App\Support\Ai\AiConnection;
use App\Support\Ai\AiException;
use App\Support\Ai\AiPricing;
use App\Support\Ai\AiPrompt;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Setting up the AI: whose AI, which key, which model, and how much the
 * shop is willing to spend on it in a month.
 *
 * The key is written encrypted and never read back into a page — the form
 * shows a masked version and treats a blank field as "leave it alone".
 */
class AiSettingsController extends Controller
{
    public function edit(): View
    {
        Gate::authorize('manage-settings');

        $cap = (int) Setting::read('ai.monthly_cap') * 100;
        $spent = AiInsightRun::spentThisMonth();

        return view('settings.ai', [
            'values' => Setting::values(),
            'providers' => AiProvider::options(),
            'provider' => AiProvider::tryFrom((string) Setting::read('ai.provider')),
            'maskedKey' => AiConnection::maskedKey(),
            'cap' => $cap,
            'spent' => $spent,
            'runs' => AiInsightRun::query()->latest('id')->limit(10)->get(),
            'asked' => AiInsightRun::query()->thisMonth()->answered()->count(),
        ]);
    }

    public function update(UpdateAiSettingsRequest $request): RedirectResponse
    {
        $before = $this->loggable();

        Setting::writeMany([
            'ai.provider' => (string) $request->validated('provider'),
            'ai.model' => trim((string) $request->validated('model')),
            'ai.base_url' => trim((string) $request->validated('base_url')),
            'ai.monthly_cap' => $request->validated('monthly_cap'),
            'ai.language' => $request->validated('language'),
            'ai.usd_rate' => $request->validated('usd_rate'),
        ]);

        Setting::writeMany(collect($request->validated('insights'))
            ->mapWithKeys(fn (mixed $value, string $key): array => ['insights.'.$key => $value])
            ->all());

        /** A blank key means "keep the one already saved". */
        if (filled($request->validated('api_key'))) {
            Setting::writeSecret(AiConnection::KEY_SETTING, trim((string) $request->validated('api_key')), 'ai');
        }

        ActivityLog::record('settings.ai_updated', before: $before, after: $this->loggable());

        return back()->with('status', __('AI settings saved.'));
    }

    /**
     * Forget the key. Everything else stays, so the same provider and model
     * are still there when a new key is put in.
     */
    public function forget(): RedirectResponse
    {
        Gate::authorize('manage-settings');

        Setting::writeSecret(AiConnection::KEY_SETTING, null, 'ai');

        ActivityLog::record('settings.ai_key_forgotten');

        return back()->with('status', __('The API key has been removed. Insights will use the shop\'s own checks until a new one is saved.'));
    }

    /**
     * The models this provider will let this key use, for the dropdown.
     */
    public function models(Request $request): JsonResponse
    {
        Gate::authorize('manage-settings');

        try {
            $models = $this->connection($request)->driver()->listModels();
        } catch (AiException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'message' => __('The list of models could not be fetched. You can still type the model name yourself.')], 422);
        }

        return response()->json(['ok' => true, 'models' => $models]);
    }

    /**
     * Ask the model the smallest question there is, and report what it cost.
     */
    public function test(Request $request): JsonResponse
    {
        Gate::authorize('manage-settings');

        try {
            $connection = $this->connection($request);

            if ($connection->model === '') {
                return response()->json(['ok' => false, 'message' => __('Choose a model first.')], 422);
            }

            $reply = $connection->driver()->complete(AiPrompt::connectionTest());
            $said = (string) ($reply->json()['reply'] ?? '');
        } catch (AiException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['ok' => false, 'message' => __('The test did not go through.')], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => __('It works. :model replied in :seconds seconds, for about :cost.', [
                'model' => $connection->model,
                'seconds' => number_format($reply->latencyMs / 1000, 1),
                'cost' => Money::withSymbol(AiPricing::estimatePaisa($connection, $reply)),
            ]),
            'said' => $said,
        ]);
    }

    /**
     * What is on the form, falling back to the saved key when the field was
     * left as it came — so Test works without retyping the key.
     */
    private function connection(Request $request): AiConnection
    {
        $provider = AiProvider::tryFrom((string) $request->input('provider'));

        abort_if($provider === null, 422, __('Choose which AI you are using first.'));

        $key = trim((string) $request->input('api_key')) ?: (string) Setting::secret(AiConnection::KEY_SETTING);

        abort_if($key === '', 422, __('Enter the API key first.'));

        return AiConnection::make($provider, $key, trim((string) $request->input('model')), (string) $request->input('base_url'));
    }

    /**
     * What may safely go in the activity log — never the key itself.
     *
     * @return array<string, mixed>
     */
    private function loggable(): array
    {
        return [
            'ai.provider' => Setting::read('ai.provider'),
            'ai.model' => Setting::read('ai.model'),
            'ai.base_url' => Setting::read('ai.base_url'),
            'ai.monthly_cap' => Setting::read('ai.monthly_cap'),
            'ai.language' => Setting::read('ai.language'),
            'ai.usd_rate' => Setting::read('ai.usd_rate'),
            'api_key_saved' => AiConnection::maskedKey() !== null,
        ];
    }
}
