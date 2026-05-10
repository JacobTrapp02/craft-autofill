<?php

declare(strict_types=1);

namespace jtdev\craftautofill\controllers;

use Craft;
use craft\web\Controller;
use jtdev\craftautofill\AutofillPlugin;
use Throwable;
use yii\web\Response;

class PromptPreviewController extends Controller
{
    public function actionBuildPrompt(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $body = Craft::$app->getRequest()->getBodyParams();
            $fieldId = (int)($body['fieldId'] ?? 0);

            if ($fieldId <= 0) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Autofill field ID was invalid.',
                ]);
            }

            $prompt = AutofillPlugin::getInstance()->getAiService()->buildAutofillPromptPreview(
                (string)($body['userPrompt'] ?? ''),
                $fieldId
            );

            return $this->asJson([
                'success' => true,
                'prompt' => $prompt,
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $exception->getMessage()
                    : 'Could not build prompt preview.',
            ]);
        }
    }
}
