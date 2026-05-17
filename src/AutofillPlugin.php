<?php

namespace jtdev\craftautofill;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use jtdev\craftautofill\fields\AutofillField;
use jtdev\craftautofill\models\Settings;
use jtdev\craftautofill\services\ai\AiRequestLogService;
use jtdev\craftautofill\services\ai\AiService;
use jtdev\craftautofill\services\entries\EntrySuggestionApplyService;
use jtdev\craftautofill\services\fields\FieldAdapterService;
use jtdev\craftautofill\services\fields\FieldDiscoveryService;
use yii\base\Event;

/**
 * Autofill plugin
 *
 * @method static AutofillPlugin getInstance()
 * @method Settings getSettings()
 * @method AiRequestLogService getAiRequestLogService()
 * @method AiService getAiService()
 * @method EntrySuggestionApplyService getEntrySuggestionApplyService()
 * @method FieldAdapterService getFieldAdapterService()
 * @method FieldDiscoveryService getFieldDiscoveryService()
 * @author JTDev <jake.trapp02@gmail.com>
 * @copyright JTDev
 * @license https://craftcms.github.io/license/ Craft License
 */
class AutofillPlugin extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'aiRequestLogService' => AiRequestLogService::class,
                'aiService' => AiService::class,
                'entrySuggestionApplyService' => EntrySuggestionApplyService::class,
                'fieldAdapterService' => FieldAdapterService::class,
                'fieldDiscoveryService' => FieldDiscoveryService::class,
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
        return Craft::$app->view->renderTemplate('autofill/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
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

    private function attachEventHandlers(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = AutofillField::class;
            }
        );
    }
}
