<?php

namespace ErnestDefoe\Brainstorm;

use ErnestDefoe\Brainstorm\Integration\Rewards\BadgeSource;
use ErnestDefoe\Brainstorm\Integration\Rewards\PointSource;
use Flarum\User\User;

/**
 * Paying out for a first pass: FoF Gamification points and a FoF Badges badge.
 *
 * Both are idempotent on their own side — a point grant is keyed by the quiz,
 * a badge is held once — so a reward can't be paid twice, even after an
 * admin resets a member's attempts and they pass again.
 *
 * Both FoF extensions are optional: each source is bound by a provider that
 * extend.php registers only while that extension is enabled, and is "none"
 * otherwise. Nothing here names a FoF class.
 */
class Rewards
{
    public function __construct(private BadgeSource $badges, private PointSource $points)
    {
    }

    public function pointsAvailable(): bool
    {
        return $this->points->available();
    }

    public function badgesAvailable(): bool
    {
        return $this->badges->available();
    }

    public function badges(): BadgeSource
    {
        return $this->badges;
    }

    /**
     * What the quiz editor may offer this actor.
     *
     * @return array{canReward: bool, points: bool, badges: bool, badgeList: list<array<string, mixed>>}
     */
    public function editorOptions(User $actor): array
    {
        $canReward = Access::canReward($actor);
        $badges = $canReward && $this->badges->available();

        return [
            'canReward' => $canReward,
            'points' => $canReward && $this->points->available(),
            'badges' => $badges,
            'badgeList' => $badges ? $this->badges->all() : [],
        ];
    }

    public function pay(Quiz $quiz, User $user): void
    {
        // The author wrote the answer key; passing their own quiz earns nothing.
        if ($quiz->user_id === $user->id) {
            return;
        }

        $this->points->grant($user, 'quiz:'.$quiz->id, $quiz->reward_points);

        if ($quiz->reward_badge_id) {
            $this->badges->award($user, $quiz->reward_badge_id);
        }
    }
}
