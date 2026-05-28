<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Tag;
use craft\fields\Tags;

class TagsFieldAdapter extends AbstractRelatedElementsFieldAdapter
{
    public function getKey(): string
    {
        return 'tags';
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Tags;
    }

    protected function elementTypeClass(): string
    {
        return Tag::class;
    }

    protected function displayTypeName(): string
    {
        return 'Tags';
    }
}
