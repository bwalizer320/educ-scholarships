<?php

declare(strict_types=1);

namespace App\Services\Eligibility;

final class EligibilityService
{
    public function evaluate(array $criteria, array $studentValues): array
    {
        $results = [];
        $hasRequiredFailure = false;
        $hasRequiredUnknown = false;
        $hasPreferenceFailure = false;

        foreach ($criteria as $criterion) {
            $field = $criterion['field_key'] ?? null;
            $sourceValue = $field !== null ? ($studentValues[$field] ?? null) : null;

            if (empty($criterion['auto_evaluable'])) {
                $results[] = $this->result($criterion, 'not_evaluated', $sourceValue, 'Requires human review.');
                continue;
            }

            if ($sourceValue === null || $sourceValue === '') {
                $results[] = $this->result($criterion, 'unknown', null, 'Source value is unavailable.');

                if (($criterion['criterion_kind'] ?? '') === 'required') {
                    $hasRequiredUnknown = true;
                }

                continue;
            }

            $expected = $criterion['comparison_value'] ?? null;
            $operator = $criterion['operator'] ?? 'eq';
            $met = $this->compare($sourceValue, $operator, $expected);
            $kind = $criterion['criterion_kind'] ?? 'required';

            if (!$met && $kind === 'required') {
                $hasRequiredFailure = true;
            }

            if (!$met && in_array($kind, ['preferred', 'preference_fallback'], true)) {
                $hasPreferenceFailure = true;
            }

            $results[] = $this->result(
                $criterion,
                $met ? 'met' : 'not_met',
                $sourceValue,
                $met ? 'Meets criterion.' : 'Does not meet criterion.'
            );
        }

        $status = match (true) {
            $hasRequiredFailure => 'potentially_ineligible',
            $hasRequiredUnknown => 'insufficient_information',
            $hasPreferenceFailure => 'eligible_unmet_preference',
            default => 'eligible',
        };

        return [
            'status' => $status,
            'results' => $results,
        ];
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => (string) $actual === (string) $expected,
            'neq' => (string) $actual !== (string) $expected,
            'gte' => (float) $actual >= (float) $expected,
            'lte' => (float) $actual <= (float) $expected,
            'in', 'program_in' => in_array((string) $actual, array_map('strval', (array) $expected), true),
            'not_in' => !in_array((string) $actual, array_map('strval', (array) $expected), true),
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'exists' => $actual !== null && $actual !== '',
            default => false,
        };
    }

    private function result(array $criterion, string $result, mixed $value, string $message): array
    {
        return [
            'criterion_id' => $criterion['id'] ?? null,
            'kind' => $criterion['criterion_kind'] ?? null,
            'label' => $criterion['display_label'] ?? '',
            'requirement' => $criterion['display_requirement'] ?? '',
            'result' => $result,
            'source_name' => $criterion['field_key'] ?? 'manual',
            'source_value' => $value,
            'source_value_display' => $value === null ? null : $this->displayValue($value),
            'message' => $message,
        ];
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        return (string) $value;
    }
}
