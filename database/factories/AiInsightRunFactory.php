<?php

namespace Database\Factories;

use App\Models\AiInsightRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiInsightRun>
 */
class AiInsightRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page' => 'stock',
            'scope_hash' => hash('sha256', fake()->uuid()),
            'status' => AiInsightRun::ANSWERED,
            'provider' => 'anthropic',
            'model' => 'claude-opus-5',
            'input_tokens' => 1800,
            'output_tokens' => 500,
            'cost_paisa' => 500,
            'latency_ms' => 4200,
            'metric_pack' => ['page' => 'stock', 'facts' => [], 'findings' => []],
            'response' => [
                'headline' => 'Stock is in good shape',
                'summary' => 'Nothing on the shelves needs you today.',
                'insights' => [],
                'watch_next' => [],
            ],
            'error' => null,
            'user_id' => User::factory()->owner(),
        ];
    }

    /**
     * A call that did not come back.
     */
    public function failed(string $error = 'The AI took too long to answer.'): static
    {
        return $this->state([
            'status' => AiInsightRun::FAILED,
            'cost_paisa' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'response' => null,
            'error' => $error,
        ]);
    }

    /**
     * What it cost, in paisa.
     */
    public function costing(int $paisa): static
    {
        return $this->state(['cost_paisa' => $paisa]);
    }
}
