<?php

namespace ErnestDefoe\Brainstorm\Event;

use ErnestDefoe\Brainstorm\Attempt;
use Flarum\User\User;

/**
 * An attempt was marked, by the player or by the clock. `firstPass` is true
 * the one time a member passes a quiz they had not passed before — what
 * rewards, badges and Onramp listen for.
 */
class AttemptFinished
{
    public function __construct(public Attempt $attempt, public User $user, public bool $firstPass)
    {
    }
}
