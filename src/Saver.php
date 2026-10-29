<?php

namespace ErnestDefoe\Brainstorm;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates and updates a quiz and its questions from the editor's payload.
 *
 * Every value is checked and clamped here; nothing in the payload reaches a
 * column it was not meant for. Rewards are only touched by actors who may
 * attach them: for anyone else they keep whatever they were.
 */
class Saver
{
    public const MAX_QUESTIONS = 200;
    public const MAX_OPTIONS = 10;
    public const MAX_ACCEPTED = 20;

    public function __construct(
        private ConnectionInterface $db,
        private RichText $rich,
        private TranslatorInterface $translator,
        private Rewards $rewards
    ) {
    }

    /**
     * On an update only the keys sent are touched: a client that sends
     * `{status}` alone publishes the quiz and leaves everything else as it was.
     *
     * @param array<string, mixed> $data
     */
    public function save(?Quiz $quiz, array $data, User $actor): Quiz
    {
        $quiz ??= new Quiz();
        $isNew = ! $quiz->exists;
        $has = fn (string $key) => $isNew || array_key_exists($key, $data);

        $title = trim((string) Arr::get($data, 'title', $quiz->title ?? ''));
        if ($title === '' || mb_strlen($title) > 200) {
            $this->fail('title', 'title');
        }

        $status = Arr::get($data, 'status', $quiz->status ?? 'draft');
        if (! in_array($status, ['draft', 'published'], true)) {
            $status = 'draft';
        }

        $reveal = Arr::get($data, 'reveal', $quiz->reveal ?? 'answers');
        if (! in_array($reveal, Quiz::REVEALS, true)) {
            $reveal = 'answers';
        }

        $opensAt = $has('opensAt') ? $this->date(Arr::get($data, 'opensAt')) : $quiz->opens_at;
        $closesAt = $has('closesAt') ? $this->date(Arr::get($data, 'closesAt')) : $quiz->closes_at;
        if ($opensAt && $closesAt && $closesAt->lte($opensAt)) {
            $this->fail('closesAt', 'dates');
        }

        $questions = $has('questions') ? $this->questions(Arr::get($data, 'questions', [])) : null;

        if ($status === 'published' && ($questions === null ? ! $quiz->question_count : ! $questions)) {
            $this->fail('status', 'publish_empty');
        }

        return $this->db->transaction(function () use ($quiz, $isNew, $has, $data, $actor, $title, $status, $reveal, $opensAt, $closesAt, $questions) {
            $before = $isNew ? null : $this->marking($quiz, $quiz->questions()->get()->all());

            if ($isNew) {
                $quiz->user_id = $actor->id;
            }

            $quiz->title = $title;
            $quiz->slug = Str::limit(Str::slug($title), 190, '') ?: 'quiz';
            $quiz->status = $status;
            $quiz->reveal = $reveal;
            $quiz->opens_at = $opensAt;
            $quiz->closes_at = $closesAt;

            foreach ([
                'time_limit' => ['timeLimit', 10, 86400],
                'max_attempts' => ['maxAttempts', 1, 1000],
                'question_pick' => ['questionPick', 1, self::MAX_QUESTIONS],
            ] as $column => [$key, $min, $max]) {
                if ($has($key)) {
                    $quiz->$column = $this->int($data, $key, $min, $max);
                }
            }
            if ($has('passMark')) {
                $quiz->pass_mark = $this->int($data, 'passMark', 0, 100) ?? 70;
            }
            foreach (['shuffle_questions' => ['shuffleQuestions', false], 'shuffle_options' => ['shuffleOptions', true], 'partial_credit' => ['partialCredit', false], 'leaderboard' => ['leaderboard', true]] as $column => [$key, $default]) {
                if ($has($key)) {
                    $quiz->$column = (bool) Arr::get($data, $key, $default);
                }
            }

            if ($status === 'published' && ! $quiz->published_at) {
                $quiz->published_at = Carbon::now();
            }

            if (Access::canReward($actor)) {
                if ($has('rewardPoints')) {
                    $quiz->reward_points = $this->int($data, 'rewardPoints', -100000, 100000) ?? 0;
                }
                if ($has('rewardBadgeId')) {
                    $quiz->reward_badge_id = $this->badge(Arr::get($data, 'rewardBadgeId'));
                }
            } elseif ($isNew) {
                $quiz->reward_points = 0;
                $quiz->reward_badge_id = null;
            }

            // A new quiz is saved first: the formatter is given it as context.
            if ($isNew) {
                $quiz->save();
            }
            if ($has('description')) {
                $quiz->description = $this->rich->store((string) Arr::get($data, 'description', ''), $quiz, $actor);
            }

            if ($questions !== null) {
                $quiz->question_count = count($questions);
                $this->syncQuestions($quiz, $questions, $actor);
            }

            /*
             * 🚨 A quiz that pays a reward is marked the way the person who
             * attached the reward saw it. Anyone without the reward permission
             * may still fix its wording, but not its marking — a pass mark of 0
             * or a rewritten key would pay whoever they sent to take it.
             */
            if ($before !== null && ! Access::canReward($actor) && ($quiz->reward_points || $quiz->reward_badge_id)
                && $before !== $this->marking($quiz, Question::query()->where('quiz_id', $quiz->id)->get()->all())) {
                $this->fail('questions', 'reward_locked');
            }

            $quiz->save();

            return $quiz;
        });
    }

    /**
     * Everything that decides a mark, in an order-free form.
     *
     * @param list<Question> $questions
     */
    private function marking(Quiz $quiz, array $questions): string
    {
        $key = array_map(function (Question $q) {
            // True/false options have no text; their id is what they say.
            $options = array_map(fn ($o) => [$q->type === 'boolean' ? (string) $o['id'] : mb_strtolower(trim((string) ($o['text'] ?? ''))), (bool) ($o['correct'] ?? false)], $q->options ?: []);
            sort($options);

            return [$q->type, $q->points, $options];
        }, $questions);
        sort($key);

        return json_encode([$quiz->pass_mark, $quiz->partial_credit, $quiz->question_pick, $quiz->max_attempts, $quiz->reveal, $key]) ?: '';
    }

    /**
     * Questions keep their ids across saves (attempts point at them by id),
     * new ones are added and the ones left out are deleted.
     *
     * @param list<array<string, mixed>> $questions
     */
    private function syncQuestions(Quiz $quiz, array $questions, User $actor): void
    {
        $existing = Question::query()->where('quiz_id', $quiz->id)->get()->keyBy('id');
        $kept = [];

        foreach ($questions as $position => $q) {
            $question = isset($q['id']) ? $existing->get($q['id']) : null;
            $question ??= new Question();

            $question->quiz_id = $quiz->id;
            $question->position = $position;
            $question->type = $q['type'];
            $question->body = (string) $this->rich->store($q['body'], $quiz, $actor, 5000);
            $question->image_url = $q['imageUrl'];
            $question->points = $q['points'];
            $question->options = $q['options'];
            $question->explanation = $this->rich->store($q['explanation'], $quiz, $actor, 5000);
            $question->save();

            $kept[] = $question->id;
        }

        Question::query()->where('quiz_id', $quiz->id)->whereNotIn('id', $kept ?: [0])->delete();
        $quiz->unsetRelation('questions');
    }

    /** @return list<array<string, mixed>> */
    private function questions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        if (count($raw) > self::MAX_QUESTIONS) {
            $this->fail('questions', 'too_many_questions', ['{max}' => self::MAX_QUESTIONS]);
        }

        $out = [];
        foreach (array_values($raw) as $i => $q) {
            if (! is_array($q)) {
                continue;
            }

            $n = ['{number}' => $i + 1];
            $type = $q['type'] ?? null;
            if (! in_array($type, Question::TYPES, true)) {
                $this->fail('questions', 'question_type', $n);
            }

            $body = trim((string) ($q['body'] ?? ''));
            if ($body === '' || mb_strlen($body) > 5000) {
                $this->fail('questions', 'question_body', $n);
            }

            $out[] = [
                'id' => isset($q['id']) && is_numeric($q['id']) ? (int) $q['id'] : null,
                'type' => $type,
                'body' => $body,
                'imageUrl' => $this->image($q['imageUrl'] ?? null, $n),
                'points' => max(1, min(100, (int) ($q['points'] ?? 1))),
                'explanation' => mb_substr(trim((string) ($q['explanation'] ?? '')), 0, 5000),
                'options' => $this->options($type, $q['options'] ?? [], $n),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, int> $n
     * @return list<array{id: string, text: string, correct: bool}>
     */
    private function options(string $type, mixed $raw, array $n): array
    {
        $raw = is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];

        if ($type === 'boolean') {
            // Two fixed options; the payload only says which one is right.
            $true = false;
            foreach ($raw as $o) {
                if (($o['id'] ?? null) === 'true') {
                    $true = (bool) ($o['correct'] ?? false);
                }
            }

            return [
                ['id' => 'true', 'text' => '', 'correct' => $true],
                ['id' => 'false', 'text' => '', 'correct' => ! $true],
            ];
        }

        $out = [];
        $seen = [];
        foreach ($raw as $o) {
            $text = trim((string) ($o['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 300) {
                $this->fail('questions', 'option_length', $n);
            }

            // Kept from the editor when well-formed, so a saved attempt's
            // answers still name the same options; otherwise a fresh random id
            // that says nothing about the option's place or truth.
            $id = (string) ($o['id'] ?? '');
            if (! preg_match('/^[a-z0-9]{6,16}$/', $id) || isset($seen[$id])) {
                $id = bin2hex(random_bytes(4));
            }
            $seen[$id] = true;

            $out[] = ['id' => $id, 'text' => $text, 'correct' => $type === 'text' || ! empty($o['correct'])];
        }

        $max = $type === 'text' ? self::MAX_ACCEPTED : self::MAX_OPTIONS;
        $min = $type === 'text' ? 1 : 2;
        if (count($out) < $min || count($out) > $max) {
            $this->fail('questions', $type === 'text' ? 'accepted_count' : 'option_count', $n + ['{min}' => $min, '{max}' => $max]);
        }

        if (! array_filter($out, fn ($o) => $o['correct'])) {
            $this->fail('questions', 'no_correct', $n);
        }

        return $out;
    }

    /** @param array<string, int> $n */
    private function image(mixed $url, array $n): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        // http(s) only, no whitespace or control characters anywhere: the
        // value lands in an <img src>, and a scheme check on a value with a
        // control character in it is a check that can be walked around.
        // A path on this forum (fof/upload's own files) is fine too: one
        // leading slash, not two, nor a backslash.
        if (mb_strlen($url) > 500 || preg_match('/[\x00-\x20\x7f]/', $url) || ! preg_match('#^(https?://[^/]|/[^/\\\\])#i', $url)) {
            $this->fail('questions', 'image_url', $n);
        }

        return $url;
    }

    private function badge(mixed $id): ?int
    {
        if (! is_numeric($id) || (int) $id <= 0 || ! $this->rewards->badgesAvailable()) {
            return null;
        }

        return $this->db->table('fof_badges')->where('id', (int) $id)->exists() ? (int) $id : null;
    }

    /** @param array<string, mixed> $data */
    private function int(array $data, string $key, int $min, int $max): ?int
    {
        $value = Arr::get($data, $key);
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc();
        } catch (\Throwable) {
            $this->fail('opensAt', 'dates');
        }
    }

    /** @param array<string, int|string> $params */
    private function fail(string $field, string $key, array $params = []): never
    {
        throw new ValidationException([$field => $this->translator->trans('ernestdefoe-brainstorm.api.'.$key, $params)]);
    }
}
