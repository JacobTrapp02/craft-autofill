<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields;

use craft\base\Component;
use craft\base\FieldInterface;
use jtdev\craftautofill\services\fields\adapters\AddressesFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\ButtonGroupFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\CategoriesFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\CheckboxesFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\CkeditorFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\ColorFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\CountryFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\DateFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\DropdownFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\EmailFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\EntriesFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\FieldAdapterInterface;
use jtdev\craftautofill\services\fields\adapters\LightswitchFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\MultiSelectFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\NumberFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\PlainTextFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\RadioButtonsFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\SeomaticFieldAdapter;
use jtdev\craftautofill\services\fields\adapters\TagsFieldAdapter;

class FieldAdapterService extends Component
{
    /**
     * @var FieldAdapterInterface[]
     */
    private array $adapters = [];

    public function init(): void
    {
        parent::init();

        if ($this->adapters === []) {
            $this->registerAdapter(new PlainTextFieldAdapter());
            $this->registerAdapter(new LightswitchFieldAdapter());
            $this->registerAdapter(new NumberFieldAdapter());
            $this->registerAdapter(new DateFieldAdapter());
            $this->registerAdapter(new DropdownFieldAdapter());
            $this->registerAdapter(new CheckboxesFieldAdapter());
            $this->registerAdapter(new MultiSelectFieldAdapter());
            $this->registerAdapter(new RadioButtonsFieldAdapter());
            $this->registerAdapter(new ColorFieldAdapter());
            $this->registerAdapter(new CountryFieldAdapter());
            $this->registerAdapter(new ButtonGroupFieldAdapter());
            $this->registerAdapter(new EmailFieldAdapter());
            $this->registerAdapter(new AddressesFieldAdapter());
            $this->registerAdapter(new CategoriesFieldAdapter());
            $this->registerAdapter(new TagsFieldAdapter());
            $this->registerAdapter(new EntriesFieldAdapter());
            $this->registerAdapter(new SeomaticFieldAdapter());
            $this->registerAdapter(new CkeditorFieldAdapter());
        }
    }

    public function registerAdapter(FieldAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getKey()] = $adapter;
    }

    /**
     * @return FieldAdapterInterface[]
     */
    public function getAdapters(): array
    {
        return $this->adapters;
    }

    public function getAdapterForField(FieldInterface $field): ?FieldAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($field)) {
                return $adapter;
            }
        }

        return null;
    }

    public function isFieldSupported(FieldInterface $field): bool
    {
        return $this->getAdapterForField($field) !== null;
    }
}
