<?php

namespace App\Services;

use App\Models\AiInsightRun;
use App\Models\Setting;
use App\Models\User;
use App\Support\Ai\AiConnection;
use App\Support\Ai\AiException;
use App\Support\Ai\AiPricing;
use App\Support\Ai\AiReply;
use App\Support\Insights\Insight;
use App\Support\Insights\InsightPrompt;
use App\Support\Insights\InsightRegistry;
use App\Support\Insights\InsightScope;
use App\Support\Insights\MetricPack;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Answers "Explain this page".
 *
 * The figures and the checks are worked out first, and they are what the
 * panel falls back to: with no AI set up, with the month's budget spent, or
 * with the provider having a bad day, the page still explains itself.
 */
class InsightService
{
    public function __construct(private readonly InsightRegistry $registry) {}

    public function explain(string $page, InsightScope $scope, User $user, bool $refresh = false): Insight
    {
        $pack = $this->registry->build($page, $scope);
        $connection = AiConnection::fromSettings();

        if ($connection === null) {
            return Insight::fromRules($pack, __('No AI is set up yet, so this is from the shop\'s own checks. Settings → AI Insights.'));
        }

        if (($stopped = $this->budgetNotice()) !== null) {
            return Insight::fromRules($pack, $stopped);
        }

        $fingerprint = $pack->fingerprint($connection->provider->value, $connection->model, (string) Setting::read('ai.language'));

        if (! $refresh && ($saved = $this->saved($page, $fingerprint)) !== null) {
            return Insight::fromAi($saved->response ?? [], $pack, $saved, cached: true);
        }

        try {
            $prompt = InsightPrompt::for($pack);
            $reply = $connection->driver()->complete($prompt);

            return Insight::fromAi($reply->json(), $pack, $this->record($page, $fingerprint, $pack, $connection, $reply, $user));
        } catch (AiException $exception) {
            $this->recordFailure($page, $fingerprint, $pack, $connection, $user, $exception->getMessage());

            return Insight::fromRules($pack, $exception->getMessage().' '.__('This note is from the shop\'s own checks instead.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->recordFailure($page, $fingerprint, $pack, $connection, $user, $exception->getMessage());

            return Insight::fromRules($pack, __('The AI could not be reached, so this is from the shop\'s own checks.'));
        }
    }

    /**
     * Why the AI is not being asked, when the month's budget says so.
     */
    private function budgetNotice(): ?string
    {
        $cap = (int) Setting::read('ai.monthly_cap');

        if ($cap <= 0) {
            return __('AI notes are switched off, so this is from the shop\'s own checks.');
        }

        $spent = AiInsightRun::spentThisMonth();

        if ($spent < $cap * 100) {
            return null;
        }

        return __('This month\'s AI budget of :cap is used up, so this is from the shop\'s own checks. It starts again next month.', [
            'cap' => Money::rounded($cap * 100),
        ]);
    }

    /**
     * An answer to the very same figures, asked recently enough to stand.
     */
    private function saved(string $page, string $fingerprint): ?AiInsightRun
    {
        return AiInsightRun::query()
            ->answered()
            ->where('page', $page)
            ->where('scope_hash', $fingerprint)
            ->where('created_at', '>=', CarbonImmutable::now()->subHours((int) config('supermart.ai.cache_hours', 6)))
            ->latest('id')
            ->first();
    }

    private function record(string $page, string $fingerprint, MetricPack $pack, AiConnection $connection, AiReply $reply, User $user): AiInsightRun
    {
        return AiInsightRun::create([
            'page' => $page,
            'scope_hash' => $fingerprint,
            'status' => AiInsightRun::ANSWERED,
            'provider' => $connection->provider->value,
            'model' => $connection->model,
            'input_tokens' => $reply->inputTokens,
            'output_tokens' => $reply->outputTokens,
            'cost_paisa' => AiPricing::estimatePaisa($connection, $reply),
            'latency_ms' => $reply->latencyMs,
            'metric_pack' => $pack->forAi(),
            'response' => $reply->json(),
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * A failed attempt is kept with the figures it was asked about, so a
     * provider that keeps refusing can be looked into later.
     */
    private function recordFailure(string $page, string $fingerprint, MetricPack $pack, AiConnection $connection, User $user, string $error): void
    {
        AiInsightRun::create([
            'page' => $page,
            'scope_hash' => $fingerprint,
            'status' => AiInsightRun::FAILED,
            'provider' => $connection->provider->value,
            'model' => $connection->model,
            'metric_pack' => $pack->forAi(),
            'error' => mb_substr($error, 0, 500),
            'user_id' => $user->getKey(),
        ]);
    }
}
