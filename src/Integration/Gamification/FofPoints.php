<?php

namespace ErnestDefoe\Brainstorm\Integration\Gamification;

use ErnestDefoe\Brainstorm\Integration\Rewards\PointSource;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * FoF Gamification's points, through its point-grants seam (idempotent per
 * key). Bound by {@see GamificationProvider} while fof-gamification is enabled.
 *
 * The seam is newer than some fof/gamification 2.x releases, so it is looked
 * up by name: on an older release there is nothing to inject, and points are
 * simply not offered.
 */
class FofPoints implements PointSource
{
    public const POINT_GRANTS = 'FoF\Gamification\PointGrants';

    public function __construct(private Container $container)
    {
    }

    public function available(): bool
    {
        return class_exists(self::POINT_GRANTS);
    }

    public function grant(User $user, string $key, int $points): void
    {
        if ($points !== 0 && $this->available()) {
            $this->container->make(self::POINT_GRANTS)->grant($user, 'brainstorm', $key, $points);
        }
    }
}
