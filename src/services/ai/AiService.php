<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use craft\base\Component;
use craft\base\Model;
use jtdev\craftautofill\models\ai\AiGenerationRequest;
use jtdev\craftautofill\models\ai\AiGenerationResult;
use jtdev\craftautofill\models\ai\OpenAiConfig;
use jtdev\craftautofill\services\ai\providers\OpenAiProvider;
use RuntimeException;

class AiService extends Component
{
    public PromptBuilder $promptBuilder;
    public AiResponseNormalizer $responseNormalizer;

    /**
     * @var AiProviderInterface[]
     */
    private array $providers = [];

    public function init(): void
    {
        parent::init();

        $this->promptBuilder ??= new PromptBuilder();
        $this->responseNormalizer ??= new AiResponseNormalizer();

        if ($this->providers === []) {
            $this->registerProvider(new OpenAiProvider());
        }
    }

    public function generate(
        string $fieldId,
        array $userProvidedContent = [],
    ): AiGenerationResult {
        throw new RuntimeException(sprintf(
            'AiService::generate() is pending Autofill field integration. Received fieldId (%s) and %d user-provided content item(s).',
            $fieldId,
            count($userProvidedContent)
        ));
    }

    public function buildAutofillPromptPreview(string $userPrompt, int $fieldId): string
    {
        return $this->promptBuilder->buildAutofillPromptPreview($userPrompt, $fieldId);
    }

    public function normalizeAutofillResponse(string $rawResponse, int $fieldId): AiGenerationResult
    {
        return $this->responseNormalizer->normalizeAutofillResponse($rawResponse, $fieldId);
    }

    public function generateFromRequest(AiGenerationRequest $request, Model $providerConfig): AiGenerationResult
    {
        $result = $this->generateFromRequestDetailed($request, $providerConfig);

        return $result['result'];
    }

    /**
     * @return array{result:AiGenerationResult,rawResponse:array}
     */
    public function generateFromRequestDetailed(AiGenerationRequest $request, Model $providerConfig): array
    {
        if (!$request->validate()) {
            throw new RuntimeException('AI generation request validation failed.');
        }

        $provider = $this->resolveProvider($this->getProviderKeyForConfig($providerConfig));
        $rawResponse = $provider->generate($request, $providerConfig);
        $result = $this->responseNormalizer->normalize($rawResponse, $request);

        return [
            'result' => $result,
            'rawResponse' => $rawResponse,
        ];
    }

    // Provider registry stuff
    public function registerProvider(AiProviderInterface $provider): void
    {
        $this->providers[$provider->getProviderKey()] = $provider;
    }

    private function resolveProvider(string $providerKey): AiProviderInterface
    {
        if (isset($this->providers[$providerKey])) {
            return $this->providers[$providerKey];
        }

        throw new RuntimeException(sprintf('No AI provider registered for key "%s".', $providerKey));
    }

    private function getProviderKeyForConfig(Model $providerConfig): string
    {
        if ($providerConfig instanceof OpenAiConfig) {
            return 'openai';
        }

        throw new RuntimeException(sprintf('Unsupported provider config class "%s".', $providerConfig::class));
    }
}
