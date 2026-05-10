<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields;

use craft\base\Component;
use craft\models\EntryType;
use jtdev\craftautofill\AutofillPlugin;

class FieldDiscoveryService extends Component
{
    private const NATIVE_ENTRY_FIELDS = [
        [
            'uid' => '__native__:title',
            'handle' => 'title',
            'name' => 'Title',
            'type' => 'craft\\base\\Element::title',
            'adapter' => 'plainText',
            'availableInFreeVersion' => true,
        ],
        [
            'uid' => '__native__:slug',
            'handle' => 'slug',
            'name' => 'Slug',
            'type' => 'craft\\base\\Element::slug',
            'adapter' => 'plainText',
            'availableInFreeVersion' => true,
        ],
        [
            'uid' => '__native__:postDate',
            'handle' => 'postDate',
            'name' => 'Post Date',
            'type' => 'craft\\elements\\Entry::postDate',
            'adapter' => 'date',
            'availableInFreeVersion' => true,
        ],
        [
            'uid' => '__native__:expiryDate',
            'handle' => 'expiryDate',
            'name' => 'Expiration Date',
            'type' => 'craft\\elements\\Entry::expiryDate',
            'adapter' => 'date',
            'availableInFreeVersion' => true,
        ],
        [
            'uid' => '__native__:enabled',
            'handle' => 'enabled',
            'name' => 'Enabled',
            'type' => 'craft\\base\\Element::enabled',
            'adapter' => 'lightswitch',
            'availableInFreeVersion' => true,
        ],
    ];

    public FieldAdapterService $fieldAdapterService;

    public function init(): void
    {
        parent::init();
        $this->fieldAdapterService ??= AutofillPlugin::getInstance()->getFieldAdapterService();
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
            return self::NATIVE_ENTRY_FIELDS;
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

        return array_merge(self::NATIVE_ENTRY_FIELDS, $supported);
    }
}
