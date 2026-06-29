<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use jtdev\craftautofill\AutofillPlugin;
use jtdev\craftautofill\fields\AutofillField;
use RuntimeException;

class AutofillFieldConfigBuilder extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function buildFromFieldId(int $fieldId, ?int $entryId = null, ?int $siteId = null): array
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        if (!$field instanceof AutofillField) {
            throw new RuntimeException(sprintf('Autofill field %d could not be found.', $fieldId));
        }

        $fieldContractsByUid = [];
        $fieldNameByUid = [];
        $fieldHandleByUid = [];
        $fieldAdapterKeyByUid = [];
        $contextValueByUid = [];

        foreach ($this->nativeFields() as $uid => $meta) {
            $fieldNameByUid[$uid] = $meta['name'];
            $fieldHandleByUid[$uid] = $meta['handle'];
            $fieldContractsByUid[$uid] = array_filter([
                'type' => $meta['type'],
                'format' => $meta['format'] ?? null,
            ], static fn($value) => $value !== null);
            $fieldAdapterKeyByUid[$uid] = $meta['adapter'];
            $contextValueByUid[$uid] = '';
        }

        $entry = $this->resolveEntry($entryId, $siteId);
        $adapterService = AutofillPlugin::getInstance()->getFieldAdapterService();
        $rowPromptConfigByUid = [];
        foreach ($field->rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $targetUid = trim((string)($row['targetFieldUid'] ?? ''));
            if ($targetUid === '') {
                continue;
            }

            $rowPromptConfigByUid[$targetUid] = $row;
        }

        if ($entry instanceof Entry) {
            foreach (array_keys($rowPromptConfigByUid) as $targetUid) {
                $rowPromptConfigByUid[$targetUid]['entryId'] = (int)$entry->id;
            }
        }

        if ($field->entryTypeUid !== '') {
            $entryType = Craft::$app->getEntries()->getEntryTypeByUid($field->entryTypeUid);

            foreach ($entryType?->getFieldLayout()->getCustomFields() ?? [] as $layoutField) {
                $uid = trim((string)($layoutField->layoutElement->uid ?? ''));
                if ($uid === '') {
                    $uid = trim((string)($layoutField->uid ?? ''));
                }
                if ($uid === '') {
                    continue;
                }

                $adapter = $adapterService->getAdapterForField($layoutField);
                if ($adapter === null) {
                    continue;
                }

                $fieldContractsByUid[$uid] = $adapter->buildPromptContract($layoutField, $rowPromptConfigByUid[$uid] ?? []);
                $fieldNameByUid[$uid] = (string)($layoutField->name ?? $uid);
                $fieldHandleByUid[$uid] = (string)($layoutField->handle ?? '');
                $fieldAdapterKeyByUid[$uid] = $adapter->getKey();
                $contextValueByUid[$uid] = $this->resolveCustomFieldContextValue($entry, $layoutField->handle ?? '', $layoutField, $adapter);
            }
        }

        if ($entry instanceof Entry) {
            $contextValueByUid['__native__:title'] = trim((string)($entry->title ?? ''));
            $contextValueByUid['__native__:slug'] = trim((string)($entry->slug ?? ''));
            $contextValueByUid['__native__:enabled'] = $entry->enabled ? 'true' : 'false';
            $contextValueByUid['__native__:postDate'] = $entry->postDate?->format(\DateTimeInterface::ATOM) ?? '';
            $contextValueByUid['__native__:expiryDate'] = $entry->expiryDate?->format(\DateTimeInterface::ATOM) ?? '';
        }

        return [
            'globalPrompt' => $field->globalPrompt,
            'rows' => $field->rows,
            'contextRows' => $field->contextRows,
            'fieldNameByUid' => $fieldNameByUid,
            'fieldHandleByUid' => $fieldHandleByUid,
            'fieldContractsByUid' => $fieldContractsByUid,
            'fieldAdapterKeyByUid' => $fieldAdapterKeyByUid,
            'contextValueByUid' => $contextValueByUid,
        ];
    }

    private function resolveEntry(?int $entryId, ?int $siteId): ?Entry
    {
        if (!is_numeric($entryId) || (int)$entryId <= 0) {
            return null;
        }

        $resolved = Craft::$app->getElements()->getElementById(
            (int)$entryId,
            Entry::class,
            is_numeric($siteId) && (int)$siteId > 0 ? (int)$siteId : null,
        );

        return $resolved instanceof Entry ? $resolved : null;
    }

    private function resolveCustomFieldContextValue(
        ?Entry $entry,
        mixed $handle,
        mixed $layoutField,
        mixed $adapter,
    ): string {
        if (!$entry instanceof Entry) {
            return '';
        }

        $fieldHandle = trim((string)$handle);
        if ($fieldHandle === '') {
            return '';
        }

        $rawValue = $entry->getFieldValue($fieldHandle);

        try {
            $adapterContextValue = trim((string)$adapter->getContextValue($layoutField, $rawValue));
            if ($adapterContextValue !== '') {
                return $adapterContextValue;
            }
        } catch (\Throwable) {
            // Fall through to generic fallback conversion.
        }

        return $this->stringifyContextFallback($rawValue);
    }

    private function stringifyContextFallback(mixed $value, int $depth = 0): string
    {
        if ($depth > 3 || $value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $text = $this->stringifyContextFallback($item, $depth + 1);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return trim(implode("\n\n", $parts));
        }

        if ($value instanceof \JsonSerializable) {
            try {
                return $this->stringifyContextFallback($value->jsonSerialize(), $depth + 1);
            } catch (\Throwable) {
                // Continue with object-based fallbacks below.
            }
        }

        if (is_object($value)) {
            foreach (['getText', 'getValue', 'getRawContent', '__toString'] as $method) {
                if (!method_exists($value, $method)) {
                    continue;
                }

                try {
                    $text = $this->stringifyContextFallback($value->{$method}(), $depth + 1);
                } catch (\Throwable) {
                    continue;
                }

                if ($text !== '') {
                    return $text;
                }
            }

            foreach (['content', 'value', 'text', 'html'] as $property) {
                if (!isset($value->{$property})) {
                    continue;
                }

                $text = $this->stringifyContextFallback($value->{$property}, $depth + 1);
                if ($text !== '') {
                    return $text;
                }
            }

            if ($value instanceof \Stringable) {
                return trim((string)$value);
            }
        }

        return '';
    }

    /**
     * @return array<string, array{name:string, handle:string, type:string, format?:string}>
     */
    private function nativeFields(): array
    {
        return [
            '__native__:title' => ['name' => 'Title', 'handle' => 'title', 'type' => 'string', 'adapter' => 'plainText'],
            '__native__:slug' => ['name' => 'Slug', 'handle' => 'slug', 'type' => 'string', 'adapter' => 'plainText'],
            '__native__:postDate' => ['name' => 'Post Date', 'handle' => 'postDate', 'type' => 'string', 'format' => 'date-time', 'adapter' => 'date'],
            '__native__:expiryDate' => ['name' => 'Expiration Date', 'handle' => 'expiryDate', 'type' => 'string', 'format' => 'date-time', 'adapter' => 'date'],
            '__native__:enabled' => ['name' => 'Enabled', 'handle' => 'enabled', 'type' => 'boolean', 'adapter' => 'lightswitch'],
        ];
    }
}
