<?php

namespace App\Support\Insights;

use App\Enums\AiProvider;
use App\Enums\InsightSeverity;
use App\Models\AiInsightRun;
use App\Support\Money;

/**
 * The note the panel shows, whether it was written by the AI or put
 * together from the shop's own checks.
 */
final readonly class Insight
{
    public const AI = 'ai';

    public const RULES = 'rules';

    /**
     * @param  list<array{finding: string|null, title: string, explanation: string, action: string, impact: string|null, severity: InsightSeverity, href: string|null}>  $items
     * @param  list<string>  $watchNext
     * @param  array<string, mixed>  $meta
     * @param  string|null  $notice  why the AI did not write this, when it did not
     */
    public function __construct(
        public string $headline,
        public string $summary,
        public array $items,
        public array $watchNext,
        public string $source,
        public MetricPack $pack,
        public array $meta = [],
        public ?string $notice = null,
    ) {}

    public function isAi(): bool
    {
        return $this->source === self::AI;
    }

    /**
     * The shop's own checks, in the same shape the AI would have answered in.
     */
    public static function fromRules(MetricPack $pack, ?string $notice = null): self
    {
        $findings = $pack->sortedFindings();
        $worst = $findings[0] ?? null;

        $items = array_map(fn (Finding $finding): array => [
            'finding' => $finding->key,
            'title' => $finding->title,
            'explanation' => $finding->detail,
            'action' => $finding->action,
            'impact' => $finding->impact(),
            'severity' => $finding->severity,
            'href' => $finding->href,
        ], array_slice($findings, 0, 6));

        return new self(
            headline: $worst?->title ?? __('Nothing needs your attention here'),
            summary: $pack->summary,
            items: $items,
            watchNext: [],
            source: self::RULES,
            pack: $pack,
            notice: $notice,
        );
    }

    /**
     * What the AI said, checked against the page's own findings so a wrong
     * or invented key cannot put the wrong colour or the wrong link on a line.
     *
     * @param  array<string, mixed>  $reply
     */
    public static function fromAi(array $reply, MetricPack $pack, ?AiInsightRun $run = null, bool $cached = false): self
    {
        $items = [];

        foreach (is_array($reply['insights'] ?? null) ? $reply['insights'] : [] as $line) {
            if (! is_array($line) || trim((string) ($line['title'] ?? '')) === '') {
                continue;
            }

            $finding = $pack->finding(trim((string) ($line['finding'] ?? '')));
            $impact = trim((string) ($line['impact'] ?? ''));

            $items[] = [
                'finding' => $finding?->key,
                'title' => trim((string) $line['title']),
                'explanation' => trim((string) ($line['explanation'] ?? '')),
                'action' => trim((string) ($line['action'] ?? '')),
                'impact' => $impact !== '' ? $impact : $finding?->impact(),
                'severity' => $finding?->severity ?? InsightSeverity::Low,
                'href' => $finding?->href,
            ];
        }

        $watch = array_values(array_filter(array_map(
            fn (mixed $line): string => trim((string) (is_scalar($line) ? $line : '')),
            is_array($reply['watch_next'] ?? null) ? $reply['watch_next'] : [],
        )));

        return new self(
            headline: trim((string) ($reply['headline'] ?? '')) ?: $pack->title,
            summary: trim((string) ($reply['summary'] ?? '')) ?: $pack->summary,
            items: $items,
            watchNext: array_slice($watch, 0, 3),
            source: self::AI,
            pack: $pack,
            meta: [
                'provider' => $run?->provider,
                'model' => $run?->model,
                'written_at' => $run?->created_at,
                'cost_paisa' => (int) ($run?->cost_paisa ?? 0),
                'cached' => $cached,
            ],
        );
    }

    /**
     * "Written by Claude · claude-opus-5 · just now · Rs. 2" — or where the
     * note came from when nobody paid for it.
     */
    public function credit(): string
    {
        if (! $this->isAi()) {
            return __('From the shop\'s own checks');
        }

        $parts = array_filter([
            AiProvider::tryFrom((string) ($this->meta['provider'] ?? ''))?->label(),
            $this->meta['model'] ?? null,
            ($this->meta['written_at'] ?? null)?->diffForHumans(),
            ($this->meta['cost_paisa'] ?? 0) > 0 ? Money::withSymbol($this->meta['cost_paisa']) : null,
        ]);

        return __('Written by AI').' · '.implode(' · ', $parts);
    }
}
