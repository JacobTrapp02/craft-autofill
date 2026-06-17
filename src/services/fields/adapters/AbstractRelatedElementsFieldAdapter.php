<?php

declare(strict_types=1);

namespace jtdev\craftautofill\services\fields\adapters;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Table;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use yii\base\InvalidConfigException;

abstract class AbstractRelatedElementsFieldAdapter implements FieldAdapterInterface
{
    private const ALL_MODE_CANDIDATE_CAP = 1000;

    public function isAvailableInLiteVersion(): bool
    {
        return false;
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
        return [
            'prompt' => trim((string)($config['prompt'] ?? '')),
        ];
    }

    public function validatePromptConfig(array $config, FieldInterface $field): array
    {
        $normalized = $this->normalizePromptConfig($config, $field);
        $errors = [];

        if ($normalized['prompt'] === '') {
            $errors[] = sprintf('Prompt is required for %s fields.', $this->displayTypeName());
        }

        return $errors;
    }

    public function buildPromptContract(FieldInterface $field, array $promptConfig = []): array
    {
        $mode = $this->relatedMode($promptConfig);
        $topN = $this->relatedTopN($promptConfig);
        $maxSelections = $this->maxSelections($field);

        $candidateTitles = $this->candidateTitles($field, $mode, $topN);
        $maxRule = $maxSelections !== null
            ? sprintf('Select at most %d title(s).', $maxSelections)
            : 'Select as many titles as needed.';

        return [
            'type' => 'object',
            'rules' => [
                'Return JSON only.',
                'Return an object with a selectedTitles array.',
                'Each selected title must exactly match one candidate title.',
                $maxRule,
            ],
            'properties' => [
                'selectedTitles' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'candidateTitles' => $candidateTitles,
            'selectionMode' => $mode,
            'topN' => $mode === 'topN' ? $topN : null,
        ];
    }

    public function getContextValue(FieldInterface $field, mixed $value, array $options = []): string
    {
        $titles = $this->extractCurrentTitles($value);
        return implode(', ', $titles);
    }

    public function validateSuggestion(FieldInterface $field, mixed $value): bool
    {
        $decoded = $this->toArray($value);
        if (array_key_exists('selectedTitles', $decoded) && is_array($decoded['selectedTitles'])) {
            return true;
        }

        $titles = $this->normalizeSuggestion($field, $value);
        return is_array($titles);
    }

    public function normalizeSuggestion(FieldInterface $field, mixed $value): mixed
    {
        $titles = $this->extractSuggestedTitles($value);
        $normalized = [];
        foreach ($titles as $title) {
            $trimmed = trim((string)$title);
            if ($trimmed === '') {
                continue;
            }
            if (!in_array($trimmed, $normalized, true)) {
                $normalized[] = $trimmed;
            }
        }

        return $normalized;
    }

    public function applySuggestionToEntry(FieldInterface $field, Entry $entry, mixed $value): mixed
    {
        $titles = $this->normalizeSuggestion($field, $value);
        if (!is_array($titles)) {
            $titles = [];
        }

        $matchedIds = $this->matchTitlesToIds($field, $titles);
        $handle = (string)($field->handle ?? '');
        if ($handle === '') {
            throw new InvalidConfigException('Could not resolve relation field handle.');
        }

        $entry->setFieldValue($handle, $matchedIds);
        return $matchedIds;
    }

    /**
     * @return string[]
     */
    protected function candidateTitles(FieldInterface $field, string $mode, int $topN): array
    {
        if ($mode === 'topN') {
            $fastRows = $this->candidateRowsForTopN($field, $topN);
            if ($fastRows !== []) {
                $titles = [];
                foreach ($fastRows as $row) {
                    $title = (string)$row['title'];
                    if (!in_array($title, $titles, true)) {
                        $titles[] = $title;
                    }
                }

                if ($titles !== []) {
                    return array_slice($titles, 0, $topN);
                }
            }
        }

        $query = $this->buildElementQuery($field);
        $query->limit(null);

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
            $title = trim((string)($element->title ?? ''));
            if ($title === '' || !is_numeric($element->id)) {
                continue;
            }
            $rows[] = [
                'id' => (int)$element->id,
                'title' => $title,
            ];
        }

        if ($rows === []) {
            return [];
        }

        // For topN mode, rank by existing relation volume for this exact field.
        if ($mode === 'topN') {
            $countsById = $this->relationCountsByTargetId($field, array_column($rows, 'id'));
            usort($rows, static function(array $a, array $b) use ($countsById): int {
                $countA = (int)($countsById[$a['id']] ?? 0);
                $countB = (int)($countsById[$b['id']] ?? 0);
                if ($countA !== $countB) {
                    return $countB <=> $countA;
                }

                return strcasecmp((string)$a['title'], (string)$b['title']);
            });
            $rows = array_slice($rows, 0, $topN);
        } else {
            usort($rows, static fn(array $a, array $b): int => strcasecmp((string)$a['title'], (string)$b['title']));
            $rows = array_slice($rows, 0, self::ALL_MODE_CANDIDATE_CAP);
        }

        $titles = [];
        foreach ($rows as $row) {
            $title = (string)$row['title'];
            if (!in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Fast path for Top-N mode that avoids loading the full candidate set.
     *
     * @return array<int, array{id:int,title:string}>
     */
    protected function candidateRowsForTopN(FieldInterface $field, int $topN): array
    {
        if (!($field instanceof BaseRelationField) || !is_numeric($field->id) || (int)$field->id <= 0) {
            return [];
        }

        $popularTargetIds = $this->topRelationTargetIds((int)$field->id, max(25, $topN * 8));
        if ($popularTargetIds === []) {
            return [];
        }

        $query = $this->buildElementQuery($field);
        $query->limit(null);
        if (method_exists($query, 'id')) {
            $query->id($popularTargetIds);
        }

        try {
            $elements = $query->all();
        } catch (\Throwable) {
            return [];
        }

        $rankById = [];
        foreach ($popularTargetIds as $index => $id) {
            $rankById[$id] = $index;
        }

        $rows = [];
        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface || !is_numeric($element->id)) {
                continue;
            }
            $id = (int)$element->id;
            $title = trim((string)($element->title ?? ''));
            if ($title === '' || !isset($rankById[$id])) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'title' => $title,
            ];
        }

        usort($rows, static function(array $a, array $b) use ($rankById): int {
            $rankA = $rankById[$a['id']] ?? PHP_INT_MAX;
            $rankB = $rankById[$b['id']] ?? PHP_INT_MAX;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return strcasecmp((string)$a['title'], (string)$b['title']);
        });

        return array_slice($rows, 0, $topN);
    }

    /**
     * @return int[]
     */
    protected function matchTitlesToIds(FieldInterface $field, array $titles): array
    {
        if ($titles === []) {
            return [];
        }

        $query = $this->buildElementQuery($field);
        $query->limit(null);

        try {
            $elements = $query->all();
        } catch (\Throwable) {
            return [];
        }

        $idsByLowerTitle = [];
        $allIds = [];
        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface) {
                continue;
            }
            $title = trim((string)($element->title ?? ''));
            if ($title === '') {
                continue;
            }
            $key = strtolower($title);
            if (is_numeric($element->id)) {
                $id = (int)$element->id;
                $idsByLowerTitle[$key] ??= [];
                $idsByLowerTitle[$key][] = $id;
                $allIds[] = $id;
            }
        }

        $allIds = array_values(array_unique($allIds));
        $countsById = $this->relationCountsByTargetId($field, $allIds);

        $matched = [];
        foreach ($titles as $title) {
            $key = strtolower(trim((string)$title));
            if ($key === '' || !isset($idsByLowerTitle[$key])) {
                continue;
            }
            $candidateIds = array_values(array_unique($idsByLowerTitle[$key]));

            usort($candidateIds, static function(int $a, int $b) use ($countsById): int {
                $countA = (int)($countsById[$a] ?? 0);
                $countB = (int)($countsById[$b] ?? 0);
                if ($countA !== $countB) {
                    return $countB <=> $countA;
                }

                return $a <=> $b;
            });

            $id = $candidateIds[0];
            if (!in_array($id, $matched, true)) {
                $matched[] = $id;
            }
        }

        $maxSelections = $this->maxSelections($field);
        if ($maxSelections !== null && $maxSelections > 0 && count($matched) > $maxSelections) {
            $matched = array_slice($matched, 0, $maxSelections);
        }

        return $matched;
    }

    protected function relatedMode(array $promptConfig): string
    {
        $related = is_array($promptConfig['related'] ?? null) ? $promptConfig['related'] : [];
        $mode = strtolower(trim((string)($related['mode'] ?? 'topN')));
        return $mode === 'all' ? 'all' : 'topN';
    }

    protected function relatedTopN(array $promptConfig): int
    {
        $related = is_array($promptConfig['related'] ?? null) ? $promptConfig['related'] : [];
        $raw = (int)($related['topN'] ?? 25);
        return max(1, min(500, $raw));
    }

    protected function maxSelections(FieldInterface $field): ?int
    {
        if ($field instanceof BaseRelationField && is_numeric($field->maxRelations) && (int)$field->maxRelations > 0) {
            return (int)$field->maxRelations;
        }

        return null;
    }

    /**
     * @return string[]
     */
    protected function extractCurrentTitles(mixed $value): array
    {
        if (is_object($value) && method_exists($value, 'all')) {
            try {
                $items = $value->all();
            } catch (\Throwable) {
                $items = [];
            }
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            $items = [];
        }

        $titles = [];
        foreach ($items as $item) {
            if (!$item instanceof ElementInterface) {
                continue;
            }
            $title = trim((string)($item->title ?? ''));
            if ($title !== '' && !in_array($title, $titles, true)) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * @return string[]
     */
    protected function extractSuggestedTitles(mixed $value): array
    {
        if (is_array($value)) {
            if (isset($value['selectedTitles']) && is_array($value['selectedTitles'])) {
                return array_values($value['selectedTitles']);
            }

            if (array_is_list($value)) {
                return array_values(array_map(fn($v) => (string)$v, $value));
            }
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $this->extractSuggestedTitles($decoded);
            }

            $parts = preg_split('/[\r\n,]+/', $trimmed) ?: [];
            return array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode(trim($value), true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function buildElementQuery(FieldInterface $field): mixed
    {
        $elementType = $this->elementTypeClass();
        $query = $elementType::find();

        if ($field instanceof BaseRelationField) {
            foreach ($field->getInputSelectionCriteria() as $key => $criteriaValue) {
                if (method_exists($query, $key)) {
                    $query->{$key}($criteriaValue);
                }
            }

            $this->applySourceFilters($field, $query);
        }

        if (method_exists($query, 'siteId')) {
            $query->siteId(Craft::$app->getSites()->getCurrentSite()->id);
        }
        if (method_exists($query, 'status')) {
            $query->status(null);
        }

        return $query;
    }

    protected function applySourceFilters(BaseRelationField $field, mixed $query): void
    {
        $sources = $field->getInputSources();
        if ($sources === '*' || $sources === null) {
            return;
        }

        $sourceList = is_array($sources) ? $sources : [$sources];
        $sectionIds = [];
        $categoryGroupIds = [];
        $tagGroupIds = [];

        foreach ($sourceList as $source) {
            $raw = trim((string)$source);
            if ($raw === '') {
                continue;
            }

            if (preg_match('/^section:([0-9a-f\-]+)$/i', $raw, $m) === 1) {
                $section = Craft::$app->getEntries()->getSectionByUid($m[1]);
                if ($section?->id) {
                    $sectionIds[] = (int)$section->id;
                }
                continue;
            }

            if (preg_match('/^(group|categorygroup):([0-9a-f\-]+)$/i', $raw, $m) === 1) {
                $group = Craft::$app->getCategories()->getGroupByUid($m[2]);
                if ($group?->id) {
                    $categoryGroupIds[] = (int)$group->id;
                }
                continue;
            }

            if (preg_match('/^taggroup:([0-9a-f\-]+)$/i', $raw, $m) === 1) {
                $group = Craft::$app->getTags()->getTagGroupByUid($m[1]);
                if ($group?->id) {
                    $tagGroupIds[] = (int)$group->id;
                }
            }
        }

        if ($sectionIds !== [] && method_exists($query, 'sectionId')) {
            $query->sectionId(array_values(array_unique($sectionIds)));
        }
        if ($categoryGroupIds !== [] && method_exists($query, 'groupId')) {
            $query->groupId(array_values(array_unique($categoryGroupIds)));
        }
        if ($tagGroupIds !== [] && method_exists($query, 'groupId')) {
            $query->groupId(array_values(array_unique($tagGroupIds)));
        }
    }

    /**
     * @param int[] $targetIds
     * @return array<int,int>
     */
    protected function relationCountsByTargetId(FieldInterface $field, array $targetIds): array
    {
        if (!($field instanceof BaseRelationField) || !is_numeric($field->id) || (int)$field->id <= 0 || $targetIds === []) {
            return [];
        }

        $rows = (new \craft\db\Query())
            ->select(['targetId', 'count' => 'COUNT(*)'])
            ->from(Table::RELATIONS)
            ->where([
                'fieldId' => (int)$field->id,
                'targetId' => $targetIds,
            ])
            ->groupBy(['targetId'])
            ->all();

        $counts = [];
        foreach ($rows as $row) {
            $id = isset($row['targetId']) ? (int)$row['targetId'] : 0;
            $count = isset($row['count']) ? (int)$row['count'] : 0;
            if ($id > 0) {
                $counts[$id] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return int[]
     */
    protected function topRelationTargetIds(int $fieldId, int $limit): array
    {
        if ($fieldId <= 0 || $limit <= 0) {
            return [];
        }

        $rows = (new \craft\db\Query())
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

    abstract protected function elementTypeClass(): string;

    abstract protected function displayTypeName(): string;
}
