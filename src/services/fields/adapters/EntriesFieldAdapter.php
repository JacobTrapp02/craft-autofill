<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Entry as EntryElement;
use craft\fields\Entries;

class EntriesFieldAdapter extends AbstractRelatedElementsFieldAdapter
{
    public function getKey(): string
    {
        return 'entries';
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Entries;
    }

    protected function elementTypeClass(): string
    {
        return EntryElement::class;
    }

    protected function displayTypeName(): string
    {
        return 'Entries';
    }
}
