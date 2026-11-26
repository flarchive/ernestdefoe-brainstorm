<?php

namespace ErnestDefoe\Brainstorm\Listener;

use ErnestDefoe\Brainstorm\Event\AttemptFinished;
use ErnestDefoe\Brainstorm\Rewards;

/** A first pass pays the quiz's reward. */
class PayRewards
{
    public function __construct(private Rewards $rewards)
    {
    }

    public function handle(AttemptFinished $event): void
    {
        if ($event->firstPass) {
            $this->rewards->pay($event->attempt->quiz, $event->user);
        }
    }
}
