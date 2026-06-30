<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\entries;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\Json;
use jtdev\craftautofill\AutofillPlugin;
use jtdev\craftautofill\fields\AutofillField;
use jtdev\craftautofill\jobs\RunAutofillEntryJob;
use jtdev\craftautofill\models\ai\OpenAiConfig;
use RuntimeException;
use Throwable;

class BulkAutofillService extends Component
{
    /**
     * @return array{fieldId:int,entries:array<int,array{entryId:int,success:bool,suggestionsApplied:int,error:string|null}>,total:int,succeeded:int,failed:int,suggestionsApplied:int}
     */
    public function run(
        ?int $fieldId,
        ?string $fieldSlug,
        string|array $entryIds,
        ?int $siteId = null,
        string $userPrompt = '',
        string $source = 'bulk',
        ?callable $onStart = null,
        ?callable $onEntryProcessed = null,
    ): array {
        if (!AutofillPlugin::getInstance()->isProEdition()) {
            throw new RuntimeException('Bulk Autofill is only available in Autofill Pro.');
        }

        $ids = is_array($entryIds) ? $this->normalizeEntryIds($entryIds) : $this->parseEntryIds($entryIds);
        if ($ids === []) {
            throw new RuntimeException('No valid entry IDs were provided.');
        }

        $field = $this->resolveAutofillField($fieldId, $fieldSlug);
        $resolvedFieldId = (int)$field->id;
        $modelConfig = $this->resolveModelConfigForField($field);
        $entries = [];
        $succeeded = 0;
        $failed = 0;
        $suggestionsApplied = 0;

        if ($onStart !== null) {
            $onStart($resolvedFieldId, count($ids));
        }

        foreach ($ids as $entryId) {
            $result = $this->processEntry($resolvedFieldId, $entryId, $siteId, $modelConfig, $userPrompt, $source);
            $entries[] = $result;

            if ($onEntryProcessed !== null) {
                $onEntryProcessed($result);
            }

            if ($result['success']) {
                $succeeded++;
                $suggestionsApplied += $result['suggestionsApplied'];
                continue;
            }

            $failed++;
        }

        return [
            'fieldId' => $resolvedFieldId,
            'entries' => $entries,
            'total' => count($ids),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'suggestionsApplied' => $suggestionsApplied,
        ];
    }

    /**
     * Queues Autofill jobs for one Autofill field across one or more entries.
     *
     * @return array{fieldId:int,entries:array<int,array{entryId:int,queued:bool,jobId:mixed,error:string|null}>,total:int,queued:int,failed:int}
     */
    public function queue(
        ?int $fieldId,
        ?string $fieldSlug,
        string|array $entryIds,
        ?int $siteId = null,
        string $userPrompt = '',
        string $source = 'bulk-queue',
        ?callable $onStart = null,
        ?callable $onEntryQueued = null,
    ): array {
        if (!AutofillPlugin::getInstance()->isProEdition()) {
            throw new RuntimeException('Bulk Autofill is only available in Autofill Pro.');
        }

        $ids = is_array($entryIds) ? $this->normalizeEntryIds($entryIds) : $this->parseEntryIds($entryIds);
        if ($ids === []) {
            throw new RuntimeException('No valid entry IDs were provided.');
        }

        $field = $this->resolveAutofillField($fieldId, $fieldSlug);
        $resolvedFieldId = (int)$field->id;
        $entries = [];
        $queued = 0;
        $failed = 0;

        if ($onStart !== null) {
            $onStart($resolvedFieldId, count($ids));
        }

        foreach ($ids as $entryId) {
            try {
                $jobId = $this->queueForEntry($resolvedFieldId, null, $entryId, $siteId, $userPrompt, $source);
                $result = [
                    'entryId' => $entryId,
                    'queued' => true,
                    'jobId' => $jobId,
                    'error' => null,
                ];
                $queued++;
            } catch (Throwable $exception) {
                $result = [
                    'entryId' => $entryId,
                    'queued' => false,
                    'jobId' => null,
                    'error' => $exception->getMessage(),
                ];
                $failed++;
            }

            $entries[] = $result;

            if ($onEntryQueued !== null) {
                $onEntryQueued($result);
            }
        }

        return [
            'fieldId' => $resolvedFieldId,
            'entries' => $entries,
            'total' => count($ids),
            'queued' => $queued,
            'failed' => $failed,
        ];
    }

    /**
     * Queues Autofill for a single entry and field.
     */
    public function queueForEntry(
        ?int $fieldId,
        ?string $fieldSlug,
        int $entryId,
        ?int $siteId = null,
        string $userPrompt = '',
        string $source = 'queue',
    ): mixed {
        if (!AutofillPlugin::getInstance()->isProEdition()) {
            throw new RuntimeException('Bulk Autofill is only available in Autofill Pro.');
        }

        if ($entryId <= 0) {
            throw new RuntimeException('A valid entry ID is required.');
        }

        $field = $this->resolveAutofillField($fieldId, $fieldSlug);

        return Craft::$app->getQueue()->push(new RunAutofillEntryJob([
            'fieldId' => (int)$field->id,
            'entryId' => $entryId,
            'siteId' => $siteId,
            'userPrompt' => $userPrompt,
            'source' => $source,
        ]));
    }

    /**
     * Runs Autofill immediately for a single entry and field.
     *
     * @return array{entryId:int,success:bool,suggestionsApplied:int,error:string|null}
     */
    public function runForEntry(
        ?int $fieldId,
        ?string $fieldSlug,
        int $entryId,
        ?int $siteId = null,
        string $userPrompt = '',
        string $source = 'api',
    ): array {
        if (!AutofillPlugin::getInstance()->isProEdition()) {
            throw new RuntimeException('Bulk Autofill is only available in Autofill Pro.');
        }

        if ($entryId <= 0) {
            throw new RuntimeException('A valid entry ID is required.');
        }

        $field = $this->resolveAutofillField($fieldId, $fieldSlug);
        $resolvedFieldId = (int)$field->id;
        $modelConfig = $this->resolveModelConfigForField($field);

        return $this->processEntry($resolvedFieldId, $entryId, $siteId, $modelConfig, $userPrompt, $source);
    }

    /**
     * @return int[]
     */
    public function parseEntryIds(string $entryIds): array
    {
        return $this->normalizeEntryIds(explode(',', $entryIds));
    }

    /**
     * @param array<int|string, mixed> $entryIds
     * @return int[]
     */
    private function normalizeEntryIds(array $entryIds): array
    {
        $ids = [];

        foreach ($entryIds as $rawId) {
            $id = (int)trim((string)$rawId);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function resolveAutofillField(?int $fieldId, ?string $fieldSlug): AutofillField
    {
        $fieldId = is_numeric($fieldId) ? (int)$fieldId : 0;
        $fieldSlug = trim((string)$fieldSlug);

        if ($fieldId <= 0 && $fieldSlug === '') {
            throw new RuntimeException('Specify an Autofill field with --field-id=123 or --field-slug=fieldHandle.');
        }

        if ($fieldId > 0 && $fieldSlug !== '') {
            throw new RuntimeException('Specify only one of --field-id or --field-slug.');
        }

        if ($fieldId > 0) {
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            if (!$field instanceof AutofillField) {
                throw new RuntimeException(sprintf('Autofill field %d could not be found.', $fieldId));
            }

            return $field;
        }

        $field = Craft::$app->getFields()->getFieldByHandle($fieldSlug);
        if (!$field instanceof AutofillField) {
            throw new RuntimeException(sprintf(
                'Autofill field with slug/handle "%s" could not be found.',
                $fieldSlug
            ));
        }

        return $field;
    }

    private function resolveModelConfigForField(AutofillField $field): OpenAiConfig
    {
        $settings = AutofillPlugin::getInstance()->getSettings();
        $targetUid = trim($field->modelConfigUid);
        $chosen = null;

        foreach ($settings->modelConfigs as $row) {
            if (!is_array($row) || !($row['enabled'] ?? false)) {
                continue;
            }

            $uid = trim((string)($row['uid'] ?? ''));
            if ($targetUid !== '' && $uid !== $targetUid) {
                continue;
            }

            $chosen = $row;
            break;
        }

        if ($chosen === null) {
            foreach ($settings->modelConfigs as $row) {
                if (!is_array($row) || !($row['enabled'] ?? false)) {
                    continue;
                }

                $chosen = $row;
                break;
            }
        }

        if (!is_array($chosen)) {
            throw new RuntimeException('No enabled AI model configuration is available.');
        }

        $modelConfig = new OpenAiConfig($chosen);
        if (!$modelConfig->validate()) {
            $message = implode(' ', array_values($modelConfig->getFirstErrors()));
            throw new RuntimeException($message !== '' ? $message : 'Selected AI model configuration is invalid.');
        }

        return $modelConfig;
    }

    /**
     * @return array{entryId:int,success:bool,suggestionsApplied:int,error:string|null}
     */
    private function processEntry(
        int $fieldId,
        int $entryId,
        ?int $siteId,
        OpenAiConfig $modelConfig,
        string $userPrompt,
        string $source,
    ): array {
        $startedAt = microtime(true);
        $logId = null;
        $plugin = AutofillPlugin::getInstance();

        try {
            $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
            if (!$entry instanceof Entry) {
                throw new RuntimeException(sprintf('Entry %d could not be found.', $entryId));
            }

            $aiService = $plugin->getAiService();
            $instruction = $aiService->buildAutofillPromptPreview($userPrompt, $fieldId, $entryId, $siteId);
            $request = $aiService->promptBuilder->buildGenerationRequest(
                '',
                $instruction,
                $modelConfig,
                ['fieldId' => $fieldId, 'entryId' => $entryId, 'source' => $source]
            );

            $requestPayload = [
                'model' => $modelConfig->modelId,
                'reasoningEffort' => $modelConfig->reasoningEffort,
                'messages' => $request->messages,
                'context' => $request->context,
            ];

            $logId = $plugin->getAiRequestLogService()->begin([
                'fieldId' => $fieldId,
                'entryId' => $entryId,
                'siteId' => $siteId,
                'provider' => $modelConfig->provider,
                'modelConfigUid' => $modelConfig->uid !== '' ? $modelConfig->uid : null,
                'modelId' => $modelConfig->modelId !== '' ? $modelConfig->modelId : null,
                'reasoningEffort' => $modelConfig->reasoningEffort,
                'requestPrompt' => $instruction,
                'requestPayloadJson' => $this->encodeJson($requestPayload),
                'success' => false,
            ]);

            $detailedResult = $aiService->generateFromRequestDetailed($request, $modelConfig);
            $generationResult = $detailedResult['result'];
            $rawResponse = $detailedResult['rawResponse'];
            $responseRawText = $this->extractResponseRawText($rawResponse);
            $usage = $this->extractTokenUsage($rawResponse);
            $providerResponseId = isset($rawResponse['id']) && is_string($rawResponse['id'])
                ? $rawResponse['id']
                : null;

            if (!$generationResult->success) {
                throw new RuntimeException($generationResult->error ?: 'AI generation failed.');
            }

            $normalizedResult = $aiService->normalizeAutofillResponse(
                Json::encode($generationResult->suggestions),
                $fieldId
            );

            if (!$normalizedResult->success) {
                throw new RuntimeException($normalizedResult->error ?: 'Could not normalize generated suggestions.');
            }

            $appliedCount = 0;
            foreach ($normalizedResult->suggestions as $suggestion) {
                if (!is_array($suggestion)) {
                    continue;
                }

                $plugin->getEntrySuggestionApplyService()->applySuggestion($fieldId, $entryId, $siteId, $suggestion);
                $appliedCount++;
            }

            $plugin->getAiRequestLogService()->complete($logId, [
                'responseRawText' => $responseRawText,
                'responsePayloadJson' => $this->encodeJson($rawResponse),
                'success' => true,
                'errorMessage' => null,
                'latencyMs' => (int)round((microtime(true) - $startedAt) * 1000),
                'inputTokens' => $usage['inputTokens'],
                'outputTokens' => $usage['outputTokens'],
                'totalTokens' => $usage['totalTokens'],
                'providerResponseId' => $providerResponseId,
            ]);

            return [
                'entryId' => $entryId,
                'success' => true,
                'suggestionsApplied' => $appliedCount,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $plugin->getAiRequestLogService()->complete($logId, [
                'fieldId' => $fieldId,
                'entryId' => $entryId,
                'siteId' => $siteId,
                'provider' => $modelConfig->provider,
                'modelConfigUid' => $modelConfig->uid !== '' ? $modelConfig->uid : null,
                'modelId' => $modelConfig->modelId !== '' ? $modelConfig->modelId : null,
                'reasoningEffort' => $modelConfig->reasoningEffort,
                'success' => false,
                'errorMessage' => $exception->getMessage(),
                'latencyMs' => (int)round((microtime(true) - $startedAt) * 1000),
            ]);

            Craft::error(sprintf(
                'Bulk Autofill failed for field %d and entry %d: %s',
                $fieldId,
                $entryId,
                $exception->getMessage()
            ), __METHOD__);

            return [
                'entryId' => $entryId,
                'success' => false,
                'suggestionsApplied' => 0,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function encodeJson(mixed $value): ?string
    {
        try {
            return Json::encode($value, JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            return null;
        }
    }

    private function extractResponseRawText(array $rawResponse): ?string
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

    /**
     * @return array{inputTokens:?int,outputTokens:?int,totalTokens:?int}
     */
    private function extractTokenUsage(array $rawResponse): array
    {
        $usage = is_array($rawResponse['usage'] ?? null) ? $rawResponse['usage'] : [];
        $inputTokens = $this->toIntOrNull($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null);
        $outputTokens = $this->toIntOrNull($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null);
        $totalTokens = $this->toIntOrNull($usage['total_tokens'] ?? null);

        if ($totalTokens === null && $inputTokens !== null && $outputTokens !== null) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        return [
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
            'totalTokens' => $totalTokens,
        ];
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }
}
