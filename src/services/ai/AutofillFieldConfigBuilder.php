<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use Craft;
use craft\base\Component;
use jtdev\craftautofill\AutofillPlugin;
use jtdev\craftautofill\fields\AutofillField;
use RuntimeException;

class AutofillFieldConfigBuilder extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function buildFromFieldId(int $fieldId): array
    {
        $field = Craft::$app->getFields()->getFieldById($fieldId);
        if (!$field instanceof AutofillField) {
            throw new RuntimeException(sprintf('Autofill field %d could not be found.', $fieldId));
        }

        $fieldContractsByUid = [];
        $fieldNameByUid = [];
        $fieldHandleByUid = [];
        $contextValueByUid = [];

        foreach ($this->nativeFields() as $uid => $meta) {
            $fieldNameByUid[$uid] = $meta['name'];
            $fieldHandleByUid[$uid] = $meta['handle'];
            $fieldContractsByUid[$uid] = array_filter([
                'type' => $meta['type'],
                'format' => $meta['format'] ?? null,
            ], static fn($value) => $value !== null);
            $contextValueByUid[$uid] = '';
        }

        if ($field->entryTypeUid !== '') {
            $entryType = Craft::$app->getEntries()->getEntryTypeByUid($field->entryTypeUid);
            $adapterService = AutofillPlugin::getInstance()->getFieldAdapterService();

            foreach ($entryType?->getFieldLayout()->getCustomFields() ?? [] as $layoutField) {
                $uid = (string)($layoutField->uid ?? '');
                if ($uid === '') {
                    continue;
                }

                $adapter = $adapterService->getAdapterForField($layoutField);
                if ($adapter === null) {
                    continue;
                }

                $fieldContractsByUid[$uid] = $adapter->buildPromptContract($layoutField);
                $fieldNameByUid[$uid] = (string)($layoutField->name ?? $uid);
                $fieldHandleByUid[$uid] = (string)($layoutField->handle ?? '');
                $contextValueByUid[$uid] = '';
            }
        }

        return [
            'globalPrompt' => $field->globalPrompt,
            'rows' => $field->rows,
            'contextRows' => $field->contextRows,
            'fieldNameByUid' => $fieldNameByUid,
            'fieldHandleByUid' => $fieldHandleByUid,
            'fieldContractsByUid' => $fieldContractsByUid,
            'contextValueByUid' => $contextValueByUid,
        ];
    }

    /**
     * @return array<string, array{name:string, handle:string, type:string, format?:string}>
     */
    private function nativeFields(): array
    {
        return [
            '__native__:title' => ['name' => 'Title', 'handle' => 'title', 'type' => 'string'],
            '__native__:slug' => ['name' => 'Slug', 'handle' => 'slug', 'type' => 'string'],
            '__native__:postDate' => ['name' => 'Post Date', 'handle' => 'postDate', 'type' => 'string', 'format' => 'date-time'],
            '__native__:expiryDate' => ['name' => 'Expiration Date', 'handle' => 'expiryDate', 'type' => 'string', 'format' => 'date-time'],
            '__native__:enabled' => ['name' => 'Enabled', 'handle' => 'enabled', 'type' => 'boolean'],
        ];
    }
}
