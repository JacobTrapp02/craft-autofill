<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use jtdev\craftautofill\fields\AutofillField;

class EntryTypeFieldResolverService extends Component
{
    public function resolveForAutofillField(AutofillField $autofillField, string $fieldUid): ?FieldInterface
    {
        return $this->resolveByEntryTypeUid($autofillField->entryTypeUid, $fieldUid);
    }

    public function resolveByEntryTypeUid(string $entryTypeUid, string $fieldUid): ?FieldInterface
    {
        $normalizedFieldUid = trim($fieldUid);
        if ($normalizedFieldUid === '' || str_starts_with($normalizedFieldUid, '__native__:')) {
            return null;
        }

        $normalizedEntryTypeUid = trim($entryTypeUid);
        if ($normalizedEntryTypeUid !== '') {
            $entryType = Craft::$app->getEntries()->getEntryTypeByUid($normalizedEntryTypeUid);
            foreach ($entryType?->getFieldLayout()->getCustomFields() ?? [] as $layoutField) {
                $layoutElementUid = trim((string)($layoutField->layoutElement->uid ?? ''));
                $fieldInstanceUid = trim((string)($layoutField->uid ?? ''));
                if (
                    ($layoutElementUid !== '' && $layoutElementUid === $normalizedFieldUid) ||
                    ($fieldInstanceUid !== '' && $fieldInstanceUid === $normalizedFieldUid)
                ) {
                    return $layoutField;
                }
            }
        }

        $field = Craft::$app->getFields()->getFieldByUid($normalizedFieldUid);
        return $field instanceof FieldInterface ? $field : null;
    }

    public function canonicalizeByEntryTypeUid(string $entryTypeUid, string $fieldUid): string
    {
        $field = $this->resolveByEntryTypeUid($entryTypeUid, $fieldUid);
        if (!$field instanceof FieldInterface) {
            return trim($fieldUid);
        }

        $layoutElementUid = trim((string)($field->layoutElement->uid ?? ''));
        if ($layoutElementUid !== '') {
            return $layoutElementUid;
        }

        return trim((string)($field->uid ?? $fieldUid));
    }
}
