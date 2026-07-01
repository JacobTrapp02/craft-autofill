<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\fields\BaseRelationField;
use craft\fields\Table as TableField;
use craft\helpers\Cp;
use craft\helpers\Json;
use jtdev\craftautofill\AutofillPlugin;
use jtdev\craftautofill\fields\AutofillField;
use jtdev\craftautofill\models\ai\AiGenerationRequest;
use jtdev\craftautofill\models\ai\AiGenerationResult;
use Throwable;
use yii\base\InvalidArgumentException;

class AiResponseNormalizer extends Component
{
    public AutofillFieldConfigBuilder $fieldConfigBuilder;

    public function init(): void
    {
        parent::init();

        $this->fieldConfigBuilder ??= new AutofillFieldConfigBuilder();
    }

    public function normalize(array $rawResponse, AiGenerationRequest $request): AiGenerationResult
    {
        $text = $this->extractText($rawResponse);

        if ($text === null || trim($text) === '') {
            return new AiGenerationResult([
                'success' => false,
                'suggestions' => [],
                'confidence' => $this->extractConfidence($rawResponse),
                'error' => 'Provider response did not include text output.',
            ]);
        }

        $parsed = Json::decodeIfJson($text);
        if (!is_array($parsed)) {
            return new AiGenerationResult([
                'success' => false,
                'suggestions' => [],
                'confidence' => $this->extractConfidence($rawResponse),
                'error' => 'Provider output was not valid JSON.',
            ]);
        }

        $result = new AiGenerationResult([
            'success' => true,
            'suggestions' => $parsed,
            'confidence' => $this->extractConfidence($rawResponse),
            'error' => null,
        ]);

        if (!$result->validate()) {
            $result->success = false;
            $result->error = 'Normalized result failed validation.';
        }

        return $result;
    }

    public function normalizeAutofillResponse(
        string $rawResponse,
        int $fieldId,
        ?int $entryId = null,
        ?int $siteId = null,
    ): AiGenerationResult
    {
        try {
            $parsed = $this->parseSuggestionJson($rawResponse);
            $config = $this->fieldConfigBuilder->buildFromFieldId($fieldId, $entryId, $siteId);
            $autofillField = $this->resolveAutofillField($fieldId);
        } catch (InvalidArgumentException $exception) {
            return new AiGenerationResult([
                'success' => false,
                'suggestions' => [],
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return new AiGenerationResult([
                'success' => false,
                'suggestions' => [],
                'error' => 'Could not load Autofill field configuration.',
            ]);
        }

        $suggestions = [];
        $targetFieldMap = $this->buildTargetFieldMap($config);
        $orderedTargetFields = $this->buildOrderedTargetFields($config);

        foreach ($parsed as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $fieldName = trim((string)($item['fieldName'] ?? ''));
            if ($fieldName === '') {
                continue;
            }

            $hasRawValue = array_key_exists('value', $item);
            $valueIsNull = $hasRawValue && $item['value'] === null;
            $rawValue = $hasRawValue && !$valueIsNull ? $item['value'] : '';
            $match = $this->resolveTargetFieldMatch($fieldName, $targetFieldMap)
                ?? $this->resolveTargetFieldMatchByOrder($index, $orderedTargetFields);
            $displayFieldName = trim((string)($match['name'] ?? $fieldName));
            $targetFieldUid = (string)($match['targetFieldUid'] ?? '');
            $fieldContract = is_array($match['fieldContract'] ?? null) ? $match['fieldContract'] : [];
            $adapterKey = (string)($match['adapterKey'] ?? '');
            $relatedConfig = is_array($match['related'] ?? null) ? $match['related'] : [];
            $currentValue = $match['currentValue'] ?? null;
            $validationErrors = [];

            if (!$hasRawValue) {
                $validationErrors[] = 'Suggestion is missing a value.';
            } elseif ($valueIsNull) {
                $validationErrors[] = 'Suggestion value cannot be null.';
            }

            if ($match === null) {
                $validationErrors[] = 'Suggestion field could not be matched to a configured field.';
            }

            $value = $this->normalizeSuggestionValue($autofillField, $targetFieldUid, $rawValue, $fieldContract);
            $value = $this->forceCurrentRelatedValues($autofillField, $targetFieldUid, $value, $currentValue, $relatedConfig, $adapterKey);
            $validationErrors = array_merge(
                $validationErrors,
                $this->validateSuggestionValue($autofillField, $targetFieldUid, $rawValue, $value, $fieldContract)
            );
            [$displayValue, $displayValueIsLabel] = $this->resolveDisplayValue($value, $fieldContract);
            $reviewEditor = $this->buildReviewEditorPayload(
                $autofillField,
                $targetFieldUid,
                $value,
                $fieldContract,
                $displayValue,
                $displayValueIsLabel,
                $adapterKey,
            );

            $suggestions[] = [
                'fieldName' => $fieldName,
                'displayFieldName' => $displayFieldName !== '' ? $displayFieldName : $fieldName,
                'value' => $value,
                'reviewEditor' => $reviewEditor,
                'hasRawValue' => $hasRawValue,
                'valueIsNull' => $valueIsNull,
                'targetFieldUid' => $targetFieldUid,
                'matchedHandle' => (string)($match['handle'] ?? ''),
                'requiresApproval' => $this->asBool($match['requiresApproval'] ?? true),
                'overrideCurrentValue' => $this->asBool($match['overrideCurrentValue'] ?? true),
                'validationErrors' => array_values(array_unique($validationErrors)),
            ];
        }

        return new AiGenerationResult([
            'success' => true,
            'suggestions' => $suggestions,
            'error' => null,
        ]);
    }

    private function extractText(array $rawResponse): ?string
    {
        if (isset($rawResponse['output_text']) && is_string($rawResponse['output_text'])) {
            return $rawResponse['output_text'];
        }

        $output = $rawResponse['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $text = $contentItem['text'] ?? null;
                if (is_string($text) && $text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    private function extractConfidence(array $rawResponse): ?float
    {
        $confidence = $rawResponse['confidence'] ?? null;
        if (is_numeric($confidence)) {
            return (float)$confidence;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSuggestionJson(string $rawResponse): array
    {
        $text = trim($rawResponse);
        if ($text === '') {
            throw new InvalidArgumentException('Response cannot be empty.');
        }

        $parsed = Json::decodeIfJson($text);
        if (!is_array($parsed)) {
            $start = strpos($text, '[');
            $end = strrpos($text, ']');

            if ($start !== false && $end !== false && $end > $start) {
                $parsed = Json::decode(substr($text, $start, $end - $start + 1));
            }
        }

        if (!is_array($parsed)) {
            throw new InvalidArgumentException('Response must be valid JSON.');
        }

        if (array_is_list($parsed)) {
            return $parsed;
        }

        $suggestions = $parsed['suggestions'] ?? null;
        if (is_array($suggestions) && array_is_list($suggestions)) {
            return $suggestions;
        }

        $result = $parsed['result'] ?? null;
        if (is_array($result) && array_is_list($result)) {
            return $result;
        }

        throw new InvalidArgumentException('Response must be a JSON array or an object with a suggestions/result array.');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildTargetFieldMap(array $config): array
    {
        $map = [];

        foreach (($config['rows'] ?? []) as $row) {
            if (!is_array($row) || ($row['enabled'] ?? true) === false) {
                continue;
            }

            $targetFieldUid = (string)($row['targetFieldUid'] ?? '');
            $name = (string)($config['fieldNameByUid'][$targetFieldUid] ?? $targetFieldUid);
            $handle = (string)($config['fieldHandleByUid'][$targetFieldUid] ?? '');

            if ($name === '' || $handle === '') {
                continue;
            }

            $map[$this->normalizeName($name)] = [
                'targetFieldUid' => $targetFieldUid,
                'name' => $name,
                'handle' => $handle,
                'requiresApproval' => $row['requiresApproval'] ?? true,
                'overrideCurrentValue' => $row['overrideCurrentValue'] ?? true,
                'related' => $row['related'] ?? [],
                'currentValue' => $config['currentValueByUid'][$targetFieldUid] ?? null,
                'fieldContract' => $config['fieldContractsByUid'][$targetFieldUid] ?? [],
                'adapterKey' => (string)($config['fieldAdapterKeyByUid'][$targetFieldUid] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildOrderedTargetFields(array $config): array
    {
        $ordered = [];

        foreach (($config['rows'] ?? []) as $row) {
            if (!is_array($row) || ($row['enabled'] ?? true) === false) {
                continue;
            }

            $targetFieldUid = (string)($row['targetFieldUid'] ?? '');
            $name = (string)($config['fieldNameByUid'][$targetFieldUid] ?? $targetFieldUid);
            $handle = (string)($config['fieldHandleByUid'][$targetFieldUid] ?? '');

            if ($name === '' || $handle === '') {
                continue;
            }

            $ordered[] = [
                'targetFieldUid' => $targetFieldUid,
                'name' => $name,
                'handle' => $handle,
                'requiresApproval' => $row['requiresApproval'] ?? true,
                'overrideCurrentValue' => $row['overrideCurrentValue'] ?? true,
                'related' => $row['related'] ?? [],
                'currentValue' => $config['currentValueByUid'][$targetFieldUid] ?? null,
                'fieldContract' => $config['fieldContractsByUid'][$targetFieldUid] ?? [],
                'adapterKey' => (string)($config['fieldAdapterKeyByUid'][$targetFieldUid] ?? ''),
                'matchedByOrder' => true,
            ];
        }

        return $ordered;
    }

    /**
     * @param array<string, array<string, mixed>> $targetFieldMap
     * @return array<string, mixed>|null
     */
    private function resolveTargetFieldMatch(string $fieldName, array $targetFieldMap): ?array
    {
        $normalized = $this->normalizeName($fieldName);
        if ($normalized !== '' && isset($targetFieldMap[$normalized])) {
            return $targetFieldMap[$normalized];
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $orderedTargetFields
     * @return array<string, mixed>|null
     */
    private function resolveTargetFieldMatchByOrder(int $index, array $orderedTargetFields): ?array
    {
        return $orderedTargetFields[$index] ?? null;
    }

    private function normalizeSuggestionValue(?AutofillField $autofillField, string $fieldUid, mixed $value, array $fieldContract): mixed
    {
        if ($fieldUid === '') {
            return $this->normalizeUnmatchedSuggestionValue($value);
        }

        $field = $this->getCustomField($autofillField, $fieldUid);
        if ($field instanceof FieldInterface) {
            $adapter = AutofillPlugin::getInstance()->getFieldAdapterService()->getAdapterForField($field);
            if ($adapter !== null) {
                try {
                    return $adapter->normalizeSuggestion($field, $value);
                } catch (Throwable) {
                    return '';
                }
            }
        }

        return $this->normalizeByContract($value, $fieldContract);
    }

    private function forceCurrentRelatedValues(
        ?AutofillField $autofillField,
        string $fieldUid,
        mixed $normalizedValue,
        mixed $currentValue,
        array $relatedConfig,
        string $adapterKey,
    ): mixed {
        if (
            !in_array($adapterKey, ['categories', 'tags', 'entries'], true) ||
            !$this->asBool($relatedConfig['forceUseCurrentValues'] ?? false, false) ||
            !is_array($normalizedValue) ||
            !is_array($currentValue)
        ) {
            return $normalizedValue;
        }

        $merged = [];
        foreach (array_merge($currentValue, $normalizedValue) as $title) {
            $trimmed = trim((string)$title);
            if ($trimmed === '') {
                continue;
            }

            $exists = false;
            foreach ($merged as $existing) {
                if (strcasecmp($existing, $trimmed) === 0) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $merged[] = $trimmed;
            }
        }

        $field = $this->getCustomField($autofillField, $fieldUid);
        if ($field instanceof BaseRelationField && is_numeric($field->maxRelations) && (int)$field->maxRelations > 0) {
            return array_slice($merged, 0, (int)$field->maxRelations);
        }

        return $merged;
    }

    private function normalizeUnmatchedSuggestionValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $encoded = Json::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : '';
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                return $this->normalizeUnmatchedSuggestionValue($value->jsonSerialize());
            }

            return $this->normalizeUnmatchedSuggestionValue((array)$value);
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @return string[]
     */
    private function validateSuggestionValue(?AutofillField $autofillField, string $fieldUid, mixed $rawValue, mixed $normalizedValue, array $fieldContract): array
    {
        $errors = [];
        $field = $this->getCustomField($autofillField, $fieldUid);

        if ($field instanceof FieldInterface) {
            $adapter = AutofillPlugin::getInstance()->getFieldAdapterService()->getAdapterForField($field);
            if ($adapter !== null && !$adapter->validateSuggestion($field, $normalizedValue)) {
                $errors[] = 'Suggestion value is not valid for this field type.';
            }
        }

        return array_merge($errors, $this->validateByContract($rawValue, $normalizedValue, $fieldContract));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReviewEditorPayload(
        ?AutofillField $autofillField,
        string $fieldUid,
        mixed $normalizedValue,
        array $fieldContract,
        mixed $displayValue,
        bool $displayValueIsLabel,
        string $adapterKey = '',
    ): array {
        $format = (string)($fieldContract['format'] ?? '');
        if ($format === 'date') {
            return [
                'type' => 'date',
                'source' => 'contract:format=date',
            ];
        }

        if ($format === 'date-time') {
            return [
                'type' => 'datetime',
                'source' => 'contract:format=date-time',
            ];
        }

        if ($format === 'time') {
            return [
                'type' => 'time',
                'source' => 'contract:format=time',
                'minuteIncrement' => max(1, (int)($fieldContract['minuteIncrement'] ?? 1)),
                'min' => trim((string)($fieldContract['min'] ?? '')),
                'max' => trim((string)($fieldContract['max'] ?? '')),
            ];
        }

        $type = (string)($fieldContract['type'] ?? '');
        if ($type === 'boolean') {
            return [
                'type' => 'lightswitch',
                'source' => 'contract:type=boolean',
            ];
        }

        $options = $fieldContract['options'] ?? null;
        if (is_array($options) && $options !== []) {
            $selectionMode = (string)($fieldContract['selectionMode'] ?? 'single');
            return [
                'type' => $adapterKey === 'checkboxes'
                    ? 'checkboxes'
                    : ($adapterKey === 'multiSelect'
                        ? 'multiselect'
                        : ($adapterKey === 'radioButtons'
                            ? 'radioButtons'
                            : ($selectionMode === 'multiple'
                                ? 'multiselect'
                                : ($adapterKey === 'buttonGroup' ? 'buttonGroup' : 'dropdown')))),
                'source' => 'contract:options',
                'displayValue' => $displayValueIsLabel ? $displayValue : $normalizedValue,
                'options' => $this->normalizeReviewOptions($options),
                'selectionMode' => $selectionMode,
            ];
        }

        if ($adapterKey === 'seomatic') {
            $sections = is_array($fieldContract['sections'] ?? null) ? $fieldContract['sections'] : [];

            return [
                'type' => 'seomaticBasic',
                'source' => 'adapter:seomatic',
                'visibleFields' => [
                    'seoTitle' => $this->asBool($sections['seoTitle'] ?? true, true),
                    'siteNamePosition' => $this->asBool($sections['siteNamePosition'] ?? true, true),
                    'seoDescription' => $this->asBool($sections['seoDescription'] ?? true, true),
                    'seoKeywords' => $this->asBool($sections['seoKeywords'] ?? true, true),
                ],
            ];
        }

        if ($adapterKey === 'addresses') {
            return [
                'type' => 'addresses',
                'source' => 'adapter:addresses',
            ];
        }

        if ($adapterKey === 'icon') {
            return [
                'type' => 'iconPreview',
                'source' => 'adapter:icon',
                'iconSvg' => $this->iconSvgForValue($normalizedValue),
            ];
        }

        if ($adapterKey === 'link') {
            return $this->buildLinkReviewEditorPayload($normalizedValue, $fieldContract);
        }

        if ($adapterKey === 'table') {
            return $this->buildTableReviewEditorPayload($autofillField, $fieldUid, $normalizedValue, $fieldContract);
        }

        if ($adapterKey === 'range') {
            return [
                'type' => 'range',
                'source' => 'adapter:range',
                'min' => $fieldContract['min'] ?? 0,
                'max' => $fieldContract['max'] ?? 100,
                'step' => $fieldContract['step'] ?? 1,
                'suffix' => trim((string)($fieldContract['suffix'] ?? '')),
            ];
        }

        if ($adapterKey === 'money') {
            return [
                'type' => 'money',
                'source' => 'adapter:money',
                'currency' => trim((string)($fieldContract['currency'] ?? '')),
                'currencyLabel' => trim((string)($fieldContract['currencyLabel'] ?? '')),
                'showCurrency' => $this->asBool($fieldContract['showCurrency'] ?? false, false),
                'decimals' => max(0, (int)($fieldContract['decimals'] ?? 2)),
                'min' => $fieldContract['min'] ?? null,
                'max' => $fieldContract['max'] ?? null,
            ];
        }

        if ($adapterKey === 'json') {
            return [
                'type' => 'textarea',
                'source' => 'adapter:json',
                'displayMode' => 'json',
            ];
        }

        if (in_array($adapterKey, ['categories', 'tags', 'entries'], true)) {
            return [
                'type' => 'relatedTitles',
                'source' => 'adapter:related',
            ];
        }

        return [
            'type' => 'textarea',
            'source' => 'fallback:textarea',
            'displayMode' => $adapterKey === 'ckeditor' ? 'richtext' : 'default',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTableReviewEditorPayload(?AutofillField $autofillField, string $fieldUid, mixed $normalizedValue, array $fieldContract): array
    {
        $previewHtml = '';
        $field = $this->getCustomField($autofillField, $fieldUid);

        if ($field instanceof TableField) {
            $previewHtml = $this->tablePreviewHtmlForValue($field, $normalizedValue);
        }

        return [
            'type' => 'tablePreview',
            'source' => 'adapter:table',
            'displayMode' => 'table',
            'previewHtml' => $previewHtml,
            'rowCount' => is_array($normalizedValue) ? count($normalizedValue) : 0,
            'columnCount' => is_array($fieldContract['columns'] ?? null) ? count($fieldContract['columns']) : 0,
            'staticRows' => $this->asBool($fieldContract['staticRows'] ?? false, false),
            'columnLabels' => $this->tableReviewColumnLabels($fieldContract['columns'] ?? []),
        ];
    }

    /**
     * @param array<int, mixed> $options
     * @return array<int, array{value:string,label:string}>
     */
    private function normalizeReviewOptions(array $options): array
    {
        $normalized = [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $value = trim((string)($option['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $label = trim((string)($option['label'] ?? $value));
            $normalized[] = [
                'value' => $value,
                'label' => $label !== '' ? $label : $value,
            ];
        }

        return $normalized;
    }

    /**
     * @return array{0:mixed,1:bool}
     */
    private function resolveDisplayValue(mixed $normalizedValue, array $fieldContract): array
    {
        $options = $fieldContract['options'] ?? null;
        if (!is_array($options) || $options === []) {
            return [$normalizedValue, false];
        }

        if (is_array($normalizedValue)) {
            $labels = [];
            $usedLabel = false;

            foreach ($normalizedValue as $item) {
                [$resolved, $isLabel] = $this->resolveDisplayValue($item, $fieldContract);
                $labels[] = $resolved;
                $usedLabel = $usedLabel || $isLabel;
            }

            return [$labels, $usedLabel];
        }

        $normalized = trim((string)$normalizedValue);
        if ($normalized === '') {
            return [$normalizedValue, false];
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionValue = trim((string)($option['value'] ?? ''));
            if ($optionValue !== $normalized) {
                continue;
            }

            $label = trim((string)($option['label'] ?? ''));
            if ($label !== '' && $label !== $normalized) {
                return [$label, true];
            }

            return [$normalizedValue, false];
        }

        return [$normalizedValue, false];
    }

    private function getCustomField(?AutofillField $autofillField, string $fieldUid): ?FieldInterface
    {
        if ($fieldUid === '' || str_starts_with($fieldUid, '__native__:')) {
            return null;
        }

        if (!$autofillField instanceof AutofillField) {
            return null;
        }

        return AutofillPlugin::getInstance()
            ->getEntryTypeFieldResolverService()
            ->resolveForAutofillField($autofillField, $fieldUid);
    }

    private function resolveAutofillField(int $fieldId): ?AutofillField
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        return $field instanceof AutofillField ? $field : null;
    }

    private function normalizeByContract(mixed $value, array $fieldContract): mixed
    {
        $type = (string)($fieldContract['type'] ?? 'string');

        if ($type === 'number') {
            return $this->normalizeNumber($value);
        }

        if ($type === 'boolean') {
            return $this->asBool($value, false);
        }

        if ($type === 'array') {
            return $this->normalizeOptionValues($value, $fieldContract);
        }

        return $this->normalizeOptionValue($value, $fieldContract);
    }

    /**
     * @return string[]
     */
    private function validateByContract(mixed $rawValue, mixed $normalizedValue, array $fieldContract): array
    {
        $errors = [];
        $type = (string)($fieldContract['type'] ?? 'string');

        if ($type === 'number' && !is_numeric($normalizedValue)) {
            $errors[] = 'Suggestion value must be numeric.';
        }

        if ($type === 'boolean' && !$this->isBoolLike($rawValue)) {
            $errors[] = 'Suggestion value must be boolean.';
        }

        if ($type === 'array' && !is_array($normalizedValue)) {
            $errors[] = 'Suggestion value must be an array.';
        }

        $options = $fieldContract['options'] ?? null;

        if (is_array($options) && $options !== [] && $type !== 'array' && $this->hasMultipleNormalizedOptions($rawValue, $fieldContract)) {
            $errors[] = 'Suggestion value must contain exactly one option.';
        }

        $format = (string)($fieldContract['format'] ?? '');
        if (in_array($format, ['date', 'date-time'], true) && strtotime((string)$rawValue) === false) {
            $errors[] = 'Suggestion value must be a valid date.';
        }

        if ($format === 'time' && !$this->isValidTimeValue($normalizedValue)) {
            $errors[] = 'Suggestion value must be a valid time.';
        }

        if ($format === 'email') {
            $candidate = trim((string)$rawValue);
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'Suggestion value must be a valid email address.';
            }
        }

        if ($format === 'color') {
            $candidate = trim((string)$normalizedValue);
            if ($candidate === '' || !$this->isValidColor($candidate)) {
                $errors[] = 'Suggestion value must be a valid color.';
            }
        }

        $allowCustomValue = $this->asBool($fieldContract['allowCustomValue'] ?? false, false);
        if (is_array($options) && $options !== [] && !$allowCustomValue) {
            if ($type === 'array' && !$this->matchesOptions($normalizedValue, $options)) {
                $errors[] = 'Suggestion values must match the configured options.';
            } elseif ($type !== 'array' && !$this->matchesOption($rawValue, $options)) {
                $errors[] = 'Suggestion value must match one of the configured options.';
            }
        }

        return $errors;
    }

    private function normalizeOptionValue(mixed $value, array $fieldContract): string
    {
        $normalizedValues = $this->normalizeOptionValues($value, $fieldContract);
        return $normalizedValues[0] ?? '';
    }

    /**
     * @return string[]
     */
    private function normalizeOptionValues(mixed $value, array $fieldContract): array
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $item) {
                $resolved = $this->normalizeOptionValue($item, $fieldContract);
                if ($resolved !== '' && !in_array($resolved, $normalized, true)) {
                    $normalized[] = $resolved;
                }
            }

            return $normalized;
        }

        $raw = trim((string)$value);
        $options = $fieldContract['options'] ?? null;

        if ($raw !== '' && str_starts_with($raw, '[')) {
            $decoded = Json::decodeIfJson($raw);
            if (is_array($decoded)) {
                return $this->normalizeOptionValues($decoded, $fieldContract);
            }
        }

        if (is_array($options)) {
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $optionValue = trim((string)($option['value'] ?? ''));
                $optionLabel = trim((string)($option['label'] ?? ''));

                if ($raw === $optionValue || strcasecmp($raw, $optionLabel) === 0) {
                    return [$optionValue];
                }
            }
        }

        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|\n|\r\n|;)\s*/', $raw) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn(string $part) => $part !== ''));

        if (count($parts) > 1) {
            return $this->normalizeOptionValues($parts, $fieldContract);
        }

        return [$raw];
    }

    private function normalizeNumber(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        $cleaned = preg_replace('/[^\d.+-]/', '', str_replace([',', ' '], '', (string)$value));
        if ($cleaned === null || $cleaned === '' || in_array($cleaned, ['.', '-', '+'], true) || !is_numeric($cleaned)) {
            return null;
        }

        return (float)$cleaned;
    }

    private function isValidTimeValue(mixed $value): bool
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return false;
        }

        if (preg_match('/^\d{2}:\d{2}$/', $raw) !== 1 && preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw) !== 1) {
            return false;
        }

        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $raw)), 3, 0);
        return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $second >= 0 && $second <= 59;
    }

    /**
     * @param array<string,mixed> $fieldContract
     * @return array<string,mixed>
     */
    private function buildLinkReviewEditorPayload(mixed $normalizedValue, array $fieldContract): array
    {
        $value = is_array($normalizedValue) ? $normalizedValue : [];
        $typeId = trim((string)($value['type'] ?? ''));
        $typeLabels = is_array($fieldContract['typeLabels'] ?? null) ? $fieldContract['typeLabels'] : [];
        $typeLabel = trim((string)($typeLabels[$typeId] ?? $typeId));
        $destination = trim((string)($value['value'] ?? ''));
        $context = '';

        $candidateListKey = $typeId !== '' ? sprintf('%sCandidates', $typeId) : '';
        $candidateList = $candidateListKey !== '' && is_array($fieldContract[$candidateListKey] ?? null)
            ? $fieldContract[$candidateListKey]
            : [];

        foreach ($candidateList as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (trim((string)($candidate['candidateKey'] ?? '')) !== $destination) {
                continue;
            }

            $destination = trim((string)($candidate['title'] ?? $destination));
            $context = trim((string)($candidate['context'] ?? ''));
            break;
        }

        return [
            'type' => 'linkPreview',
            'source' => 'adapter:link',
            'typeLabel' => $typeLabel,
            'destination' => $destination,
            'context' => $context,
            'label' => trim((string)($value['label'] ?? '')),
            'urlSuffix' => trim((string)($value['urlSuffix'] ?? '')),
            'openInNewTab' => $this->asBool($value['openInNewTab'] ?? false, false),
        ];
    }

    /**
     * @param array<int, mixed> $options
     */
    private function matchesOption(mixed $value, array $options): bool
    {
        $raw = trim((string)$value);

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            if ($raw === trim((string)($option['value'] ?? '')) || strcasecmp($raw, trim((string)($option['label'] ?? ''))) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $values
     * @param array<int, mixed> $options
     */
    private function matchesOptions(mixed $values, array $options): bool
    {
        if (!is_array($values) || $values === []) {
            return false;
        }

        foreach ($values as $value) {
            if (!$this->matchesOption($value, $options)) {
                return false;
            }
        }

        return true;
    }

    private function hasMultipleNormalizedOptions(mixed $value, array $fieldContract): bool
    {
        return count($this->normalizeOptionValues($value, $fieldContract)) > 1;
    }

    private function isValidColor(string $value): bool
    {
        return preg_match('/^#?(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($value)) === 1;
    }

    private function iconSvgForValue(mixed $value): string
    {
        $icon = trim((string)$value);
        if ($icon === '') {
            return '';
        }

        try {
            return Cp::iconSvg($icon) ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function tablePreviewHtmlForValue(TableField $field, mixed $value): string
    {
        if (!is_array($value) || empty($field->columns)) {
            return '';
        }

        try {
            $normalizedRows = $field->normalizeValue($value, null);
            if (!is_array($normalizedRows)) {
                return '';
            }

            return Cp::editableTableFieldHtml([
                'id' => sprintf('autofill-review-table-%s', $field->handle ?: $field->uid ?: uniqid('', true)),
                'name' => $field->handle ?: 'autofillReviewTable',
                'cols' => $field->columns,
                'rows' => $normalizedRows,
                'static' => true,
                'fullWidth' => true,
                'allowAdd' => false,
                'allowDelete' => false,
                'allowReorder' => false,
                'staticRows' => (bool)$field->staticRows,
                'includeRowId' => (bool)$field->staticRows,
            ]);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param mixed $columns
     * @return string[]
     */
    private function tableReviewColumnLabels(mixed $columns): array
    {
        if (!is_array($columns)) {
            return [];
        }

        $labels = [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $label = trim((string)($column['label'] ?? $column['key'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function isBoolLike(mixed $value): bool
    {
        if (is_bool($value) || is_numeric($value)) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['0', '1', 'false', 'true', 'off', 'on', 'no', 'yes'], true);
    }

    private function asBool(mixed $value, bool $defaultValue = true): bool
    {
        if ($value === null || $value === '') {
            return $defaultValue;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }

        $normalized = strtolower(trim((string)$value));

        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        return $defaultValue;
    }

    private function normalizeName(mixed $value): string
    {
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return '';
        }

        return strtolower($normalized);
    }
}
