<?php

namespace ErnestDefoe\Brainstorm\Assessment;

use Carbon\Carbon;
use ErnestDefoe\Brainstorm\Best;
use ErnestDefoe\Brainstorm\Event\AssessmentCompleted;
use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * After a counted attempt (practice never fires AttemptFinished), the
 * published assessments holding that quiz are checked for the member. The
 * first completion of each is recorded and announced. Four queries whatever
 * the number of assessments: the assessments, their items, the member's
 * bests and their earlier completions.
 */
class RecordCompletions
{
    public function __construct(private Dispatcher $events)
    {
    }

    public function handle(AttemptFinished $event): void
    {
        $user = $event->user;
        $assessments = Assessment::query()->where('status', 'published')
            ->whereIn('id', Item::query()->where('quiz_id', $event->attempt->quiz_id)->select('assessment_id'))
            ->get();

        if ($assessments->isEmpty()) {
            return;
        }

        // Filtered by id: an Eloquent collection's except() drops models by key and renumbers the rest.
        $done = Completion::query()->where('user_id', $user->id)->whereIn('assessment_id', $assessments->pluck('id')->all())->pluck('assessment_id')->all();
        $open = $assessments->reject(fn (Assessment $a) => in_array($a->id, $done, true));
        if ($open->isEmpty()) {
            return;
        }

        $items = Item::query()->whereIn('assessment_id', $open->pluck('id')->all())->get()->groupBy('assessment_id');
        $bests = Best::query()->where('user_id', $user->id)->whereIn('quiz_id', $items->flatten()->pluck('quiz_id')->unique()->all())
            ->pluck('percent', 'quiz_id')->map(fn ($p) => (float) $p)->all();

        foreach ($open as $assessment) {
            $standing = Progress::standing($assessment, $items->get($assessment->id, collect()), $bests);

            // Only the request that records it announces it, should two land at once.
            if ($standing['complete'] && Completion::query()->insertOrIgnore([
                    'assessment_id' => $assessment->id,
                    'user_id' => $user->id,
                    'percent' => $standing['percent'],
                    'completed_at' => Carbon::now(),
                ])) {
                $this->events->dispatch(new AssessmentCompleted($assessment, $user, (float) $standing['percent']));
            }
        }
    }
}
