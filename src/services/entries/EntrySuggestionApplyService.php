<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\entries;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use DateTime;
use jtdev\craftautofill\AutofillPlugin;
use RuntimeException;

class EntrySuggestionApplyService extends Component
{
    /**
     * @param array<string, mixed> $suggestion
     * @return array<string, mixed>
     */
    public function applySuggestion(int $fieldId, int $entryId, ?int $siteId, array $suggestion): array
    {
        if ($entryId <= 0) {
            throw new RuntimeException('Entry ID is required to apply suggestions.');
        }

        $entry = Craft::$app->getElements()->getElementById($entryId, Entry::class, $siteId);
        if (!$entry instanceof Entry) {
            throw new RuntimeException(sprintf('Entry %d could not be found.', $entryId));
        }

        $targetFieldUid = trim((string)($suggestion['targetFieldUid'] ?? ''));
        $matchedHandle = trim((string)($suggestion['matchedHandle'] ?? ''));
        $rawValue = $suggestion['value'] ?? null;

        if ($targetFieldUid === '' && $matchedHandle === '') {
            throw new RuntimeException('Suggestion is missing target field information.');
        }

        $appliedValue = $rawValue;
        $targetHandle = '';

        if (str_starts_with($targetFieldUid, '__native__:')) {
            $this->applyNativeFieldValue($entry, $targetFieldUid, $appliedValue);
        } else {
            $field = $targetFieldUid !== '' ? Craft::$app->getFields()->getFieldByUid($targetFieldUid) : null;
            if ($field === null) {
                throw new RuntimeException(sprintf('Field UID "%s" could not be found.', $targetFieldUid));
            }

            $adapter = AutofillPlugin::getInstance()->getFieldAdapterService()->getAdapterForField($field);
            if ($adapter === null) {
                throw new RuntimeException(sprintf('No adapter available for field UID "%s".', $targetFieldUid));
            }

            $targetHandle = (string)($field->handle ?? '');
            $appliedValue = $adapter->applySuggestionToEntry($field, $entry, $rawValue);
        }

        if (!Craft::$app->getElements()->saveElement($entry, false, false, false)) {
            $errors = $entry->getErrorSummary(true);
            Craft::error(sprintf(
                'Autofill entry suggestion save failed for entry %d and target "%s": %s',
                $entryId,
                $targetFieldUid,
                $errors !== [] ? implode(' ', $errors) : 'Entry save failed while applying suggestion.'
            ), __METHOD__);

            throw new RuntimeException($errors !== []
                ? implode(' ', $errors)
                : 'Entry save failed while applying suggestion.');
        }

        return [
            'entryId' => (int)$entry->id,
            'targetFieldUid' => $targetFieldUid,
            'matchedHandle' => $matchedHandle,
            'savedValue' => $appliedValue,
        ];
    }

    private function applyNativeFieldValue(Entry $entry, string $targetFieldUid, mixed $value): void
    {
        switch ($targetFieldUid) {
            case '__native__:title':
                $entry->title = trim((string)$value);
                return;

            case '__native__:slug':
                $entry->slug = trim((string)$value);
                return;

            case '__native__:enabled':
                $entry->enabled = $this->asBool($value);
                return;

            case '__native__:postDate':
                $entry->postDate = $this->toDateTimeOrNull($value);
                return;

            case '__native__:expiryDate':
                $entry->expiryDate = $this->toDateTimeOrNull($value);
                return;

            default:
                throw new RuntimeException(sprintf('Unsupported native field UID "%s".', $targetFieldUid));
        }
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function toDateTimeOrNull(mixed $value): ?DateTime
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            return new DateTime($raw);
        } catch (\Throwable) {
            throw new RuntimeException(sprintf('Invalid date value "%s".', $raw));
        }
    }
}
