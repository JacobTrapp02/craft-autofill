<?php

declare(strict_types=1);

namespace jtdev\craftautofill\records;

use craft\db\ActiveRecord;

class AiRequestLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%autofill_ai_request_logs}}';
    }
}
