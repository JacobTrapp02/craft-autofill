<?php

declare(strict_types=1);

namespace jtdev\craftautofill\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use jtdev\craftautofill\AutofillPlugin;
use jtdev\craftautofill\fields\AutofillField;
use jtdev\craftautofill\models\ai\OpenAiConfig;
use RuntimeException;
use Throwable;
use yii\web\Response;

class PromptPreviewController extends Controller
{
    public function actionBuildPrompt(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $body = Craft::$app->getRequest()->getBodyParams();
            $fieldId = (int)($body['fieldId'] ?? 0);

            if ($fieldId <= 0) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Autofill field ID was invalid.',
                ]);
            }

            $prompt = AutofillPlugin::getInstance()->getAiService()->buildAutofillPromptPreview(
                (string)($body['userPrompt'] ?? ''),
                $fieldId
            );

            return $this->asJson([
                'success' => true,
                'prompt' => $prompt,
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $exception->getMessage()
                    : 'Could not build prompt preview.',
            ]);
        }
    }

    public function actionGenerateSuggestions(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $startedAt = microtime(true);
        $logId = null;

        try {
            $body = Craft::$app->getRequest()->getBodyParams();
            $fieldId = (int)($body['fieldId'] ?? 0);
            $entryId = (int)($body['entryId'] ?? 0);
            $siteIdRaw = $body['siteId'] ?? null;
            $siteId = is_numeric($siteIdRaw) ? (int)$siteIdRaw : null;
            $userPrompt = (string)($body['userPrompt'] ?? '');

            if ($fieldId <= 0) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Autofill field ID was invalid.',
                    'suggestions' => [],
                ]);
            }

            $field = $this->resolveAutofillField($fieldId);
            $modelConfig = $this->resolveModelConfigForField($field);
            $plugin = AutofillPlugin::getInstance();
            $aiService = $plugin->getAiService();
            $instruction = $aiService->buildAutofillPromptPreview($userPrompt, $fieldId);
            $request = $aiService->promptBuilder->buildGenerationRequest(
                '',
                $instruction,
                $modelConfig,
                ['fieldId' => $fieldId]
            );
            $detailedResult = $aiService->generateFromRequestDetailed($request, $modelConfig);
            $generationResult = $detailedResult['result'];
            $rawResponse = $detailedResult['rawResponse'];
            $responseRawText = $this->extractResponseRawText($rawResponse);
            $usage = $this->extractTokenUsage($rawResponse);
            $providerResponseId = isset($rawResponse['id']) && is_string($rawResponse['id'])
                ? $rawResponse['id']
                : null;
            $latencyMs = (int)round((microtime(true) - $startedAt) * 1000);
            $requestPayload = [
                'model' => $modelConfig->modelId,
                'reasoningEffort' => $modelConfig->reasoningEffort,
                'messages' => $request->messages,
                'context' => $request->context,
            ];
            $logId = $plugin->getAiRequestLogService()->begin([
                'fieldId' => $fieldId,
                'entryId' => $entryId > 0 ? $entryId : null,
                'siteId' => $siteId,
                'userId' => $this->getCurrentUserId(),
                'provider' => $modelConfig->provider,
                'modelConfigUid' => $modelConfig->uid !== '' ? $modelConfig->uid : null,
                'modelId' => $modelConfig->modelId !== '' ? $modelConfig->modelId : null,
                'reasoningEffort' => $modelConfig->reasoningEffort,
                'requestPrompt' => $instruction,
                'requestPayloadJson' => $this->encodeJson($requestPayload),
                'success' => false,
            ]);

            if (!$generationResult->success) {
                $plugin->getAiRequestLogService()->complete($logId, [
                    'responseRawText' => $responseRawText,
                    'responsePayloadJson' => $this->encodeJson($rawResponse),
                    'success' => false,
                    'errorMessage' => $generationResult->error ?: 'AI generation failed.',
                    'latencyMs' => $latencyMs,
                    'inputTokens' => $usage['inputTokens'],
                    'outputTokens' => $usage['outputTokens'],
                    'totalTokens' => $usage['totalTokens'],
                    'providerResponseId' => $providerResponseId,
                ]);

                return $this->asJson([
                    'success' => false,
                    'error' => $generationResult->error ?: 'AI generation failed.',
                    'suggestions' => [],
                ]);
            }

            $normalizedResult = $aiService->normalizeAutofillResponse(
                Json::encode($generationResult->suggestions),
                $fieldId
            );

            $isDevMode = Craft::$app->getConfig()->getGeneral()->devMode;
            $debugPayload = $isDevMode ? [
                'providerNormalizedSuggestions' => $generationResult->suggestions,
            ] : null;

            if (!$normalizedResult->success) {
                $plugin->getAiRequestLogService()->complete($logId, [
                    'responseRawText' => $responseRawText,
                    'responsePayloadJson' => $this->encodeJson($rawResponse),
                    'success' => false,
                    'errorMessage' => $normalizedResult->error ?: 'Could not normalize generated suggestions.',
                    'latencyMs' => $latencyMs,
                    'inputTokens' => $usage['inputTokens'],
                    'outputTokens' => $usage['outputTokens'],
                    'totalTokens' => $usage['totalTokens'],
                    'providerResponseId' => $providerResponseId,
                ]);

                return $this->asJson([
                    'success' => false,
                    'error' => $normalizedResult->error ?: 'Could not normalize generated suggestions.',
                    'suggestions' => [],
                    'debug' => $debugPayload,
                ]);
            }

            $plugin->getAiRequestLogService()->complete($logId, [
                'responseRawText' => $responseRawText,
                'responsePayloadJson' => $this->encodeJson($rawResponse),
                'success' => true,
                'errorMessage' => null,
                'latencyMs' => $latencyMs,
                'inputTokens' => $usage['inputTokens'],
                'outputTokens' => $usage['outputTokens'],
                'totalTokens' => $usage['totalTokens'],
                'providerResponseId' => $providerResponseId,
            ]);

            return $this->asJson([
                'success' => true,
                'error' => null,
                'suggestions' => $normalizedResult->suggestions,
                'debug' => $debugPayload,
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
            if ($logId !== null) {
                AutofillPlugin::getInstance()->getAiRequestLogService()->complete($logId, [
                    'success' => false,
                    'errorMessage' => $exception->getMessage(),
                    'latencyMs' => (int)round((microtime(true) - $startedAt) * 1000),
                ]);
            }

            return $this->asJson([
                'success' => false,
                'suggestions' => [],
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $exception->getMessage()
                    : 'Could not generate suggestions.',
            ]);
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

    private function getCurrentUserId(): ?int
    {
        $id = Craft::$app->getUser()->getId();
        if (!is_numeric($id)) {
            return null;
        }

        return (int)$id;
    }

    private function resolveAutofillField(int $fieldId): AutofillField
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        if (!$field instanceof AutofillField) {
            throw new RuntimeException(sprintf('Autofill field %d could not be found.', $fieldId));
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
}
