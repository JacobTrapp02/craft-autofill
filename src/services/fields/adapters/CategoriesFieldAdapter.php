<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use craft\base\FieldInterface;
use craft\elements\Category;
use craft\fields\Categories;

class CategoriesFieldAdapter extends AbstractRelatedElementsFieldAdapter
{
    public function getKey(): string
    {
        return 'categories';
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof Categories;
    }

    protected function elementTypeClass(): string
    {
        return Category::class;
    }

    protected function displayTypeName(): string
    {
        return 'Categories';
    }
}
