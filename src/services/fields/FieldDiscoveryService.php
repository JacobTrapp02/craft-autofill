<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields;

use craft\base\Component;
use craft\base\FieldInterface;
use craft\fields\Link as LinkField;
use craft\fields\linktypes\Asset as AssetLinkType;
use craft\fields\linktypes\Category as CategoryLinkType;
use craft\fields\linktypes\Entry as EntryLinkType;
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
            'availableInLiteVersion' => true,
        ],
        [
            'uid' => '__native__:slug',
            'handle' => 'slug',
            'name' => 'Slug',
            'type' => 'craft\\base\\Element::slug',
            'adapter' => 'plainText',
            'availableInLiteVersion' => true,
        ],
        [
            'uid' => '__native__:postDate',
            'handle' => 'postDate',
            'name' => 'Post Date',
            'type' => 'craft\\elements\\Entry::postDate',
            'adapter' => 'date',
            'availableInLiteVersion' => true,
        ],
        [
            'uid' => '__native__:expiryDate',
            'handle' => 'expiryDate',
            'name' => 'Expiration Date',
            'type' => 'craft\\elements\\Entry::expiryDate',
            'adapter' => 'date',
            'availableInLiteVersion' => true,
        ],
        [
            'uid' => '__native__:enabled',
            'handle' => 'enabled',
            'name' => 'Enabled',
            'type' => 'craft\\base\\Element::enabled',
            'adapter' => 'lightswitch',
            'availableInLiteVersion' => true,
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

            $supported[] = $this->describeSupportedField($field, $adapter->getKey(), $adapter->isAvailableInLiteVersion());
        }

        return array_merge(self::NATIVE_ENTRY_FIELDS, $supported);
    }

    /**
     * @return array<string, mixed>
     */
    public function describeSupportedField(FieldInterface $field, string $adapterKey, bool $availableInLiteVersion): array
    {
        $layoutElementUid = trim((string)($field->layoutElement->uid ?? ''));
        $fieldUid = trim((string)($field->uid ?? ''));

        $meta = [
            'uid' => $layoutElementUid !== '' ? $layoutElementUid : $fieldUid,
            'fieldUid' => $fieldUid,
            'layoutElementUid' => $layoutElementUid,
            'handle' => (string)($field->handle ?? ''),
            'name' => (string)($field->name ?? ''),
            'type' => $field::class,
            'adapter' => $adapterKey,
            'availableInLiteVersion' => $availableInLiteVersion,
        ];

        if ($field instanceof LinkField) {
            $meta['linkScopeSummary'] = $this->buildLinkScopeSummary($field);
        }

        return $meta;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildLinkScopeSummary(LinkField $field): array
    {
        $summary = [
            'entry' => ['enabled' => false, 'summary' => 'Not enabled in this Link field.'],
            'category' => ['enabled' => false, 'summary' => 'Not enabled in this Link field.'],
            'asset' => ['enabled' => false, 'summary' => 'Not enabled in this Link field.'],
        ];

        foreach ($field->getLinkTypes() as $linkType) {
            if ($linkType instanceof EntryLinkType) {
                $summary['entry'] = [
                    'enabled' => true,
                    'summary' => $this->entryScopeSummary($linkType->sources),
                ];
                continue;
            }

            if ($linkType instanceof CategoryLinkType) {
                $summary['category'] = [
                    'enabled' => true,
                    'summary' => $this->categoryScopeSummary($linkType->sources),
                ];
                continue;
            }

            if ($linkType instanceof AssetLinkType) {
                $summary['asset'] = [
                    'enabled' => true,
                    'summary' => $this->assetScopeSummary($linkType->sources, $linkType->allowedKinds),
                ];
            }
        }

        return $summary;
    }

    private function entryScopeSummary(?array $sources): string
    {
        $sectionNames = [];
        foreach ($this->normalizedSourceList($sources) as $source) {
            if (preg_match('/^section:([0-9a-f\-]+)$/i', $source, $match) !== 1) {
                continue;
            }

            $section = \Craft::$app->getEntries()->getSectionByUid($match[1]);
            if ($section?->name) {
                $sectionNames[] = $section->name;
            }
        }

        if ($sectionNames === []) {
            return 'Any section.';
        }

        return 'Sections: ' . implode(', ', array_values(array_unique($sectionNames))) . '.';
    }

    private function categoryScopeSummary(?array $sources): string
    {
        $groupNames = [];
        foreach ($this->normalizedSourceList($sources) as $source) {
            if (preg_match('/^(group|categorygroup):([0-9a-f\-]+)$/i', $source, $match) !== 1) {
                continue;
            }

            $group = \Craft::$app->getCategories()->getGroupByUid($match[2]);
            if ($group?->name) {
                $groupNames[] = $group->name;
            }
        }

        if ($groupNames === []) {
            return 'Any category group.';
        }

        return 'Category groups: ' . implode(', ', array_values(array_unique($groupNames))) . '.';
    }

    private function assetScopeSummary(?array $sources, mixed $allowedKinds): string
    {
        $volumeNames = [];
        foreach ($this->normalizedSourceList($sources) as $source) {
            if (preg_match('/^volume:([0-9a-f\-]+)$/i', $source, $match) !== 1) {
                continue;
            }

            $volume = \Craft::$app->getVolumes()->getVolumeByUid($match[1]);
            if ($volume?->name) {
                $volumeNames[] = $volume->name;
            }
        }

        $parts = [];
        $parts[] = $volumeNames === []
            ? 'Any volume'
            : 'Volumes: ' . implode(', ', array_values(array_unique($volumeNames)));

        $kinds = is_array($allowedKinds) ? array_values(array_filter(array_map(static fn(mixed $kind): string => trim((string)$kind), $allowedKinds))) : [];
        $parts[] = $kinds === []
            ? 'Any file type'
            : 'File types: ' . implode(', ', array_map(static fn(string $kind): string => strtoupper($kind), array_values(array_unique($kinds))));

        return implode('. ', $parts) . '.';
    }

    /**
     * @return string[]
     */
    private function normalizedSourceList(?array $sources): array
    {
        if ($sources === null || $sources === [] || $sources === ['*']) {
            return [];
        }

        $normalized = [];
        foreach ($sources as $source) {
            $raw = trim((string)$source);
            if ($raw !== '' && $raw !== '*') {
                $normalized[] = $raw;
            }
        }

        return array_values(array_unique($normalized));
    }
}
