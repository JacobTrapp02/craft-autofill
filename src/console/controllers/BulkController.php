<?php

declare(strict_types=1);

namespace jtdev\craftautofill\console\controllers;

use craft\console\Controller;
use jtdev\craftautofill\AutofillPlugin;
use Throwable;
use yii\console\ExitCode;

class BulkController extends Controller
{
    public ?int $fieldId = null;
    public ?string $fieldSlug = null;
    public string $entryIds = '';
    public ?int $siteId = null;
    public string $userPrompt = '';
    public bool $runNow = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'fieldId',
            'fieldSlug',
            'entryIds',
            'siteId',
            'userPrompt',
            'runNow',
        ]);
    }

    /**
     * Bulk-runs Autofill for one Autofill field across one or more entries.
     */
    public function actionRun(): int
    {
        $plugin = AutofillPlugin::getInstance();
        if (!$plugin->isProEdition()) {
            $this->stderr("Bulk Autofill is only available in Autofill Pro.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            if ($this->runNow) {
                $result = $plugin->getBulkAutofillService()->run(
                    $this->fieldId,
                    $this->fieldSlug,
                    $this->entryIds,
                    $this->siteId,
                    $this->userPrompt,
                    'console-bulk',
                    function(int $fieldId, int $total): void {
                        $this->stdout(sprintf(
                            "Running Autofill field %d for %d entr%s.\n",
                            $fieldId,
                            $total,
                            $total === 1 ? 'y' : 'ies'
                        ));
                    },
                    function(array $entryResult): void {
                        if ($entryResult['success']) {
                            $this->stdout(sprintf(
                                "[OK] Entry %d: applied %d suggestion%s.\n",
                                $entryResult['entryId'],
                                $entryResult['suggestionsApplied'],
                                $entryResult['suggestionsApplied'] === 1 ? '' : 's'
                            ));
                            return;
                        }

                        $this->stderr(sprintf("[FAIL] Entry %d: %s\n", $entryResult['entryId'], $entryResult['error']));
                    }
                );

                $this->stdout("\nBulk Autofill complete.\n");
                $this->stdout(sprintf("Entries requested: %d\n", $result['total']));
                $this->stdout(sprintf("Entries succeeded: %d\n", $result['succeeded']));
                $this->stdout(sprintf("Entries failed: %d\n", $result['failed']));
                $this->stdout(sprintf("Suggestions applied: %d\n", $result['suggestionsApplied']));

                return $result['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
            }

            $result = $plugin->getBulkAutofillService()->queue(
                $this->fieldId,
                $this->fieldSlug,
                $this->entryIds,
                $this->siteId,
                $this->userPrompt,
                'console-bulk-queue',
                function(int $fieldId, int $total): void {
                    $this->stdout(sprintf(
                        "Queueing Autofill field %d for %d entr%s.\n",
                        $fieldId,
                        $total,
                        $total === 1 ? 'y' : 'ies'
                    ));
                },
                function(array $entryResult): void {
                    if ($entryResult['queued']) {
                        $this->stdout(sprintf(
                            "[QUEUED] Entry %d: job %s.\n",
                            $entryResult['entryId'],
                            (string)$entryResult['jobId']
                        ));
                        return;
                    }

                    $this->stderr(sprintf("[FAIL] Entry %d: %s\n", $entryResult['entryId'], $entryResult['error']));
                }
            );
        } catch (Throwable $exception) {
            $this->stderr($exception->getMessage() . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("\nBulk Autofill queued.\n");
        $this->stdout(sprintf("Entries requested: %d\n", $result['total']));
        $this->stdout(sprintf("Entries queued: %d\n", $result['queued']));
        $this->stdout(sprintf("Entries failed to queue: %d\n", $result['failed']));

        return $result['failed'] > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
