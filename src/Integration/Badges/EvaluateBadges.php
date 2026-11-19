<?php

namespace ErnestDefoe\Brainstorm\Integration\Badges;

use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\Trigger\TriggerEvaluator;

/**
 * After a counted attempt, the badges whose triggers use Brainstorm's metrics.
 *
 * 🚨 FoF Badges' own listener only recognises the events it knows, so
 * Brainstorm asks the evaluator itself — the same two calls that listener makes.
 *
 * Registered from extend.php only when fof-badges is enabled.
 */
class EvaluateBadges
{
    public function __construct(private TriggerEvaluator $evaluator, private BadgeAwarder $awarder)
    {
    }

    public function handle(AttemptFinished $event): void
    {
        foreach ($this->evaluator->evaluateForEvent($event->user, AttemptFinished::class) as $badge) {
            $this->awarder->award($event->user, $badge);
        }
    }
}
