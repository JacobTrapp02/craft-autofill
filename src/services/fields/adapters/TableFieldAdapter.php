<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Table as TableField;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json as JsonHelper;
use RuntimeException;

class TableFieldAdapter implements FieldAdapterInterface
{
    public function getKey(): string
    {
        return 'table';
    }

    public function isAvailableInFreeVersion(): bool
    {
        return true;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof TableField;
    }

    public function getPromptConfigSchema(FieldInterface $field): array
    {
        return [
            'fields' => [
                [
                    'key' => 'prompt',
                    'type' => 'multiline',
                    'label' => 'Prompt',
                    'required' => true,
                ],
            ],
        ];
    }

    public function normalizePromptConfig(array $config, FieldInterface $field): array
    {
        return [
            'prompt' => trim((string)($config['prompt'] ?? '')),
        ];
    }

    public function validatePromptConfig(array $config, FieldInterface $field): array
    {
        $normalized = $this->normalizePromptConfig($config, $field);
        $errors = [];

        if ($normalized['prompt'] === '') {
            $errors[] = 'Prompt is required for Table fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof TableField) {
            return ['type' => 'object'];
        }

        $columns = $this->promptColumns($field);
        $rules = [
            'Return JSON only.',
            'Return an object with a rows array.',
            'Each rows item must be an object keyed by the exact column key values shown in columns.',
            'Only include editable columns. Do not return heading columns.',
        ];

        if ($field->staticRows) {
            $rules[] = sprintf('Return exactly %d row object(s), in the same order as rowLabels.', count($field->defaults ?? []));
        } else {
            if ($field->minRows) {
                $rules[] = sprintf('Return at least %d row object(s).', (int)$field->minRows);
            }
            if ($field->maxRows) {
                $rules[] = sprintf('Return at most %d row object(s).', (int)$field->maxRows);
            }
        }

        return [
            'type' => 'object',
            'format' => 'table',
            'rules' => $rules,
            'properties' => [
                'rows' => [
                    'type' => 'array',
                ],
            ],
            'columns' => $columns,
            'staticRows' => (bool)$field->staticRows,
            'minRows' => (int)($field->minRows ?? 0),
            'maxRows' => (int)($field->maxRows ?? 0),
            'rowLabels' => $this->rowLabels($field),
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if (!$field instanceof TableField) {
            return '';
        }

        $rows = $this->friendlyRowsForContext($field, $value);
        if ($rows === []) {
            return '';
        }

        $encoded = json_encode(['rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof TableField) {
            return false;
        }

        $rows = $this->normalizeRows($field, $value);
        if ($rows === null) {
            return false;
        }

        if (!$this->validateRowCount($field, $rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                return false;
            }

            foreach ($this->editableColumns($field) as $columnId => $column) {
                if (!array_key_exists($columnId, $row)) {
                    continue;
                }

                if (!$this->validateCellValue($column, $row[$columnId])) {
                    return false;
                }
            }
        }

        return true;
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (!$field instanceof TableField) {
            return [];
        }

        return $this->normalizeRows($field, $value) ?? [];
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        if (!$field instanceof TableField) {
            throw new RuntimeException('Table suggestion adapter received an unsupported field.');
        }

        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for table suggestion apply.');
        }

        $normalized = $this->normalizeRows($field, $value);
        if ($normalized === null) {
            throw new RuntimeException('Table suggestion must be valid JSON with a rows array.');
        }

        if (!$this->validateSuggestion($field, $normalized)) {
            throw new RuntimeException('Table suggestion must match the configured table columns and row constraints.');
        }

        $fieldValue = $field->normalizeValue($normalized, $entry);
        if (!is_array($fieldValue) || $fieldValue === []) {
            throw new RuntimeException('Table suggestion could not be normalized into a Craft table value.');
        }

        $entry->setFieldValue($handle, $fieldValue);
        return $fieldValue;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function promptColumns(TableField $field): array
    {
        $columns = [];

        foreach ($this->editableColumns($field) as $columnId => $column) {
            $key = $this->responseKeyForColumn($columnId, $column);
            $item = [
                'key' => $key,
                'type' => (string)($column['type'] ?? 'singleline'),
                'valueKind' => $this->columnValueKind((string)($column['type'] ?? 'singleline')),
            ];

            $label = $this->columnLabel($columnId, $column);
            if ($label !== $key) {
                $item['label'] = $label;
            }

            if (!empty($column['options']) && is_array($column['options'])) {
                $item['options'] = $this->promptOptions($column);
            }

            $columns[] = $item;
        }

        return $columns;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowLabels(TableField $field): array
    {
        if (!$field->staticRows || !is_array($field->defaults ?? null)) {
            return [];
        }

        $labels = [];
        foreach (array_values($field->defaults ?? []) as $index => $row) {
            $labels[] = $this->rowLabel($field, $row, $index);
        }

        return $labels;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function editableColumns(TableField $field): array
    {
        $columns = [];

        foreach ($field->columns as $columnId => $column) {
            if (!is_array($column)) {
                continue;
            }

            if (($column['type'] ?? '') === 'heading') {
                continue;
            }

            $columns[(string)$columnId] = $column;
        }

        return $columns;
    }

    private function responseKeyForColumn(string $columnId, array $column): string
    {
        $handle = trim((string)($column['handle'] ?? ''));
        return $handle !== '' ? $handle : $columnId;
    }

    private function columnLabel(string $columnId, array $column): string
    {
        $heading = trim((string)($column['heading'] ?? ''));
        if ($heading !== '') {
            return $heading;
        }

        $handle = trim((string)($column['handle'] ?? ''));
        return $handle !== '' ? $handle : $columnId;
    }

    private function columnValueKind(string $type): string
    {
        return match ($type) {
            'checkbox', 'lightswitch' => 'boolean',
            'number' => 'number',
            'color' => 'hex-color',
            'date' => 'date',
            'time' => 'time',
            'email' => 'email',
            'url' => 'url',
            'select' => 'option-value',
            default => 'text',
        };
    }

    /**
     * @return array<int, string|array{value:string,label:string}>
     */
    private function promptOptions(array $column): array
    {
        $normalized = $this->normalizedSelectOptions($column);
        if ($normalized === []) {
            return [];
        }

        $allLabelsMatch = true;
        foreach ($normalized as $option) {
            if (($option['label'] ?? '') !== ($option['value'] ?? '')) {
                $allLabelsMatch = false;
                break;
            }
        }

        if ($allLabelsMatch) {
            return array_values(array_map(static fn(array $option) => (string)$option['value'], $normalized));
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function normalizeRows(TableField $field, mixed $value): ?array
    {
        $rows = $this->extractRows($field, $value);
        if ($rows === null) {
            return null;
        }

        $rows = array_values($rows);
        $staticRowCount = count($field->defaults ?? []);

        if ($field->staticRows && $staticRowCount > 0) {
            $rows = array_slice(array_pad($rows, $staticRowCount, []), 0, $staticRowCount);
        }

        $normalized = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                if ($field->staticRows) {
                    $normalized[] = $this->staticRowBase($field, $index);
                }
                continue;
            }

            $normalizedRow = $this->staticRowBase($field, $index);

            foreach ($this->editableColumns($field) as $columnId => $column) {
                [$hasRawCellValue, $rawCellValue] = $this->findRawCellValue($row, $columnId, $column);
                $cellValue = $this->normalizeCellValue($column, $rawCellValue, $hasRawCellValue);

                if ($cellValue === null || $cellValue === '') {
                    continue;
                }

                $normalizedRow[$columnId] = $cellValue;
            }

            if ($field->staticRows || $this->rowHasContent($normalizedRow)) {
                $normalized[] = $normalizedRow;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function extractRows(TableField $field, mixed $value): ?array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            $decoded = JsonHelper::decodeIfJson($trimmed);
            if ($decoded === $trimmed) {
                return null;
            }

            return $this->extractRows($field, $decoded);
        }

        if (!is_array($value)) {
            return null;
        }

        if (isset($value['rows']) && is_array($value['rows'])) {
            return array_values(array_filter($value['rows'], static fn(mixed $row) => is_array($row)));
        }

        if (array_is_list($value)) {
            return array_values(array_filter($value, static fn(mixed $row) => is_array($row)));
        }

        if ($this->looksLikeRowObject($field, $value)) {
            return [$value];
        }

        return null;
    }

    private function looksLikeRowObject(TableField $field, array $row): bool
    {
        foreach ($this->editableColumns($field) as $columnId => $column) {
            $key = $this->responseKeyForColumn($columnId, $column);
            if (array_key_exists($columnId, $row) || array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function staticRowBase(TableField $field, int $index): array
    {
        if (!$field->staticRows) {
            return [];
        }

        $row = is_array(($field->defaults ?? [])[$index] ?? null) ? $field->defaults[$index] : [];
        $rowId = trim((string)($row['rowId'] ?? ''));

        return $rowId !== '' ? ['rowId' => $rowId] : [];
    }

    /**
     * @return array{0:bool,1:mixed}
     */
    private function findRawCellValue(array $row, string $columnId, array $column): array
    {
        if (array_key_exists($columnId, $row)) {
            return [true, $row[$columnId]];
        }

        $key = $this->responseKeyForColumn($columnId, $column);
        if ($key !== $columnId && array_key_exists($key, $row)) {
            return [true, $row[$key]];
        }

        return [false, null];
    }

    private function normalizeCellValue(array $column, mixed $value, bool $hasExplicitValue = true): mixed
    {
        $type = (string)($column['type'] ?? 'singleline');

        return match ($type) {
            'checkbox', 'lightswitch' => $this->normalizeBooleanCellValue($column, $value, $hasExplicitValue),
            'color' => $this->normalizeColor($value),
            'date' => $this->normalizeDate($value),
            'time' => $this->normalizeTime($value),
            'number' => $this->normalizeNumber($value),
            'select' => $this->normalizeSelectValue($column, $value),
            'email', 'url', 'multiline', 'singleline' => $this->normalizeString($value),
            default => $this->normalizeString($value),
        };
    }

    private function normalizeString(mixed $value): ?string
    {
        if (is_array($value) || is_object($value) && !method_exists($value, '__toString')) {
            return null;
        }

        $normalized = trim((string)$value);
        return $normalized === '' ? null : $normalized;
    }

    private function normalizeColor(mixed $value): ?string
    {
        $normalized = strtolower(trim((string)$value));
        if ($normalized === '' || $normalized === '#') {
            return null;
        }

        if ($normalized[0] !== '#') {
            $normalized = '#' . $normalized;
        }

        if (preg_match('/^#[0-9a-f]{3}$/', $normalized) === 1) {
            return sprintf(
                '#%1$s%1$s%2$s%2$s%3$s%3$s',
                $normalized[1],
                $normalized[2],
                $normalized[3],
            );
        }

        return preg_match('/^#[0-9a-f]{6}$/', $normalized) === 1 ? $normalized : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeTime(mixed $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime(['time' => $raw], true);
        return $date ? $date->format('H:i:s') : null;
    }

    private function normalizeNumber(mixed $value): float|int|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        $cleaned = preg_replace('/[^\d.+-]/', '', str_replace([',', ' '], '', $raw));
        if ($cleaned === null || $cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }

        $number = (float)$cleaned;
        return floor($number) === $number ? (int)$number : $number;
    }

    private function normalizeSelectValue(array $column, mixed $value): ?string
    {
        $options = $this->normalizedSelectOptions($column);

        $normalized = OptionFieldValueHelper::normalizeSingleValue($value, $options);
        return $normalized !== '' ? $normalized : $this->normalizeString($value);
    }

    private function validateRowCount(TableField $field, array $rows): bool
    {
        if ($field->staticRows) {
            return count($rows) === count($field->defaults ?? []);
        }

        $count = count($rows);
        if ($field->minRows && $count < (int)$field->minRows) {
            return false;
        }

        if ($field->maxRows && $count > (int)$field->maxRows) {
            return false;
        }

        return true;
    }

    private function validateCellValue(array $column, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        $type = (string)($column['type'] ?? 'singleline');

        return match ($type) {
            'color' => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1,
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            'time' => is_string($value) && preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $value) === 1,
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'number' => is_int($value) || is_float($value) || is_numeric((string)$value),
            'select' => $this->matchesSelectOption($column, (string)$value),
            default => true,
        };
    }

    private function matchesSelectOption(array $column, string $value): bool
    {
        $options = $this->normalizedSelectOptions($column);

        return OptionFieldValueHelper::matchesSingleValue(trim($value), $options);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function normalizeBooleanCellValue(array $column, mixed $value, bool $hasExplicitValue): mixed
    {
        if (!$hasExplicitValue) {
            return null;
        }

        return $this->toBool($value) ? ($column['value'] ?? 1) : false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function friendlyRowsForContext(TableField $field, mixed $value): array
    {
        $rows = $this->normalizeRows($field, $value);
        if ($rows === null || $rows === []) {
            return [];
        }

        $friendly = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $friendlyRow = [];
            foreach ($this->editableColumns($field) as $columnId => $column) {
                if (!array_key_exists($columnId, $row)) {
                    continue;
                }

                $key = $this->responseKeyForColumn($columnId, $column);
                $friendlyRow[$key] = $row[$columnId];
            }

            if ($field->staticRows) {
                $friendlyRow['_rowLabel'] = $this->rowLabel($field, ($field->defaults ?? [])[$index] ?? [], $index);
            }

            if ($friendlyRow !== []) {
                $friendly[] = $friendlyRow;
            }
        }

        return $friendly;
    }

    private function rowLabel(TableField $field, array $defaultRow, int $index): string
    {
        foreach ($field->columns as $columnId => $column) {
            if (!is_array($column) || ($column['type'] ?? '') !== 'heading') {
                continue;
            }

            $value = trim((string)($defaultRow[$columnId] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($this->editableColumns($field) as $columnId => $column) {
            $value = trim((string)($defaultRow[$columnId] ?? ''));
            if ($value !== '') {
                return sprintf('Row %d: %s', $index + 1, $value);
            }
        }

        return sprintf('Row %d', $index + 1);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowHasContent(array $row): bool
    {
        foreach ($row as $key => $value) {
            if ($key === 'rowId') {
                continue;
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    private function normalizedSelectOptions(array $column): array
    {
        return is_array($column['options'] ?? null)
            ? array_values(array_filter(array_map(
                static function(mixed $option): ?array {
                    if (!is_array($option)) {
                        return null;
                    }

                    $optionValue = trim((string)($option['value'] ?? ''));
                    if ($optionValue === '') {
                        return null;
                    }

                    return [
                        'value' => $optionValue,
                        'label' => trim((string)($option['label'] ?? $optionValue)),
                    ];
                },
                $column['options'],
            )))
            : [];
    }
}
