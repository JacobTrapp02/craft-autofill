<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use craft\base\Model;
use jtdev\craftautofill\models\ai\AiGenerationRequest;

interface AiProviderInterface
{
    public function getProviderKey(): string;

    /**
     * Sends a typed generation request to the provider and returns raw provider payload.
     */
    public function generate(AiGenerationRequest $request, Model $providerConfig): array;
}
