<?php

namespace App\Support\Insights;

use App\Enums\InsightSeverity;
use App\Support\Money;

/**
 * One thing the shop's own checks noticed: what it is, why it matters and
 * what to do about it.
 *
 * Findings are worked out from the figures by fixed rules, so they are the
 * same with or without an AI key. The AI only explains them better.
 */
final readonly class Finding
{
    /**
     * @param  string  $key  a short, stable name the AI answer refers back to, e.g. "dead_stock"
     * @param  int|null  $impactPaisa  the money involved, when there is a fair figure for it
     * @param  list<string>  $examples  the names behind the finding, worst first
     */
    public function __construct(
        public string $key,
        public InsightSeverity $severity,
        public string $title,
        public string $detail,
        public string $action,
        public ?int $impactPaisa = null,
        public ?string $href = null,
        public array $examples = [],
    ) {}

    public function impact(): ?string
    {
        return $this->impactPaisa === null ? null : Money::rounded($this->impactPaisa);
    }

    /**
     * The finding as the AI is shown it.
     *
     * @return array<string, mixed>
     */
    public function forAi(): array
    {
        return array_filter([
            'key' => $this->key,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'detail' => $this->detail,
            'suggested_action' => $this->action,
            'money_involved' => $this->impact(),
            'examples' => $this->examples ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
