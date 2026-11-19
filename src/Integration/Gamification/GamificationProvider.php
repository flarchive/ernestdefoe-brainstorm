<?php

namespace ErnestDefoe\Brainstorm\Integration\Gamification;

use ErnestDefoe\Brainstorm\Integration\Rewards\PointSource;
use Flarum\Foundation\AbstractServiceProvider;

/** Registered from extend.php only when fof-gamification is enabled. */
class GamificationProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(PointSource::class, FofPoints::class);
    }
}
