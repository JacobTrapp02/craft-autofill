<?php

namespace jtdev\craftautofill;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use jtdev\craftautofill\models\Settings;
use jtdev\craftautofill\services\ai\AiService;

/**
 * Autofill plugin
 *
 * @method static AutofillPlugin getInstance()
 * @method Settings getSettings()
 * @method AiService getAiService()
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
                'aiService' => AiService::class,
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

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
    }
}
