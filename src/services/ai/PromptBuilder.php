<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use craft\base\Component;
use jtdev\craftautofill\models\ai\AiGenerationRequest;
use jtdev\craftautofill\models\ai\OpenAiConfig;

class PromptBuilder extends Component
{
    public function buildGenerationRequest(
        string $sourceContent,
        string $instruction,
        OpenAiConfig $config,
        array $context = [],
        array $dependencyValues = []
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
}
