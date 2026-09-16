<?php

namespace App\Support\Insights;

/**
 * Everything one page's insights are built from: the headline figures and
 * what the shop's own checks found in them.
 *
 * This is all the AI ever sees — totals, rankings and product or customer
 * names, already worked out from the database. Never a phone number, never
 * a raw table.
 */
final readonly class MetricPack
{
    /**
     * @param  string  $page  the insight page's key, e.g. "stock"
     * @param  string  $title  what the page is about, e.g. "Stock"
     * @param  string  $scopeLabel  the dates or the customer the figures cover
     * @param  string  $summary  one or two plain sentences of the headline figures
     * @param  array<string, mixed>  $facts  the figures, with money already written in rupees
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public string $page,
        public string $title,
        public string $scopeLabel,
        public string $summary,
        public array $facts,
        public array $findings,
    ) {}

    /**
     * Worst first, and the biggest money first within the same severity.
     *
     * @return list<Finding>
     */
    public function sortedFindings(): array
    {
        $findings = $this->findings;

        usort($findings, fn (Finding $a, Finding $b): int => $a->severity->rank() <=> $b->severity->rank()
            ?: ($b->impactPaisa ?? 0) <=> ($a->impactPaisa ?? 0));

        return $findings;
    }

    public function finding(string $key): ?Finding
    {
        foreach ($this->findings as $finding) {
            if ($finding->key === $key) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function forAi(): array
    {
        return [
            'page' => $this->title,
            'covers' => $this->scopeLabel,
            'summary' => $this->summary,
            'facts' => $this->facts,
            'findings' => array_map(fn (Finding $finding): array => $finding->forAi(), $this->sortedFindings()),
        ];
    }

    /**
     * Changes whenever a figure does. A saved AI answer is reused only while
     * the fingerprint still matches, so an old answer is never shown for new
     * figures.
     */
    public function fingerprint(string ...$context): string
    {
        return hash('sha256', json_encode($this->forAi(), JSON_UNESCAPED_UNICODE).'|'.implode('|', $context));
    }
}
