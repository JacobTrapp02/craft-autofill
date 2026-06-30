<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\ai;

use Craft;
use craft\base\Component;
use jtdev\craftautofill\models\AiRequestLog;
use jtdev\craftautofill\records\AiRequestLogRecord;

class AiRequestLogService extends Component
{
    public function begin(array $attributes): ?int
    {
        $record = $this->saveNewRecord($attributes);

        return $record?->id !== null ? (int)$record->id : null;
    }

    public function complete(?int $id, array $attributes): void
    {
        if ($id === null || $id <= 0) {
            $this->saveNewRecord($attributes);
            return;
        }

        $record = AiRequestLogRecord::findOne($id);
        if (!$record instanceof AiRequestLogRecord) {
            $this->saveNewRecord($attributes);
            return;
        }

        foreach ($this->filterKnownAttributes($attributes) as $key => $value) {
            $record->setAttribute($key, $value);
        }

        if (!$record->save()) {
            Craft::warning('AI request log record update failed.', __METHOD__);
        }
    }

    public function hasSuccessfulEntryRun(int $fieldId, int $entryId, ?int $siteId = null): bool
    {
        if ($fieldId <= 0 || $entryId <= 0) {
            return false;
        }

        $query = AiRequestLogRecord::find()
            ->where([
                'fieldId' => $fieldId,
                'entryId' => $entryId,
                'success' => true,
            ]);

        if ($siteId !== null && $siteId > 0) {
            $query->andWhere(['siteId' => $siteId]);
        }

        return $query->exists();
    }

    private function saveNewRecord(array $attributes): ?AiRequestLogRecord
    {
        $model = new AiRequestLog($this->filterKnownAttributes($attributes));
        if (!$model->validate()) {
            Craft::warning('AI request log validation failed while creating record.', __METHOD__);
            return null;
        }

        $record = new AiRequestLogRecord();
        foreach ($model->getAttributes() as $key => $value) {
            $record->setAttribute($key, $value);
        }

        if (!$record->save()) {
            Craft::warning('AI request log record insert failed.', __METHOD__);
            return null;
        }

        return $record;
    }

    private function filterKnownAttributes(array $attributes): array
    {
        $allowed = [
            'fieldId',
            'entryId',
            'siteId',
            'userId',
            'provider',
            'modelConfigUid',
            'modelId',
            'reasoningEffort',
            'requestPrompt',
            'requestPayloadJson',
            'responseRawText',
            'responsePayloadJson',
            'success',
            'errorMessage',
            'latencyMs',
            'inputTokens',
            'outputTokens',
            'totalTokens',
            'providerResponseId',
        ];

        return array_intersect_key($attributes, array_flip($allowed));
    }
}
