<?php

namespace ErnestDefoe\Brainstorm\Assessment;

use ErnestDefoe\Brainstorm\Best;
use ErnestDefoe\Brainstorm\Quiz;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * A member's standing at an assessment, from their best counted attempt at
 * each of its quizzes (practice runs never reach those, so never count).
 *
 * The overall result is the average of the quizzes they have finished, equal
 * or weighted. The assessment is complete once every required quiz is
 * finished and that average reaches its pass mark.
 */
class Progress
{
    /**
     * The standing, worked out from the items and the member's best percent
     * at each quiz they have finished.
     *
     * @param iterable<Item> $items
     * @param array<int, float> $bests best percent by quiz id
     * @return array{done: int, required: int, total: int, percent: float|null, complete: bool}
     */
    public static function standing(Assessment $assessment, iterable $items, array $bests): array
    {
        $done = $required = $requiredDone = $total = 0;
        $sum = $weights = 0.0;

        foreach ($items as $item) {
            $total++;
            $weight = $assessment->weighting === 'weighted' ? max(1, $item->weight) : 1;
            $finished = array_key_exists($item->quiz_id, $bests);

            if ($item->required) {
                $required++;
                $requiredDone += $finished ? 1 : 0;
            }
            if ($finished) {
                $done++;
                $sum += $bests[$item->quiz_id] * $weight;
                $weights += $weight;
            }
        }

        $percent = $weights > 0 ? round($sum / $weights, 2) : null;
        // All required quizzes finished (at least one quiz when none is required), and the average reached.
        $allDone = $required > 0 ? $requiredDone === $required : $done > 0;

        return [
            'done' => $done,
            'required' => $required,
            'total' => $total,
            'percent' => $percent,
            'complete' => $allDone && $percent !== null && $percent >= $assessment->pass_mark,
        ];
    }

    /**
     * The actor's progress and the quizzes they may see, onto each of a page
     * of assessments ({@see Assessment::$viewer}). Three queries whatever the
     * count: the items, the quizzes the actor may see, and the actor's bests.
     *
     * @param iterable<Assessment> $assessments
     */
    public function prime(iterable $assessments, User $actor): void
    {
        $assessments = new Collection(is_array($assessments) ? $assessments : iterator_to_array($assessments, false));
        if ($assessments->isEmpty()) {
            return;
        }

        $items = Item::query()->whereIn('assessment_id', $assessments->pluck('id')->all())->orderBy('position')->get()->groupBy('assessment_id');
        $quizIds = $items->flatten()->pluck('quiz_id')->unique()->values()->all();
        $quizzes = $quizIds ? Quiz::query()->whereVisibleTo($actor)->whereIn('quizzes.id', $quizIds)->get(['quizzes.id', 'title', 'slug', 'status'])->keyBy('id') : collect();
        $bests = $quizIds && ! $actor->isGuest()
            ? Best::query()->where('user_id', $actor->id)->whereIn('quiz_id', $quizIds)->get()->keyBy('quiz_id')
            : collect();

        $percents = $bests->map(fn (Best $b) => $b->percent)->all();

        foreach ($assessments as $assessment) {
            $mine = $items->get($assessment->id, new \Illuminate\Database\Eloquent\Collection());
            // The editor's copy reads the same list: no second query for it.
            $assessment->setRelation('items', $mine);

            $assessment->viewer = [
                'me' => $actor->isGuest() ? null : self::standing($assessment, $mine, $percents),
                'quizzes' => $mine->map(fn (Item $item) => $this->child($item, $quizzes->get($item->quiz_id), $bests->get($item->quiz_id)))->values()->all(),
            ];
        }
    }

    /**
     * One quiz of the assessment as the actor sees it. A quiz they may not
     * see is listed only as locked: neither its title nor their score.
     *
     * @return array<string, mixed>
     */
    private function child(Item $item, ?Quiz $quiz, ?Best $best): array
    {
        $shared = ['weight' => $item->weight, 'required' => $item->required];

        if (! $quiz) {
            return ['id' => null, 'locked' => true] + $shared;
        }

        return [
            'id' => $quiz->id,
            'locked' => false,
            'title' => $quiz->title,
            'slug' => $quiz->slug,
            'best' => $best ? ['percent' => $best->percent, 'passed' => $best->passed] : null,
        ] + $shared;
    }
}
