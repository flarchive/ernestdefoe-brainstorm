<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\Extension\ExtensionManager;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * Paying out for a first pass: FoF Gamification points and a FoF Badges badge.
 *
 * Both are idempotent on their own side — a point grant is keyed by the quiz,
 * a badge is held once — so a reward can't be paid twice, even after an
 * admin resets a member's attempts and they pass again.
 *
 * 🚨 Both FoF extensions are optional and resolved by NAME when paying.
 * Nothing here imports them, so Brainstorm loads the same with either, both or
 * neither installed.
 */
class Rewards
{
    /** fof/gamification's point-grants seam; absent from versions before it. */
    public const POINT_GRANTS = 'FoF\Gamification\PointGrants';

    public function __construct(private ExtensionManager $extensions, private Container $container)
    {
    }

    public function pointsAvailable(): bool
    {
        return $this->extensions->isEnabled('fof-gamification') && class_exists(self::POINT_GRANTS);
    }

    public function badgesAvailable(): bool
    {
        return $this->extensions->isEnabled('fof-badges') && class_exists('FoF\Badges\Badge');
    }

    public function pay(Quiz $quiz, User $user): void
    {
        // The author wrote the answer key; passing their own quiz earns nothing.
        if ($quiz->user_id === $user->id) {
            return;
        }

        if ($quiz->reward_points !== 0 && $this->pointsAvailable()) {
            $this->container->make(self::POINT_GRANTS)->grant($user, 'brainstorm', 'quiz:'.$quiz->id, $quiz->reward_points);
        }

        if ($quiz->reward_badge_id && $this->badgesAvailable()) {
            $badge = \FoF\Badges\Badge::query()->find($quiz->reward_badge_id);

            if ($badge) {
                $this->container->make('FoF\Badges\BadgeAwarder')->award($user, $badge, \FoF\Badges\UserBadge::GRANTED_BY_SYSTEM);
            }
        }
    }
}
