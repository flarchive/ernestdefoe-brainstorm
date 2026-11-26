<?php

namespace ErnestDefoe\Brainstorm\Integration\Badges;

use ErnestDefoe\Brainstorm\Integration\Rewards\BadgeSource;
use Flarum\User\User;
use FoF\Badges\Badge;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\UserBadge;

/** FoF Badges' badges, through its own model and awarder. Bound by {@see BadgesProvider}. */
class FofBadges implements BadgeSource
{
    /** The editor's picker lists at most this many. */
    public const PICKER = 500;

    public function __construct(private BadgeAwarder $awarder)
    {
    }

    public function available(): bool
    {
        return true;
    }

    public function all(): array
    {
        return Badge::query()->orderBy('name')->limit(self::PICKER)->get()
            ->map(fn (Badge $b) => ['id' => (int) $b->id] + $this->present($b))->values()->all();
    }

    public function visible(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $out = [];
        foreach (Badge::query()->whereIn('id', $ids)->where('is_visible', true)->get() as $b) {
            $out[(int) $b->id] = $this->present($b);
        }

        return $out;
    }

    public function exists(int $id): bool
    {
        return Badge::query()->whereKey($id)->exists();
    }

    public function award(User $user, int $id): void
    {
        if ($badge = Badge::query()->find($id)) {
            $this->awarder->award($user, $badge, UserBadge::GRANTED_BY_SYSTEM);
        }
    }

    /** @return array{name: string, icon: string, color: string, background: string} */
    private function present(Badge $b): array
    {
        return [
            'name' => (string) $b->name,
            'icon' => (string) $b->icon,
            'color' => (string) $b->icon_color,
            'background' => (string) $b->background_color,
        ];
    }
}
