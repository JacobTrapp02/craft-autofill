<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset as AssetElement;
use craft\elements\Category as CategoryElement;
use craft\elements\Entry as EntryElement;
use craft\elements\Entry;
use craft\fields\Link as LinkField;
use craft\fields\data\LinkData;
use craft\fields\linktypes\Asset as AssetLinkType;
use craft\fields\linktypes\BaseElementLinkType;
use craft\fields\linktypes\BaseLinkType;
use craft\fields\linktypes\Category as CategoryLinkType;
use craft\fields\linktypes\Entry as EntryLinkType;
use craft\helpers\Json;
use craft\validators\StringValidator;
use RuntimeException;
use yii\base\InvalidArgumentException;

class LinkFieldAdapter implements FieldAdapterInterface
{
    private const ELEMENT_TYPE_ENTRY = 'entry';
    private const ELEMENT_TYPE_CATEGORY = 'category';
    private const ELEMENT_TYPE_ASSET = 'asset';
    private const MODE_TOP_N = 'topN';
    private const MODE_MOST_RECENT = 'mostRecent';
    private const MODE_ALL = 'all';
    private const MODE_NONE = 'none';
    private const ALL_MODE_CANDIDATE_CAP = 500;

    public function getKey(): string
    {
        return 'link';
    }

    public function isAvailableInLiteVersion(): bool
    {
        return false;
    }

    public function supports(FieldInterface $field): bool
    {
        return $field instanceof LinkField;
    }

    public function getPromptConfigSchema(FieldInterface $field): array
    {
        return [
            'fields' => [
                [
                    'key' => 'prompt',
                    'type' => 'multiline',
                    'label' => 'Prompt',
                    'required' => true,
                ],
            ],
        ];
    }

    public function normalizePromptConfig(array $config, FieldInterface $field): array
    {
        $linkConfig = is_array($config['link'] ?? null) ? $config['link'] : [];

        return [
            'prompt' => trim((string)($config['prompt'] ?? '')),
            'link' => [
                self::ELEMENT_TYPE_ENTRY => $this->normalizeElementContextConfig($linkConfig[self::ELEMENT_TYPE_ENTRY] ?? []),
                self::ELEMENT_TYPE_CATEGORY => $this->normalizeElementContextConfig($linkConfig[self::ELEMENT_TYPE_CATEGORY] ?? []),
                self::ELEMENT_TYPE_ASSET => $this->normalizeElementContextConfig($linkConfig[self::ELEMENT_TYPE_ASSET] ?? []),
            ],
        ];
    }

    public function validatePromptConfig(array $config, FieldInterface $field): array
    {
        $normalized = $this->normalizePromptConfig($config, $field);
        $errors = [];

        if ($normalized['prompt'] === '') {
            $errors[] = 'Prompt is required for Link fields.';
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        if (!$field instanceof LinkField) {
            return ['type' => 'object'];
        }

        $config = $this->normalizePromptConfig($promptConfig, $field);
        $currentEntryId = $this->currentEntryIdFromPromptConfig($promptConfig);
        $linkTypes = $field->getLinkTypes();
        $allowedTypes = array_keys($linkTypes);
        $typeLabels = [];
        $candidatesByType = [];
        $rules = [
            'Return JSON only.',
            'Return exactly one link suggestion object.',
        ];

        foreach ($linkTypes as $typeId => $linkType) {
            $typeLabels[$typeId] = $linkType::displayName();

            if ($this->supportsCandidateContext($linkType)) {
                $typeConfig = $config['link'][$typeId] ?? $this->defaultElementContextConfig();
                $mode = $typeConfig['mode'];
                $count = $typeConfig['count'];
                $candidates = $this->candidateRecordsForLinkType($field, $typeId, $linkType, $mode, $count, $currentEntryId);
                $candidatesByType[$typeId] = $candidates;

                if ($mode === self::MODE_NONE) {
                    $rules[] = sprintf('Do not choose the %s link type unless the editor will set it manually later.', $linkType::displayName());
                } elseif ($candidates !== []) {
                    $rules[] = sprintf('If you choose the %s link type, return the exact candidateKey from %sCandidates.', $linkType::displayName(), $typeId);
                }

                continue;
            }

            $rules = array_merge($rules, $this->textTypeRulesForLinkType($typeId, $linkType));
        }

        if (count($allowedTypes) === 1) {
            $rules[] = sprintf('The type must be %s.', $allowedTypes[0]);
        } else {
            $rules[] = sprintf('The type must be one of: %s.', implode(', ', $allowedTypes));
        }

        $rules[] = 'For text-style links, return the destination in the value field.';
        $rules[] = 'Do not wrap link values in markdown, brackets, quotes, or prose.';
        $rules[] = 'For entry, category, and asset links, return the exact candidateKey and do not invent IDs.';

        if ($field->showLabelField) {
            $rules[] = 'You may include label when a custom link label is needed.';
        } else {
            $rules[] = 'Do not include label unless the field explicitly supports it.';
        }

        if (in_array('urlSuffix', $field->advancedFields, true)) {
            $rules[] = 'You may include urlSuffix for query params or anchor fragments when needed.';
        }

        if (in_array('target', $field->advancedFields, true)) {
            $rules[] = 'You may include openInNewTab as true when the link should open in a new tab.';
        }

        $properties = [
            'type' => [
                'type' => 'string',
            ],
            'value' => [
                'type' => 'string',
            ],
            'candidateKey' => [
                'type' => 'string',
            ],
        ];

        if ($field->showLabelField) {
            $properties['label'] = ['type' => 'string'];
        }

        if (in_array('urlSuffix', $field->advancedFields, true)) {
            $properties['urlSuffix'] = ['type' => 'string'];
        }

        if (in_array('target', $field->advancedFields, true)) {
            $properties['openInNewTab'] = ['type' => 'boolean'];
        }

        $contract = [
            'type' => 'object',
            'format' => 'link',
            'rules' => $rules,
            'properties' => $properties,
            'allowedTypes' => $allowedTypes,
            'typeLabels' => $typeLabels,
            'showLabelField' => (bool)$field->showLabelField,
            'supportsUrlSuffix' => in_array('urlSuffix', $field->advancedFields, true),
            'supportsTarget' => in_array('target', $field->advancedFields, true),
        ];

        foreach ($candidatesByType as $typeId => $candidates) {
            $contract[sprintf('%sCandidates', $typeId)] = $candidates;
        }

        return $contract;
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        if ($value instanceof LinkData) {
            $serialized = $value->serialize();
            $serialized['url'] = $value->getUrl();
            return json_encode($serialized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        return '';
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        if (!$field instanceof LinkField) {
            return false;
        }

        [$payload, $normalized] = $this->payloadFromSuggestion($field, $value);
        if ($payload === null || $normalized === []) {
            return false;
        }

        $typeId = (string)($normalized['type'] ?? '');
        $linkTypes = $field->getLinkTypes();
        $linkType = $linkTypes[$typeId] ?? null;
        if (!$linkType instanceof BaseLinkType) {
            return false;
        }

        try {
            $linkData = $field->normalizeValue($payload, null);
        } catch (\Throwable) {
            return false;
        }

        if (!$linkData instanceof LinkData) {
            return false;
        }

        $serialized = $linkData->serialize();
        $normalizedValue = trim((string)($serialized['value'] ?? ''));
        if ($normalizedValue === '') {
            return false;
        }

        $error = null;
        if (!$linkType->validateValue($normalizedValue, $error)) {
            return false;
        }

        if ($linkType instanceof BaseElementLinkType && !$this->elementExistsForLinkType($linkType, $normalizedValue)) {
            return false;
        }

        $stringValidator = new StringValidator(['max' => $field->maxLength]);
        return $stringValidator->validate($normalizedValue);
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        if (!$field instanceof LinkField) {
            return [];
        }

        [, $normalized] = $this->payloadFromSuggestion($field, $value);
        return $normalized;
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        if (!$field instanceof LinkField) {
            throw new RuntimeException('Link suggestion adapter received an unsupported field.');
        }

        [$payload, $normalized] = $this->payloadFromSuggestion($field, $value);
        if ($payload === null || $normalized === []) {
            throw new RuntimeException('Link suggestion must include a valid type and destination.');
        }

        if (!$this->validateSuggestion($field, $normalized)) {
            throw new RuntimeException('Link suggestion must match the field type and destination constraints.');
        }

        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new RuntimeException('Could not resolve field handle for link suggestion apply.');
        }

        $linkData = $field->normalizeValue($payload, $entry);
        if (!$linkData instanceof LinkData) {
            throw new RuntimeException('Link suggestion could not be normalized.');
        }

        $entry->setFieldValue($handle, $linkData);
        return $linkData->serialize();
    }

    /**
     * @return array{0:array<string,mixed>|null,1:array<string,mixed>}
     */
    private function payloadFromSuggestion(LinkField $field, mixed $value): array
    {
        $input = $this->toArray($value);
        if ($input === []) {
            return [null, []];
        }

        $linkTypes = $field->getLinkTypes();
        $typeId = $this->normalizeTypeId((string)($input['type'] ?? ''), $linkTypes);
        $candidateKey = trim((string)($input['candidateKey'] ?? $input['candidate'] ?? ''));
        $rawInputValue = (string)($input['value'] ?? '');
        $rawValue = $this->normalizeRawTextLinkValue($rawInputValue);

        if ($typeId === '') {
            $typeId = $this->inferTypeId($field, $candidateKey, $rawValue, $linkTypes);
        }

        $linkType = $linkTypes[$typeId] ?? null;
        if (!$linkType instanceof BaseLinkType) {
            return [null, []];
        }

        $normalizedValue = '';
        if ($this->supportsCandidateContext($linkType)) {
            $normalizedValue = $candidateKey;
            if ($normalizedValue === '' && $rawValue !== '') {
                $normalizedValue = $this->resolveCandidateKeyFromLabel($field, $typeId, $linkType, $rawValue);
            }
            if ($normalizedValue === '' && $rawValue !== '' && $linkType->supports($rawValue)) {
                $normalizedValue = trim($rawValue);
            }
        } else {
            $normalizedValue = $rawValue !== '' ? $linkType->normalizeValue($rawValue) : '';
        }

        if ($normalizedValue === '') {
            return [null, []];
        }

        $payload = [
            'type' => $typeId,
            'value' => $normalizedValue,
        ];
        $normalized = [
            'type' => $typeId,
            'value' => $normalizedValue,
        ];

        if ($field->showLabelField) {
            $label = $this->normalizeRawTextLinkLabel((string)($input['label'] ?? ''), $rawInputValue);
            if ($label !== '') {
                $payload['label'] = $label;
                $normalized['label'] = $label;
            }
        }

        if (in_array('urlSuffix', $field->advancedFields, true)) {
            $urlSuffix = trim((string)($input['urlSuffix'] ?? ''));
            if ($urlSuffix !== '') {
                $payload['urlSuffix'] = $urlSuffix;
                $normalized['urlSuffix'] = $urlSuffix;
            }
        }

        if (in_array('target', $field->advancedFields, true)) {
            $openInNewTab = $this->asBool($input['openInNewTab'] ?? ($input['target'] ?? false));
            if ($openInNewTab) {
                $payload['target'] = '_blank';
                $normalized['openInNewTab'] = true;
            }
        }

        if ($this->supportsCandidateContext($linkType)) {
            $normalized['candidateKey'] = $normalizedValue;
        }

        return [$payload, $normalized];
    }

    /**
     * @param array<string, BaseLinkType> $linkTypes
     */
    private function normalizeTypeId(string $rawType, array $linkTypes): string
    {
        $normalized = strtolower(trim($rawType));
        if ($normalized === '') {
            return '';
        }

        foreach ($linkTypes as $typeId => $linkType) {
            if ($normalized === strtolower($typeId) || $normalized === strtolower($linkType::displayName())) {
                return $typeId;
            }
        }

        return '';
    }

    /**
     * @param array<string, BaseLinkType> $linkTypes
     */
    private function inferTypeId(LinkField $field, string $candidateKey, string $rawValue, array $linkTypes): string
    {
        if (count($linkTypes) === 1) {
            return array_key_first($linkTypes) ?? '';
        }

        foreach ($linkTypes as $typeId => $linkType) {
            if ($candidateKey !== '' && $this->supportsCandidateContext($linkType) && $linkType->supports($candidateKey)) {
                return $typeId;
            }
        }

        foreach ($linkTypes as $typeId => $linkType) {
            if ($rawValue !== '' && $linkType->supports($rawValue)) {
                return $typeId;
            }
        }

        foreach ($linkTypes as $typeId => $linkType) {
            if ($rawValue !== '' && $this->supportsCandidateContext($linkType)) {
                $candidateKey = $this->resolveCandidateKeyFromLabel($field, $typeId, $linkType, $rawValue);
                if ($candidateKey !== '') {
                    return $typeId;
                }
            }
        }

        return '';
    }

    private function supportsCandidateContext(BaseLinkType $linkType): bool
    {
        return $linkType instanceof EntryLinkType
            || $linkType instanceof CategoryLinkType
            || $linkType instanceof AssetLinkType;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{mode:string,count:int}
     */
    private function normalizeElementContextConfig(array $config): array
    {
        $mode = strtolower(trim((string)($config['mode'] ?? self::MODE_TOP_N)));
        if (!in_array($mode, [strtolower(self::MODE_TOP_N), strtolower(self::MODE_MOST_RECENT), strtolower(self::MODE_ALL), strtolower(self::MODE_NONE)], true)) {
            $mode = strtolower(self::MODE_TOP_N);
        }

        $canonicalMode = match ($mode) {
            strtolower(self::MODE_MOST_RECENT) => self::MODE_MOST_RECENT,
            strtolower(self::MODE_ALL) => self::MODE_ALL,
            strtolower(self::MODE_NONE) => self::MODE_NONE,
            default => self::MODE_TOP_N,
        };

        $count = (int)($config['count'] ?? 10);
        if ($count <= 0) {
            return [
                'mode' => self::MODE_NONE,
                'count' => 0,
            ];
        }

        $count = min(250, $count);

        return [
            'mode' => $canonicalMode,
            'count' => $count,
        ];
    }

    /**
     * @return array{mode:string,count:int}
     */
    private function defaultElementContextConfig(): array
    {
        return [
            'mode' => self::MODE_TOP_N,
            'count' => 10,
        ];
    }

    /**
     * @return string[]
     */
    private function textTypeRulesForLinkType(string $typeId, BaseLinkType $linkType): array
    {
        return match ($typeId) {
            'url' => [
                'If you choose the url type, return the destination in value.',
                'Return only a valid URL, root-relative URL, or anchor allowed by the field settings.',
                'Do not return markdown links like [label](url) or partial bracketed URLs.',
            ],
            'email' => [
                'If you choose the email type, return an email address or a mailto: link in value.',
                'Do not wrap the value in markdown or quotes.',
            ],
            'tel' => [
                'If you choose the tel type, return a phone number or tel: link in value.',
                'Do not wrap the value in markdown or quotes.',
            ],
            'sms' => [
                'If you choose the sms type, return a phone number or sms: link in value.',
                'Do not wrap the value in markdown or quotes.',
            ],
            default => [
                sprintf('If you choose the %s type, return its destination in value.', $linkType::displayName()),
            ],
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidateRecordsForLinkType(LinkField $field, string $typeId, BaseLinkType $linkType, string $mode, int $count, ?int $currentEntryId = null): array
    {
        if (!$this->supportsCandidateContext($linkType)) {
            return [];
        }

        if ($mode === self::MODE_NONE) {
            return [];
        }

        $rows = match ($mode) {
            self::MODE_TOP_N => $this->candidateRowsForTopN($field, $typeId, $linkType, $count, $currentEntryId),
            self::MODE_MOST_RECENT => $this->candidateRowsForMostRecent($typeId, $linkType, $count, $currentEntryId),
            self::MODE_ALL => $this->candidateRowsForAll($typeId, $linkType, $currentEntryId),
            default => $this->candidateRowsForTopN($field, $typeId, $linkType, $count, $currentEntryId),
        };

        $records = [];
        foreach ($rows as $row) {
            $element = $row['element'] ?? null;
            if (!$element instanceof ElementInterface || !$element->id || !$element->siteId) {
                continue;
            }

            try {
                $candidateKey = $linkType->normalizeValue($element);
            } catch (\Throwable) {
                continue;
            }

            $title = trim((string)($row['title'] ?? (string)$element));
            if ($candidateKey === '' || $title === '') {
                continue;
            }

            $record = [
                'candidateKey' => $candidateKey,
                'title' => $title,
            ];

            $context = trim((string)($row['context'] ?? ''));
            if ($context !== '') {
                $record['context'] = $context;
            }

            $url = method_exists($element, 'getUrl') ? trim((string)$element->getUrl()) : '';
            if ($url !== '') {
                $record['url'] = $url;
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @return array<int,array{element:ElementInterface,title:string,context:string}>
     */
    private function candidateRowsForTopN(LinkField $field, string $typeId, BaseLinkType $linkType, int $count, ?int $currentEntryId = null): array
    {
        $popularIds = $this->topRelationTargetIds((int)($field->id ?? 0), max(25, $count * 8));
        if ($typeId === self::ELEMENT_TYPE_ENTRY && $currentEntryId !== null && $currentEntryId > 0) {
            $popularIds = array_values(array_filter($popularIds, static fn(int $id): bool => $id !== $currentEntryId));
        }

        if ($popularIds === []) {
            return $this->candidateRowsForMostRecent($typeId, $linkType, $count, $currentEntryId);
        }

        $query = $this->buildElementQueryForLinkType($typeId, $linkType, $currentEntryId);
        if (method_exists($query, 'id')) {
            $query->id($popularIds);
        }
        $query->limit(null);

        try {
            $elements = $query->all();
        } catch (\Throwable) {
            return [];
        }

        $rankById = [];
        foreach ($popularIds as $index => $id) {
            $rankById[$id] = $index;
        }

        $rows = [];
        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface || !is_numeric($element->id) || !isset($rankById[(int)$element->id])) {
                continue;
            }
            $rows[] = [
                'element' => $element,
                'title' => $this->elementTitle($element),
                'context' => $this->candidateContextForElement($typeId, $element),
                'rank' => $rankById[(int)$element->id],
            ];
        }

        usort($rows, static function(array $a, array $b): int {
            $rankA = (int)($a['rank'] ?? PHP_INT_MAX);
            $rankB = (int)($b['rank'] ?? PHP_INT_MAX);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
        });

        $rows = array_slice($rows, 0, $count);
        if (count($rows) >= $count) {
            return $rows;
        }

        $excludedIds = [];
        foreach ($rows as $row) {
            $element = $row['element'] ?? null;
            if ($element instanceof ElementInterface && is_numeric($element->id)) {
                $excludedIds[] = (int)$element->id;
            }
        }

        $fallbackRows = $this->candidateRowsForMostRecent($typeId, $linkType, $count, $currentEntryId, $excludedIds);
        foreach ($fallbackRows as $fallbackRow) {
            $rows[] = $fallbackRow;
            if (count($rows) >= $count) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return array<int,array{element:ElementInterface,title:string,context:string}>
     */
    private function candidateRowsForMostRecent(string $typeId, BaseLinkType $linkType, int $count, ?int $currentEntryId = null, array $excludedIds = []): array
    {
        $query = $this->buildElementQueryForLinkType($typeId, $linkType, $currentEntryId);
        $this->excludeElementIdsFromQuery($query, $excludedIds);
        if (method_exists($query, 'orderBy')) {
            $query->orderBy(['dateUpdated' => SORT_DESC, 'id' => SORT_DESC]);
        }
        $query->limit($count);

        try {
            $elements = $query->all();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface) {
                continue;
            }
            $rows[] = [
                'element' => $element,
                'title' => $this->elementTitle($element),
                'context' => $this->candidateContextForElement($typeId, $element),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int,array{element:ElementInterface,title:string,context:string}>
     */
    private function candidateRowsForAll(string $typeId, BaseLinkType $linkType, ?int $currentEntryId = null): array
    {
        $query = $this->buildElementQueryForLinkType($typeId, $linkType, $currentEntryId);
        if (method_exists($query, 'orderBy')) {
            $query->orderBy(['title' => SORT_ASC]);
        }
        $query->limit(self::ALL_MODE_CANDIDATE_CAP);

        try {
            $elements = $query->all();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];
        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface) {
                continue;
            }
            $rows[] = [
                'element' => $element,
                'title' => $this->elementTitle($element),
                'context' => $this->candidateContextForElement($typeId, $element),
            ];
        }

        return $rows;
    }

    private function buildElementQueryForLinkType(string $typeId, BaseLinkType $linkType, ?int $currentEntryId = null): mixed
    {
        return match ($typeId) {
            self::ELEMENT_TYPE_ENTRY => $this->buildEntryQuery($linkType, $currentEntryId),
            self::ELEMENT_TYPE_CATEGORY => $this->buildCategoryQuery($linkType),
            self::ELEMENT_TYPE_ASSET => $this->buildAssetQuery($linkType),
            default => throw new InvalidArgumentException(sprintf('Unsupported link candidate type "%s".', $typeId)),
        };
    }

    private function buildEntryQuery(BaseLinkType $linkType, ?int $currentEntryId = null): mixed
    {
        /** @var EntryLinkType $linkType */
        $query = EntryElement::find()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->status(null)
            ->uri('not :empty:');

        if ($currentEntryId !== null && $currentEntryId > 0 && method_exists($query, 'andWhere')) {
            $query->andWhere(['not', ['elements.id' => $currentEntryId]]);
        }

        if (!$linkType->showUnpermittedEntries && method_exists($query, 'editable')) {
            $query->editable(true);
        }

        $this->applyEntrySources($query, $linkType->sources);
        return $query;
    }

    private function excludeElementIdsFromQuery(mixed $query, array $excludedIds): void
    {
        $excludedIds = array_values(array_unique(array_filter(array_map('intval', $excludedIds), static fn(int $id): bool => $id > 0)));
        if ($excludedIds === [] || !method_exists($query, 'andWhere')) {
            return;
        }

        $query->andWhere(['not in', 'elements.id', $excludedIds]);
    }

    private function buildCategoryQuery(BaseLinkType $linkType): mixed
    {
        /** @var CategoryLinkType $linkType */
        $query = CategoryElement::find()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->status(null)
            ->uri('not :empty:');

        $this->applyCategorySources($query, $linkType->sources);
        return $query;
    }

    private function buildAssetQuery(BaseLinkType $linkType): mixed
    {
        /** @var AssetLinkType $linkType */
        $query = AssetElement::find()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->status(null);

        if (is_array($linkType->allowedKinds) && $linkType->allowedKinds !== []) {
            $query->kind($linkType->allowedKinds);
        }

        $this->applyAssetSources($query, $linkType->sources);
        return $query;
    }

    private function applyEntrySources(mixed $query, ?array $sources): void
    {
        $sectionIds = [];
        foreach ($this->normalizedSourceList($sources) as $source) {
            if (preg_match('/^section:([0-9a-f\-]+)$/i', $source, $match) !== 1) {
                continue;
            }

            $section = Craft::$app->getEntries()->getSectionByUid($match[1]);
            if ($section?->id) {
                $sectionIds[] = (int)$section->id;
            }
        }

        if ($sectionIds !== [] && method_exists($query, 'sectionId')) {
            $query->sectionId(array_values(array_unique($sectionIds)));
        }
    }

    private function applyCategorySources(mixed $query, ?array $sources): void
    {
        $groupIds = [];
        foreach ($this->normalizedSourceList($sources) as $source) {
            if (preg_match('/^(group|categorygroup):([0-9a-f\-]+)$/i', $source, $match) !== 1) {
                continue;
            }

            $group = Craft::$app->getCategories()->getGroupByUid($match[2]);
            if ($group?->id) {
                $groupIds[] = (int)$group->id;
            }
        }

        if ($groupIds !== [] && method_exists($query, 'groupId')) {
            $query->groupId(array_values(array_unique($groupIds)));
        }
    }

    private function applyAssetSources(mixed $query, ?array $sources): void
    {
        $volumeIds = [];
        foreach ($this->normalizedSourceList($sources) as $source) {
            if (preg_match('/^volume:([0-9a-f\-]+)$/i', $source, $match) !== 1) {
                continue;
            }

            $volume = Craft::$app->getVolumes()->getVolumeByUid($match[1]);
            if ($volume?->id) {
                $volumeIds[] = (int)$volume->id;
            }
        }

        if ($volumeIds !== [] && method_exists($query, 'volumeId')) {
            $query->volumeId(array_values(array_unique($volumeIds)));
        }
    }

    /**
     * @return string[]
     */
    private function normalizedSourceList(?array $sources): array
    {
        if ($sources === null || $sources === [] || $sources === ['*']) {
            return [];
        }

        $normalized = [];
        foreach ($sources as $source) {
            $raw = trim((string)$source);
            if ($raw !== '' && $raw !== '*') {
                $normalized[] = $raw;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function elementTitle(ElementInterface $element): string
    {
        if ($element instanceof AssetElement) {
            return trim((string)$element->title) !== '' ? trim((string)$element->title) : trim((string)$element->getFilename());
        }

        return trim((string)($element->title ?? (string)$element));
    }

    private function candidateContextForElement(string $typeId, ElementInterface $element): string
    {
        $parts = [];

        if ($typeId === self::ELEMENT_TYPE_ENTRY && $element instanceof EntryElement) {
            $section = $element->getSection();
            if ($section?->name) {
                $parts[] = $section->name;
            }
        }

        if ($typeId === self::ELEMENT_TYPE_CATEGORY && $element instanceof CategoryElement) {
            $group = $element->getGroup();
            if ($group?->name) {
                $parts[] = $group->name;
            }
        }

        if ($typeId === self::ELEMENT_TYPE_ASSET && $element instanceof AssetElement) {
            $parts[] = $element->getFilename();
            if ($element->kind) {
                $parts[] = strtoupper((string)$element->kind);
            }
            $volume = $element->getVolume();
            if ($volume?->name) {
                $parts[] = $volume->name;
            }
        }

        $site = $element->getSite();
        if ($site?->name) {
            $parts[] = $site->name;
        }

        return implode(' | ', array_values(array_filter(array_map('trim', $parts), static fn(string $part) => $part !== '')));
    }

    private function resolveCandidateKeyFromLabel(LinkField $field, string $typeId, BaseLinkType $linkType, string $label): string
    {
        if (!$this->supportsCandidateContext($linkType)) {
            return '';
        }

        $query = $this->buildElementQueryForLinkType($typeId, $linkType);
        $query->limit(self::ALL_MODE_CANDIDATE_CAP);

        try {
            $elements = $query->all();
        } catch (\Throwable) {
            return '';
        }

        $needle = strtolower(trim($label));
        if ($needle === '') {
            return '';
        }

        $matchedKeys = [];
        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface) {
                continue;
            }

            if (strtolower($this->elementTitle($element)) !== $needle) {
                continue;
            }

            try {
                $matchedKeys[] = $linkType->normalizeValue($element);
            } catch (\Throwable) {
                continue;
            }
        }

        $matchedKeys = array_values(array_unique(array_filter($matchedKeys)));
        return count($matchedKeys) === 1 ? $matchedKeys[0] : '';
    }

    private function currentEntryIdFromPromptConfig(array $promptConfig): ?int
    {
        $entryId = $promptConfig['entryId'] ?? null;
        if (!is_numeric($entryId) || (int)$entryId <= 0) {
            return null;
        }

        return (int)$entryId;
    }

    private function elementExistsForLinkType(BaseElementLinkType $linkType, string $value): bool
    {
        try {
            return $linkType->element($value) instanceof ElementInterface;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return int[]
     */
    private function topRelationTargetIds(int $fieldId, int $limit): array
    {
        if ($fieldId <= 0 || $limit <= 0) {
            return [];
        }

        $rows = (new Query())
            ->select(['targetId', 'count' => 'COUNT(*)'])
            ->from(Table::RELATIONS)
            ->where(['fieldId' => $fieldId])
            ->groupBy(['targetId'])
            ->orderBy(['count' => SORT_DESC, 'targetId' => SORT_ASC])
            ->limit($limit)
            ->all();

        $ids = [];
        foreach ($rows as $row) {
            $id = isset($row['targetId']) ? (int)$row['targetId'] : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && $value instanceof \JsonSerializable) {
            try {
                $serialized = $value->jsonSerialize();
                return is_array($serialized) ? $serialized : [];
            } catch (\Throwable) {
                return [];
            }
        }

        if (is_string($value)) {
            $decoded = Json::decodeIfJson(trim($value));
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float)$value !== 0.0;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'on', 'yes', '_blank'], true);
    }

    private function normalizeRawTextLinkValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $trimmed, $matches) === 1) {
            return trim((string)($matches[2] ?? ''));
        }

        if (preg_match('/\]\(([^)]+)\)/', $trimmed, $matches) === 1) {
            return $this->sanitizeLooseTextLinkValue((string)($matches[1] ?? ''));
        }

        if (preg_match('/^<([^>]+)>$/', $trimmed, $matches) === 1) {
            return trim((string)($matches[1] ?? ''));
        }

        return $this->sanitizeLooseTextLinkValue($trimmed);
    }

    private function normalizeRawTextLinkLabel(string $label, string $rawValue): string
    {
        $trimmedLabel = trim($label);
        if ($trimmedLabel !== '') {
            return $trimmedLabel;
        }

        if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', trim($rawValue), $matches) === 1) {
            return trim((string)($matches[1] ?? ''));
        }

        return '';
    }

    private function sanitizeLooseTextLinkValue(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        $normalized = rawurldecode($normalized);
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'`<>[](){}");

        if (preg_match('~(https?://[^\s"\'<>\])}]+)~i', $normalized, $matches) === 1) {
            return rtrim(trim((string)($matches[1] ?? '')), '.,;:!?');
        }

        if (preg_match('~^(?:/[^"\'>\s]*)$~', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('~^(?:#[^"\'>\s]+)$~', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('~^(?:mailto:|tel:|sms:)[^"\'>\s]+$~i', $normalized) === 1) {
            return $normalized;
        }

        return rtrim($normalized, '.,;:!?');
    }
}
