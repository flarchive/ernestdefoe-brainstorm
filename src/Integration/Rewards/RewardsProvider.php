<?php

namespace ErnestDefoe\Brainstorm\Integration\Rewards;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * "No rewards" unless an optional extension's provider bound its own source:
 * those are registered from extend.php inside Extend\Conditional, and bindIf
 * leaves them in place whichever provider runs first.
 */
class RewardsProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bindIf(BadgeSource::class, NoBadges::class);
        $this->container->bindIf(PointSource::class, NoPoints::class);
    }
}
