<?php

namespace ErnestDefoe\Brainstorm\Integration\Rewards;

use Flarum\User\User;

/**
 * Points a quiz can pay out. FoF Gamification's implementation is bound only
 * while fof-gamification is enabled; otherwise {@see NoPoints} says there are none.
 */
interface PointSource
{
    public function available(): bool;

    /** Idempotent: one grant per member per key, however often it is asked for. */
    public function grant(User $user, string $key, int $points): void;
}
