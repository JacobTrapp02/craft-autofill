<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use craft\base\Component;
use craft\helpers\Json;
use jtdev\craftautofill\models\ai\AiGenerationRequest;
use jtdev\craftautofill\models\ai\AiGenerationResult;

class AiResponseNormalizer extends Component
{
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
}
