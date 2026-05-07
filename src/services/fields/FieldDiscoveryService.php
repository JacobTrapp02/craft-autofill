<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields;

use craft\base\Component;
use craft\models\EntryType;
use jtdev\craftautofill\AutofillPlugin;

class FieldDiscoveryService extends Component
{
    public FieldAdapterService $fieldAdapterService;

    public function init(): void
    {
        parent::init();
        $this->fieldAdapterService ??= AutofillPlugin::getInstance()->fieldAdapterService;
    }

    /**
     * Returns only fields that currently have a registered field adapter.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSupportedEntryTypeFields(EntryType $entryType): array
    {
        $fieldLayout = $entryType->getFieldLayout();
        if ($fieldLayout === null) {
            return [];
        }

        $supported = [];

        foreach ($fieldLayout->getCustomFields() as $field) {
            $adapter = $this->fieldAdapterService->getAdapterForField($field);
            if ($adapter === null) {
                continue;
            }

            $supported[] = [
                'uid' => (string)($field->uid ?? ''),
                'handle' => (string)($field->handle ?? ''),
                'name' => (string)($field->name ?? ''),
                'type' => $field::class,
                'adapter' => $adapter->getKey(),
                'availableInFreeVersion' => $adapter->isAvailableInFreeVersion(),
            ];
        }

        return $supported;
    }
}
