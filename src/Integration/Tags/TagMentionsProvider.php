<?php

namespace ErnestDefoe\Brainstorm\Integration\Tags;

use ErnestDefoe\Brainstorm\MentionKinds;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Tags\Tag;

/** Tag mentions in quiz texts, looked up in bulk. Registered from extend.php only when flarum-tags is enabled. */
class TagMentionsProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(MentionKinds::class, fn (MentionKinds $kinds) => $kinds->add('TAGMENTION', 'mentionsTags', Tag::class));
    }
}
