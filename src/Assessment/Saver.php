<?php

namespace ErnestDefoe\Brainstorm\Assessment;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Creates and updates an assessment and its list of quizzes. Every value is
 * checked and clamped here. On an update only the keys sent are touched.
 */
class Saver
{
    public function __construct(private ConnectionInterface $db, private TranslatorInterface $translator)
    {
    }

    /** @param array<string, mixed> $data */
    public function save(Assessment $assessment, array $data, User $actor): Assessment
    {
        $isNew = ! $assessment->exists;
        $has = fn (string $key) => $isNew || array_key_exists($key, $data);

        $title = trim((string) Arr::get($data, 'title', $assessment->title ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $this->fail('title', 'assessment_title');
        }

        $items = $has('items') ? $this->items(Arr::get($data, 'items', []), $actor) : null;
        $status = Arr::get($data, 'status', $assessment->status ?? 'draft') === 'published' ? 'published' : 'draft';
        if ($status === 'published' && ($items === null ? ! $assessment->items()->exists() : ! $items)) {
            $this->fail('status', 'assessment_empty');
        }

        return $this->db->transaction(function () use ($assessment, $isNew, $has, $data, $actor, $title, $status, $items) {
            if ($isNew) {
                $assessment->user_id = $actor->id;
            }

            $assessment->title = $title;
            $assessment->slug = Str::limit(Str::slug($title), 190, '') ?: 'assessment';
            $assessment->status = $status;
            if ($has('description')) {
                $assessment->description = mb_substr(trim((string) Arr::get($data, 'description', '')), 0, 2000) ?: null;
            }
            if ($has('passMark')) {
                $assessment->pass_mark = max(0, min(100, (int) Arr::get($data, 'passMark', 70)));
            }
            if ($has('weighting')) {
                $weighting = Arr::get($data, 'weighting');
                $assessment->weighting = in_array($weighting, Assessment::WEIGHTINGS, true) ? $weighting : 'equal';
            }
            if ($status === 'published' && ! $assessment->published_at) {
                $assessment->published_at = Carbon::now();
            }
            $assessment->save();

            if ($items !== null) {
                Item::query()->where('assessment_id', $assessment->id)->delete();
                Item::query()->insert(array_map(fn (array $item) => ['assessment_id' => $assessment->id] + $item, $items));
                $assessment->unsetRelation('items');
            }

            return $assessment;
        });
    }

    /**
     * The quizzes, in order: each one the actor may see, once, with a weight
     * of 1 to 100 and whether it is required.
     *
     * @return list<array{quiz_id: int, weight: int, required: bool, position: int}>
     */
    private function items(mixed $raw, User $actor): array
    {
        $raw = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
        if (count($raw) > Assessment::MAX_ITEMS) {
            $this->fail('items', 'assessment_too_many', ['{max}' => Assessment::MAX_ITEMS]);
        }

        $ids = array_values(array_unique(array_filter(array_map(fn ($i) => (int) ($i['quizId'] ?? 0), $raw))));
        $known = $ids ? Quiz::query()->whereVisibleTo($actor)->whereIn('quizzes.id', $ids)->pluck('quizzes.id')->map(fn ($id) => (int) $id)->all() : [];
        if (count($known) !== count($ids)) {
            $this->fail('items', 'assessment_quiz');
        }

        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $id = (int) ($item['quizId'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'quiz_id' => $id,
                'weight' => max(1, min(100, (int) ($item['weight'] ?? 1))),
                'required' => (bool) ($item['required'] ?? true),
                'position' => count($out),
            ];
        }

        return $out;
    }

    /** @param array<string, int|string> $params */
    private function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-brainstorm.api.'.$key, $params)]);
    }
}
