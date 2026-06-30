<?php

declare(strict_types=1);

namespace jtdev\craftautofill\jobs;

use craft\queue\BaseJob;
use jtdev\craftautofill\AutofillPlugin;

class RunAutofillEntryJob extends BaseJob
{
    public int $fieldId;
    public int $entryId;
    public ?int $siteId = null;
    public string $userPrompt = '';
    public string $source = 'queue';

    public function execute($queue): void
    {
        AutofillPlugin::getInstance()
            ->getBulkAutofillService()
            ->runForEntry($this->fieldId, null, $this->entryId, $this->siteId, $this->userPrompt, $this->source);
    }

    protected function defaultDescription(): ?string
    {
        return sprintf('Running Autofill field %d for entry %d', $this->fieldId, $this->entryId);
    }
}
