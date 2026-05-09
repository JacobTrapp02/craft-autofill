<?php

declare(strict_types=1);

namespace jtdev\craftautofill\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\models\EntryType;
use jtdev\craftautofill\AutofillPlugin;

class AutofillField extends Field
{
    public string $entryTypeUid = '';
    public string $modelConfigUid = '';
    public string $globalPrompt = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $contextRows = [];

    public static function displayName(): string
    {
        return Craft::t('autofill', 'Autofill');
    }

    public static function icon(): string
    {
        return 'wand-magic-sparkles';
    }

    public static function phpType(): string
    {
        return 'array|null';
    }

    public static function dbType(): array|string|null
    {
        return null;
    }

    public static function isRequirable(): bool
    {
        return false;
    }

    public function init(): void
    {
        parent::init();
        $this->globalPrompt = trim($this->globalPrompt);
        $this->rows = $this->normalizeRows($this->rows);
        $this->contextRows = $this->normalizeContextRows($this->contextRows);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['entryTypeUid', 'modelConfigUid', 'globalPrompt'], 'string'];
        $rules[] = ['globalPrompt', 'filter', 'filter' => 'trim'];
        $rules[] = ['rows', 'validateRows'];
        $rules[] = ['contextRows', 'validateContextRows'];
        $rules[] = [['rows', 'contextRows'], 'validateUniqueFieldSelections'];
        $rules[] = ['entryTypeUid', 'validateEntryTypeUid'];
        $rules[] = ['modelConfigUid', 'validateModelConfigUid'];

        return $rules;
    }

    public function getSettingsHtml(): ?string
    {
        $selectedEntryType = $this->getSelectedEntryType();

        return Craft::$app->getView()->renderTemplate('autofill/fields/autofill/settings.twig', [
            'field' => $this,
            'entryTypeOptions' => $this->getEntryTypeOptions(),
            'modelConfigOptions' => $this->getModelConfigOptions(),
            'supportedFields' => $selectedEntryType ? AutofillPlugin::getInstance()->fieldDiscoveryService->getSupportedEntryTypeFields($selectedEntryType) : [],
            'rows' => $this->rows,
            'contextRows' => $this->contextRows,
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return null;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $fieldContractsByUid = [];

        if ($element?->getFieldLayout()) {
            $adapterService = AutofillPlugin::getInstance()->fieldAdapterService;
            foreach ($element->getFieldLayout()->getCustomFields() as $layoutField) {
                $uid = (string)($layoutField->uid ?? '');
                if ($uid === '') {
                    continue;
                }

                $adapter = $adapterService->getAdapterForField($layoutField);
                if ($adapter === null) {
                    continue;
                }

                $fieldContractsByUid[$uid] = $adapter->buildPromptContract($layoutField);
            }
        }

        return Craft::$app->getView()->renderTemplate('autofill/fields/autofill/input.twig', [
            'field' => $this,
            'element' => $element,
            'fieldContractsByUid' => $fieldContractsByUid,
        ]);
    }

    public function validateRows(): void
    {
        $this->rows = $this->normalizeRows($this->rows);

        foreach ($this->rows as $index => $row) {
            $rowNumber = $index + 1;

            if ((string)($row['targetFieldUid'] ?? '') === '') {
                $this->addError('rows', sprintf('Row %d must select a target field.', $rowNumber));
            }

            if ((string)($row['prompt'] ?? '') === '') {
                $this->addError('rows', sprintf('Row %d must include a prompt.', $rowNumber));
            }
        }
    }

    public function validateContextRows(): void
    {
        $this->contextRows = $this->normalizeContextRows($this->contextRows);

        foreach ($this->contextRows as $index => $row) {
            $rowNumber = $index + 1;
            if (($row['fieldUid'] ?? '') === '') {
                $this->addError('contextRows', sprintf('Context row %d must select a field.', $rowNumber));
            }
        }
    }

    public function validateUniqueFieldSelections(): void
    {
        $this->rows = $this->normalizeRows($this->rows);
        $this->contextRows = $this->normalizeContextRows($this->contextRows);

        $seen = [];

        foreach ($this->rows as $index => $row) {
            $uid = (string)($row['targetFieldUid'] ?? '');
            if ($uid === '') {
                continue;
            }

            if (isset($seen[$uid])) {
                $this->addError('rows', sprintf(
                    'Field selection conflict: row %d uses a field that is already selected in %s.',
                    $index + 1,
                    $seen[$uid]
                ));
                continue;
            }

            $seen[$uid] = sprintf('row %d', $index + 1);
        }

        foreach ($this->contextRows as $index => $row) {
            $uid = (string)($row['fieldUid'] ?? '');
            if ($uid === '') {
                continue;
            }

            if (isset($seen[$uid])) {
                $this->addError('contextRows', sprintf(
                    'Field selection conflict: context row %d uses a field that is already selected in %s.',
                    $index + 1,
                    $seen[$uid]
                ));
                continue;
            }

            $seen[$uid] = sprintf('context row %d', $index + 1);
        }
    }

    public function validateEntryTypeUid(): void
    {
        if ($this->entryTypeUid === '') {
            return;
        }

        if ($this->getSelectedEntryType() === null) {
            $this->addError('entryTypeUid', 'Selected entry type no longer exists.');
        }
    }

    public function validateModelConfigUid(): void
    {
        if ($this->modelConfigUid === '') {
            return;
        }

        foreach (AutofillPlugin::getInstance()->getSettings()->modelConfigs as $config) {
            if (($config['uid'] ?? null) !== $this->modelConfigUid) {
                continue;
            }

            if (!($config['enabled'] ?? false)) {
                $this->addError('modelConfigUid', 'Selected model config is disabled.');
            }

            return;
        }

        $this->addError('modelConfigUid', 'Selected model config no longer exists.');
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $targetFieldUid = trim((string)($row['targetFieldUid'] ?? ''));
            $prompt = trim((string)($row['prompt'] ?? ''));
            $requiresApproval = $this->toBool($row['requiresApproval'] ?? true);
            $enabled = $this->toBool($row['enabled'] ?? true);

            $hasMeaningfulInput = $targetFieldUid !== '' || $prompt !== '';
            if (!$hasMeaningfulInput) {
                continue;
            }

            $normalized[] = [
                'targetFieldUid' => $targetFieldUid,
                'prompt' => $prompt,
                'requiresApproval' => $requiresApproval,
                'enabled' => $enabled,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $rows
     * @return array<int, array{fieldUid:string, prompt:string, enabled:bool}>
     */
    private function normalizeContextRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fieldUid = trim((string)($row['fieldUid'] ?? ''));
            $prompt = trim((string)($row['prompt'] ?? ''));
            $enabled = $this->toBool($row['enabled'] ?? true);

            if ($fieldUid === '' && $prompt === '') {
                continue;
            }

            $normalized[] = [
                'fieldUid' => $fieldUid,
                'prompt' => $prompt,
                'enabled' => $enabled,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, array{label:string, value:string}>
     */
    private function getEntryTypeOptions(): array
    {
        $options = [
            ['label' => 'Select an entry type', 'value' => ''],
        ];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach (Craft::$app->getEntries()->getEntryTypesBySectionId((int)$section->id) as $entryType) {
                $options[] = [
                    'label' => sprintf('%s -> %s', $section->name, $entryType->name),
                    'value' => (string)$entryType->uid,
                ];
            }
        }

        return $options;
    }

    /**
     * @return array<int, array{label:string, value:string}>
     */
    private function getModelConfigOptions(): array
    {
        $options = [
            ['label' => 'Use global default (first enabled model config)', 'value' => ''],
        ];

        foreach (AutofillPlugin::getInstance()->getSettings()->modelConfigs as $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            $uid = (string)($config['uid'] ?? '');
            if ($uid === '') {
                continue;
            }

            $label = trim((string)($config['label'] ?? ''));
            $options[] = [
                'label' => $label !== '' ? $label : $uid,
                'value' => $uid,
            ];
        }

        return $options;
    }

    private function getSelectedEntryType(): ?EntryType
    {
        if ($this->entryTypeUid === '') {
            return null;
        }

        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            if ((string)$entryType->uid === $this->entryTypeUid) {
                return $entryType;
            }
        }

        return null;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
