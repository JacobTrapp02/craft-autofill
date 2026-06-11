<?php

declare(strict_types=1);

namespace jtdev\craftautofill\controllers;

use Craft;
use craft\web\Controller;
use jtdev\craftautofill\AutofillPlugin;
use Throwable;
use yii\web\Response;

class FieldSettingsController extends Controller
{
    public function actionSupportedFields(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $entryTypeUid = trim((string)Craft::$app->getRequest()->getBodyParam('entryTypeUid', ''));
            if ($entryTypeUid === '') {
                return $this->asJson([
                    'success' => true,
                    'fields' => [],
                ]);
            }

            $entryType = Craft::$app->getEntries()->getEntryTypeByUid($entryTypeUid);
            if ($entryType === null) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Selected entry type could not be found.',
                    'fields' => [],
                ]);
            }

            return $this->asJson([
                'success' => true,
                'fields' => AutofillPlugin::getInstance()
                    ->getFieldDiscoveryService()
                    ->getSupportedEntryTypeFields($entryType),
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $exception->getMessage()
                    : 'Could not load fields for the selected entry type.',
                'fields' => [],
            ]);
        }
    }
}
