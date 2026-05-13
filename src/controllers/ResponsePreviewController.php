<?php

declare(strict_types=1);

namespace jtdev\craftautofill\controllers;

use Craft;
use craft\web\Controller;
use jtdev\craftautofill\AutofillPlugin;
use Throwable;
use yii\web\Response;

class ResponsePreviewController extends Controller
{
    public function actionNormalizeResponse(): Response
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
                    'suggestions' => [],
                ]);
            }

            $result = AutofillPlugin::getInstance()->getAiService()->normalizeAutofillResponse(
                (string)($body['rawResponse'] ?? ''),
                $fieldId
            );

            return $this->asJson([
                'success' => $result->success,
                'suggestions' => $result->suggestions,
                'error' => $result->error,
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'suggestions' => [],
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $exception->getMessage()
                    : 'Could not normalize response.',
            ]);
        }
    }

    public function actionApplySuggestion(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $body = Craft::$app->getRequest()->getBodyParams();
            $fieldId = (int)($body['fieldId'] ?? 0);
            $entryId = (int)($body['entryId'] ?? 0);
            $siteIdRaw = $body['siteId'] ?? null;
            $siteId = is_numeric($siteIdRaw) ? (int)$siteIdRaw : null;
            $suggestion = is_array($body['suggestion'] ?? null) ? $body['suggestion'] : [];

            if ($fieldId <= 0) {
                return $this->asJson([
                    'success' => false,
                    'error' => 'Autofill field ID was invalid.',
                ]);
            }

            $applied = AutofillPlugin::getInstance()
                ->getEntrySuggestionApplyService()
                ->applySuggestion($fieldId, $entryId, $siteId, $suggestion);

            return $this->asJson([
                'success' => true,
                'applied' => $applied,
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            return $this->asJson([
                'success' => false,
                'error' => Craft::$app->getConfig()->getGeneral()->devMode
                    ? $exception->getMessage()
                    : 'Could not apply suggestion.',
            ]);
        }
    }
}
