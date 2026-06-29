<?php

namespace jtdev\craftautofill;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\EntryTypeEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\models\EntryType;
use craft\services\Entries;
use craft\services\Fields;
use jtdev\craftautofill\fields\AutofillField;
use jtdev\craftautofill\models\Settings;
use jtdev\craftautofill\services\ai\AiRequestLogService;
use jtdev\craftautofill\services\ai\AiService;
use jtdev\craftautofill\services\entries\BulkAutofillService;
use jtdev\craftautofill\services\entries\EntrySuggestionApplyService;
use jtdev\craftautofill\services\fields\FieldAdapterService;
use jtdev\craftautofill\services\fields\FieldDiscoveryService;
use jtdev\craftautofill\services\fields\EntryTypeFieldResolverService;
use RuntimeException;
use yii\base\Event;
use yii\base\ModelEvent;

/**
 * Autofill plugin
 *
 * @method static AutofillPlugin getInstance()
 * @method Settings getSettings()
 * @method AiRequestLogService getAiRequestLogService()
 * @method AiService getAiService()
 * @method BulkAutofillService getBulkAutofillService()
 * @method EntrySuggestionApplyService getEntrySuggestionApplyService()
 * @method FieldAdapterService getFieldAdapterService()
 * @method FieldDiscoveryService getFieldDiscoveryService()
 * @method EntryTypeFieldResolverService getEntryTypeFieldResolverService()
 * @author JTDev <jake.trapp02@gmail.com>
 * @copyright JTDev
 * @license https://craftcms.github.io/license/ Craft License
 */
class AutofillPlugin extends Plugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'aiRequestLogService' => AiRequestLogService::class,
                'aiService' => AiService::class,
                'bulkAutofillService' => BulkAutofillService::class,
                'entrySuggestionApplyService' => EntrySuggestionApplyService::class,
                'fieldAdapterService' => FieldAdapterService::class,
                'fieldDiscoveryService' => FieldDiscoveryService::class,
                'entryTypeFieldResolverService' => EntryTypeFieldResolverService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            // ...
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        $this->hydrateServerEnvKeysForAutosuggest();
        $settings = $this->getSettings();

        return Craft::$app->view->renderTemplate('autofill/_settings.twig', [
            'plugin' => $this,
            'settings' => $settings,
        ]);
    }

    public function getAiService(): AiService
    {
        return $this->get('aiService');
    }

    public function getAiRequestLogService(): AiRequestLogService
    {
        return $this->get('aiRequestLogService');
    }

    public function getBulkAutofillService(): BulkAutofillService
    {
        return $this->get('bulkAutofillService');
    }

    public function getFieldAdapterService(): FieldAdapterService
    {
        return $this->get('fieldAdapterService');
    }

    public function getEntrySuggestionApplyService(): EntrySuggestionApplyService
    {
        return $this->get('entrySuggestionApplyService');
    }

    public function getFieldDiscoveryService(): FieldDiscoveryService
    {
        return $this->get('fieldDiscoveryService');
    }

    public function getEntryTypeFieldResolverService(): EntryTypeFieldResolverService
    {
        return $this->get('entryTypeFieldResolverService');
    }

    public function isProEdition(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * @return array<int, array{label:string,data:array<int, array{name:string,hint:string}>}>
     */
    public function getEnvSuggestionsForAutosuggest(): array
    {
        $this->hydrateServerEnvKeysForAutosuggest();

        $security = Craft::$app->getSecurity();
        $suggestions = [];

        foreach (array_keys($_SERVER) as $key) {
            if (!is_string($key) || $key === '' || str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $value = App::env($key);
            if (!is_scalar($value)) {
                continue;
            }

            $suggestions[$key] = [
                'name' => '$' . $key,
                'hint' => $security->redactIfSensitive($key, Craft::getAlias((string)$value, false)),
            ];
        }

        ksort($suggestions, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            [
                'label' => Craft::t('app', 'Environment Variables'),
                'data' => array_values($suggestions),
            ],
        ];
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = AutofillField::class;
            }
        );

        Event::on(
            EntryType::class,
            EntryType::EVENT_BEFORE_VALIDATE,
            static function(ModelEvent $event): void {
                $entryType = $event->sender;
                if (!$entryType instanceof EntryType) {
                    return;
                }

                self::addAutofillEntryTypeCompatibilityErrors($entryType);
            }
        );

        Event::on(
            Entries::class,
            Entries::EVENT_BEFORE_SAVE_ENTRY_TYPE,
            static function(EntryTypeEvent $event): void {
                $messages = self::addAutofillEntryTypeCompatibilityErrors($event->entryType);
                if ($messages !== []) {
                    throw new RuntimeException(implode(' ', $messages));
                }
            }
        );
    }

    /**
     * @return string[]
     */
    private static function addAutofillEntryTypeCompatibilityErrors(EntryType $entryType): array
    {
        $expectedEntryTypeUid = trim((string)($entryType->uid ?? ''));
        if ($expectedEntryTypeUid === '') {
            return [];
        }

        $fieldLayout = $entryType->getFieldLayout();
        $messages = [];
        foreach ($fieldLayout->getCustomFields() as $field) {
            if (!$field instanceof AutofillField) {
                continue;
            }

            $configuredEntryTypeUid = trim($field->entryTypeUid);
            if ($configuredEntryTypeUid === $expectedEntryTypeUid) {
                continue;
            }

            $fieldLabel = trim((string)($field->name ?? ''));
            $message = $fieldLabel !== ''
                ? sprintf(
                    'Autofill field "%s" is configured for a different entry type and cannot be added here.',
                    $fieldLabel
                )
                : 'An Autofill field is configured for a different entry type and cannot be added here.';

            $messages[] = $message;

            if (!in_array($message, $entryType->getErrors('fieldLayout'), true)) {
                $entryType->addError('fieldLayout', $message);
            }

            if (!in_array($message, $fieldLayout->getErrors('customFields'), true)) {
                $fieldLayout->addError('customFields', $message);
            }
        }

        return $messages;
    }

    /**
     * Craft's env autosuggest sources variables from $_SERVER keys.
     * Mirror keys from other env sources so plugin settings autosuggest is reliable.
     */
    private function hydrateServerEnvKeysForAutosuggest(): void
    {
        $candidates = [];

        if (is_array($_ENV)) {
            foreach ($_ENV as $key => $value) {
                if (!is_string($key) || $key === '' || !is_scalar($value)) {
                    continue;
                }
                $candidates[$key] = (string)$value;
            }
        }

        $all = getenv();
        if (is_array($all)) {
            foreach ($all as $key => $value) {
                if (!is_string($key) || $key === '' || !is_scalar($value)) {
                    continue;
                }
                $candidates[$key] = (string)$value;
            }
        }

        foreach ($candidates as $key => $value) {
            if (!array_key_exists($key, $_SERVER)) {
                $_SERVER[$key] = $value;
            }
        }
    }
}
