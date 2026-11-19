<?php

namespace ErnestDefoe\Brainstorm\Integration\Rewards;

use Flarum\User\User;

/**
 * The badges a quiz can pay out. FoF Badges' implementation is bound only
 * while fof-badges is enabled; otherwise {@see NoBadges} says there are none.
 */
interface BadgeSource
{
    public function available(): bool;

    /**
     * Every badge an editor may attach, for the quiz editor's picker.
     *
     * @return list<array{id: int, name: string, icon: string, color: string, background: string}>
     */
    public function all(): array;

    /**
     * The visible ones among these ids, by id, for quiz cards.
     *
     * @param list<int> $ids
     * @return array<int, array{name: string, icon: string, color: string, background: string}>
     */
    public function visible(array $ids): array;

    public function exists(int $id): bool;

    public function award(User $user, int $id): void;
}
