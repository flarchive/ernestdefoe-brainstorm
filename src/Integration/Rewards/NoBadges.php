<?php

namespace ErnestDefoe\Brainstorm\Integration\Rewards;

use Flarum\User\User;

/** FoF Badges is not enabled: no badges to attach or pay. */
class NoBadges implements BadgeSource
{
    public function available(): bool
    {
        return false;
    }

    public function all(): array
    {
        return [];
    }

    public function visible(array $ids): array
    {
        return [];
    }

    public function exists(int $id): bool
    {
        return false;
    }

    public function award(User $user, int $id): void
    {
    }
}
