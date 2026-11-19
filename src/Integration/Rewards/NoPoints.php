<?php

namespace ErnestDefoe\Brainstorm\Integration\Rewards;

use Flarum\User\User;

/** FoF Gamification is not enabled: no points to pay. */
class NoPoints implements PointSource
{
    public function available(): bool
    {
        return false;
    }

    public function grant(User $user, string $key, int $points): void
    {
    }
}
