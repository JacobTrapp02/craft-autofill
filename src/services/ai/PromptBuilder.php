<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use craft\base\Component;
use craft\helpers\Json;
use jtdev\craftautofill\models\ai\AiGenerationRequest;
use jtdev\craftautofill\models\ai\OpenAiConfig;

class PromptBuilder extends Component
{
    public AutofillFieldConfigBuilder $fieldConfigBuilder;

    public function init(): void
    {
        parent::init();

        $this->fieldConfigBuilder ??= new AutofillFieldConfigBuilder();
    }

    public function buildAutofillPromptPreview(string $userPrompt, int $fieldId): string
    {
        $config = $this->fieldConfigBuilder->buildFromFieldId($fieldId);
        $activeRows = [];

        foreach (($config['rows'] ?? []) as $row) {
            if (!is_array($row) || ($row['enabled'] ?? true) === false) {
                continue;
            }

            $targetFieldUid = (string)($row['targetFieldUid'] ?? '');
            $targetFieldName = (string)($config['fieldNameByUid'][$targetFieldUid] ?? $targetFieldUid);
            $targetFieldHandle = (string)($config['fieldHandleByUid'][$targetFieldUid] ?? '');
            $includeCurrentFieldValue = $this->asBool($row['includeCurrentFieldValue'] ?? true);
            $overrideCurrentValue = $this->asBool($row['overrideCurrentValue'] ?? true);
            $currentFieldValue = $config['contextValueByUid'][$targetFieldUid] ?? '';
            $hasCurrentValue = trim((string)$currentFieldValue) !== '';

            if (!$overrideCurrentValue && $targetFieldHandle !== '' && $hasCurrentValue) {
                continue;
            }

            $payload = [
                'targetFieldName' => $targetFieldName,
                'fieldContract' => $config['fieldContractsByUid'][$targetFieldUid] ?? [],
                'prompt' => (string)($row['prompt'] ?? ''),
            ];

            if ($includeCurrentFieldValue && $hasCurrentValue) {
                $payload['currentFieldValue'] = $currentFieldValue;
            }

            $activeRows[] = $payload;
        }

        $activeContextRows = [];

        foreach (($config['contextRows'] ?? []) as $row) {
            if (!is_array($row) || ($row['enabled'] ?? true) === false) {
                continue;
            }

            $fieldUid = (string)($row['fieldUid'] ?? '');
            $activeContextRows[] = [
                'fieldName' => (string)($config['fieldNameByUid'][$fieldUid] ?? $fieldUid),
                'fieldType' => (string)($config['fieldContractsByUid'][$fieldUid]['type'] ?? ''),
                'contextPrompt' => (string)($row['prompt'] ?? ''),
                'value' => $config['contextValueByUid'][$fieldUid] ?? '',
            ];
        }

        $instruction = implode("\n", [
            'You are filling structured content fields.',
            'You will receive:',
            '1) Global instructions',
            '2) User input',
            '3) Context fields (read-only)',
            '4) Generation rows to fill',
            '',
            'Return only valid JSON as an object with a suggestions array in generation row order.',
            'Each object must include: fieldName, value.',
            'Do not include markdown or explanations.',
        ]);

        $globalPromptText = trim((string)($config['globalPrompt'] ?? ''));
        $globalPromptSection = $globalPromptText !== '' ? $globalPromptText : '(none)';
        $userPromptSection = trim($userPrompt) !== '' ? trim($userPrompt) : '(none)';

        return implode("\n", [
            'SYSTEM INSTRUCTION',
            $instruction,
            '',
            'GLOBAL PROMPT',
            $globalPromptSection,
            '',
            'USER INPUT',
            $userPromptSection,
            '',
            'CONTEXT_FIELDS_JSON',
            Json::encode($activeContextRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'GENERATION_ROWS_JSON',
            Json::encode($activeRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            '',
            'RESPONSE_FORMAT_JSON',
            '{"suggestions":[{"fieldName":"Example Field","value":"..."}]}',
        ]);
    }

    public function buildGenerationRequest(
        string $sourceContent,
        string $instruction,
        OpenAiConfig $config,
        array $context = [],
        array $dependencyValues = [],
    ): AiGenerationRequest {
        $systemPrompt = implode("\n\n", array_filter([
            'You are an assistant that generates field suggestions for Craft CMS entries.',
            'Return your best draft based on the instruction and context.',
            'Do not include explanations unless explicitly requested.',
            'Prefer JSON-compatible output when asked.',
        ]));

        $userPromptSections = [
            "Instruction:\n" . trim($instruction),
            "Source Content:\n" . trim($sourceContent),
        ];

        if ($context !== []) {
            $userPromptSections[] = 'Read-only Context: ' . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if ($dependencyValues !== []) {
            $userPromptSections[] = 'Dependency Values: ' . json_encode($dependencyValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $prompt = implode("\n\n", $userPromptSections);

        return new AiGenerationRequest([
            'modelConfigUid' => $config->uid,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'context' => [
                'sourceContent' => $sourceContent,
                'context' => $context,
                'dependencyValues' => $dependencyValues,
            ],
        ]);
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
}
