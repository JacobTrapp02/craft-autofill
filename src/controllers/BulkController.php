<?php

declare(strict_types=1);

namespace jtdev\craftautofill\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\web\Controller;
use jtdev\craftautofill\AutofillPlugin;
use jtdev\craftautofill\fields\AutofillField;
use Throwable;
use yii\web\Response;

class BulkController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-autofill');

        return $this->renderTemplate('autofill/cp/bulk/index.twig', [
            'autofillFieldOptions' => $this->autofillFieldOptions(),
            'entrySources' => $this->entrySources(),
            'siteOptions' => $this->siteOptions(),
            'selectedEntries' => [],
            'selectedFieldId' => null,
            'selectedSiteId' => null,
            'userPrompt' => '',
        ]);
    }

    public function actionQueue(): ?Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('accessPlugin-autofill');

        $request = Craft::$app->getRequest();
        $entryIds = $this->normalizeEntryIds($request->getBodyParam('entryIds', []));
        $fieldId = (int)$request->getBodyParam('fieldId', 0);
        $siteId = $this->normalizeSiteId($request->getBodyParam('siteId'));
        $userPrompt = trim((string)$request->getBodyParam('userPrompt', ''));

        if ($fieldId <= 0 || $entryIds === []) {
            Craft::$app->getSession()->setError(Craft::t('autofill', 'Select an Autofill field and at least one entry.'));

            return $this->renderTemplate('autofill/cp/bulk/index.twig', [
                'autofillFieldOptions' => $this->autofillFieldOptions(),
                'entrySources' => $this->entrySources(),
                'siteOptions' => $this->siteOptions(),
                'selectedEntries' => $this->selectedEntries($entryIds, $siteId),
                'selectedFieldId' => $fieldId,
                'selectedSiteId' => $siteId,
                'userPrompt' => $userPrompt,
            ]);
        }

        try {
            $result = AutofillPlugin::getInstance()->getBulkAutofillService()->queue(
                $fieldId,
                null,
                $entryIds,
                $siteId,
                $userPrompt,
                'cp-bulk',
            );

            if ($result['failed'] > 0) {
                Craft::$app->getSession()->setError(Craft::t(
                    'autofill',
                    'Queued {queued} Autofill jobs. {failed} could not be queued.',
                    ['queued' => $result['queued'], 'failed' => $result['failed']]
                ));
            } else {
                Craft::$app->getSession()->setNotice(Craft::t(
                    'autofill',
                    'Queued {queued} Autofill jobs.',
                    ['queued' => $result['queued']]
                ));
            }

            return $this->redirect(UrlHelper::cpUrl('autofill/bulk'));
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError(Craft::$app->getConfig()->getGeneral()->devMode
                ? $exception->getMessage()
                : Craft::t('autofill', 'Could not queue Autofill jobs.'));

            return $this->renderTemplate('autofill/cp/bulk/index.twig', [
                'autofillFieldOptions' => $this->autofillFieldOptions(),
                'entrySources' => $this->entrySources(),
                'siteOptions' => $this->siteOptions(),
                'selectedEntries' => $this->selectedEntries($entryIds, $siteId),
                'selectedFieldId' => $fieldId,
                'selectedSiteId' => $siteId,
                'userPrompt' => $userPrompt,
            ]);
        }
    }

    /**
     * @return array<int, array{label:string,value:int}>
     */
    private function autofillFieldOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if (!$field instanceof AutofillField) {
                continue;
            }

            $options[] = [
                'label' => sprintf('%s (%s)', $field->name, $field->handle),
                'value' => (int)$field->id,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{label:string,value:string}>
     */
    private function siteOptions(): array
    {
        $options = [
            [
                'label' => Craft::t('autofill', 'Default entry site'),
                'value' => '',
            ],
        ];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $options[] = [
                'label' => $site->name,
                'value' => (string)$site->id,
            ];
        }

        return $options;
    }

    /**
     * @return string[]
     */
    private function entrySources(): array
    {
        $sections = Craft::$app->getEntries()->getEditableSections();
        if ($sections === []) {
            return [];
        }

        $sources = ['*'];
        foreach ($sections as $section) {
            if ($section->type === Section::TYPE_SINGLE) {
                $sources[] = 'singles';
                break;
            }
        }

        foreach ($sections as $section) {
            $sources[] = 'section:' . $section->uid;
        }

        return $sources;
    }

    /**
     * @param mixed $entryIds
     * @return int[]
     */
    private function normalizeEntryIds(mixed $entryIds): array
    {
        if (!is_array($entryIds)) {
            $entryIds = [$entryIds];
        }

        $ids = [];
        foreach ($entryIds as $entryId) {
            $id = (int)$entryId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function normalizeSiteId(mixed $siteId): ?int
    {
        $siteId = is_numeric($siteId) ? (int)$siteId : 0;

        return $siteId > 0 ? $siteId : null;
    }

    /**
     * @param int[] $entryIds
     * @return Entry[]
     */
    private function selectedEntries(array $entryIds, ?int $siteId): array
    {
        if ($entryIds === []) {
            return [];
        }

        return Entry::find()
            ->id($entryIds)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->revisions(null)
            ->fixedOrder()
            ->all();
    }
}
