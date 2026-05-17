<?php

declare(strict_types=1);

namespace jtdev\craftautofill\migrations;

use craft\db\Migration;
use jtdev\craftautofill\records\AiRequestLogRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $tableName = AiRequestLogRecord::tableName();

        if (!$this->db->tableExists($tableName)) {
            $this->createTable($tableName, [
                'id' => $this->primaryKey(),
                'fieldId' => $this->integer(),
                'entryId' => $this->integer(),
                'siteId' => $this->integer(),
                'userId' => $this->integer(),
                'provider' => $this->string(40)->notNull(),
                'modelConfigUid' => $this->string(36),
                'modelId' => $this->string(255),
                'reasoningEffort' => $this->string(20),
                'requestPrompt' => $this->text()->notNull(),
                'requestPayloadJson' => $this->text(),
                'responseRawText' => $this->text(),
                'responsePayloadJson' => $this->text(),
                'success' => $this->boolean()->notNull()->defaultValue(false),
                'errorMessage' => $this->text(),
                'latencyMs' => $this->integer(),
                'inputTokens' => $this->integer(),
                'outputTokens' => $this->integer(),
                'totalTokens' => $this->integer(),
                'providerResponseId' => $this->string(255),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $tableName, ['fieldId']);
            $this->createIndex(null, $tableName, ['entryId']);
            $this->createIndex(null, $tableName, ['siteId']);
            $this->createIndex(null, $tableName, ['userId']);
            $this->createIndex(null, $tableName, ['provider']);
            $this->createIndex(null, $tableName, ['modelConfigUid']);
            $this->createIndex(null, $tableName, ['success']);
            $this->createIndex(null, $tableName, ['dateCreated']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
