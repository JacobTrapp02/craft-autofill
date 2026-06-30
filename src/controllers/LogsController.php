<?php

declare(strict_types=1);

namespace jtdev\craftautofill\controllers;

use craft\web\Controller;
use jtdev\craftautofill\records\AiRequestLogRecord;
use yii\web\Response;

class LogsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-autofill');

        return $this->renderTemplate('autofill/cp/logs/index.twig', [
            'logsQuery' => AiRequestLogRecord::find()->orderBy(['dateCreated' => SORT_DESC]),
        ]);
    }
}
