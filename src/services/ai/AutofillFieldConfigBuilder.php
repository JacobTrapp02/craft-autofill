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
        $fillRuntimeSpecsByUid = [];
        $reviewUiSpecsByUid = [];
        $fieldNameByUid = [];
        $fieldHandleByUid = [];
        $contextValueByUid = [];

        foreach ($this->nativeFields() as $uid => $meta) {
            $fieldNameByUid[$uid] = $meta['name'];
            $fieldHandleByUid[$uid] = $meta['handle'];
            $fieldContractsByUid[$uid] = ['type' => $meta['type']];
            $fillRuntimeSpecsByUid[$uid] = $this->nativeFillRuntimeSpec($uid);
            $reviewUiSpecsByUid[$uid] = ['inputControl' => 'textarea'];
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
                $fillRuntimeSpecsByUid[$uid] = $adapter->getFillRuntimeSpec($layoutField);
                $reviewUiSpecsByUid[$uid] = $adapter->getReviewUiSpec($layoutField);
                $fieldNameByUid[$uid] = (string)($layoutField->name ?? $uid);
                $fieldHandleByUid[$uid] = (string)($layoutField->handle ?? '');
                $contextValueByUid[$uid] = '';
            }
        }

        $fillRuntimeSpecsByHandle = [];
        foreach ($fieldHandleByUid as $uid => $handle) {
            $normalizedHandle = trim((string)$handle);
            if ($normalizedHandle === '') {
                continue;
            }

            $fillRuntimeSpecsByHandle[$normalizedHandle] = $fillRuntimeSpecsByUid[$uid] ?? [];
        }

        return [
            'globalPrompt' => $field->globalPrompt,
            'rows' => $field->rows,
            'contextRows' => $field->contextRows,
            'fieldNameByUid' => $fieldNameByUid,
            'fieldHandleByUid' => $fieldHandleByUid,
            'fieldContractsByUid' => $fieldContractsByUid,
            'fillRuntimeSpecsByUid' => $fillRuntimeSpecsByUid,
            'fillRuntimeSpecsByHandle' => $fillRuntimeSpecsByHandle,
            'reviewUiSpecsByUid' => $reviewUiSpecsByUid,
            'contextValueByUid' => $contextValueByUid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nativeFillRuntimeSpec(string $uid): array
    {
        return match ($uid) {
            '__native__:enabled' => [
                'inputKind' => 'checkbox',
                'applyVia' => 'native',
                'acceptanceCheck' => 'checkedState',
            ],
            '__native__:postDate', '__native__:expiryDate' => [
                'inputKind' => 'dateSplit',
                'applyVia' => 'native',
                'acceptanceCheck' => 'valueRoundTrip',
                'includesTime' => true,
            ],
            default => [
                'inputKind' => 'text',
                'applyVia' => 'native',
                'acceptanceCheck' => 'valueRoundTrip',
            ],
        };
    }

    /**
     * @return array<string, array{name:string, handle:string, type:string}>
     */
    private function nativeFields(): array
    {
        return [
            '__native__:title' => ['name' => 'Title', 'handle' => 'title', 'type' => 'string'],
            '__native__:slug' => ['name' => 'Slug', 'handle' => 'slug', 'type' => 'string'],
            '__native__:postDate' => ['name' => 'Post Date', 'handle' => 'postDate', 'type' => 'string'],
            '__native__:expiryDate' => ['name' => 'Expiration Date', 'handle' => 'expiryDate', 'type' => 'string'],
            '__native__:enabled' => ['name' => 'Enabled', 'handle' => 'enabled', 'type' => 'boolean'],
        ];
    }
}
