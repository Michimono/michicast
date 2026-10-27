<?php

namespace App\Support\BroadcastFilters;

use App\Models\ContentItem;
use Carbon\CarbonInterface;

/**
 * Applies a broadcast's filter JSON to one content item. Rules combine with
 * AND; an empty or missing rule list passes everything.
 */
class BroadcastFilterEvaluator
{
    /**
     * @param  array<string, mixed>|null  $filter
     */
    public function evaluate(?array $filter, ContentItem $item, ?CarbonInterface $now = null): FilterDecision
    {
        $rules = $this->rules($filter);

        if ($rules === []) {
            return FilterDecision::pass();
        }

        $now ??= now();

        foreach ($rules as $rule) {
            if (! $rule->matches($item, $now)) {
                return FilterDecision::skip($rule->describeFor($item));
            }
        }

        return FilterDecision::pass();
    }

    /**
     * @param  array<string, mixed>|null  $filter
     * @return list<BroadcastFilterRule>
     */
    public function rules(?array $filter): array
    {
        $raw = $filter['rules'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $rules = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $rule = BroadcastFilterRule::fromArray($entry);

            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}
