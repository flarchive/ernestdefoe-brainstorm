<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * The mentions {@see RichText::prime()} looks up in bulk: the XML tag
 * flarum/mentions writes, the relation it reads from the render context, the
 * model, and what to load with it. Core's kinds are here; Tags adds its own
 * from extend.php, only while flarum-tags is enabled.
 */
class MentionKinds
{
    /** @var array<string, array{0: string, 1: class-string<AbstractModel>, 2: list<string>}> */
    private array $kinds = [
        'USERMENTION' => ['mentionsUsers', User::class, []],
        'POSTMENTION' => ['mentionsPosts', Post::class, ['user', 'discussion']],
        'GROUPMENTION' => ['mentionsGroups', Group::class, []],
    ];

    /**
     * @param class-string<AbstractModel> $model
     * @param list<string> $with
     */
    public function add(string $tag, string $relation, string $model, array $with = []): self
    {
        $this->kinds[$tag] = [$relation, $model, $with];

        return $this;
    }

    /** @return array<string, array{0: string, 1: class-string<AbstractModel>, 2: list<string>}> */
    public function all(): array
    {
        return $this->kinds;
    }
}
