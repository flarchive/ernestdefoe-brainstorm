<?php

namespace ErnestDefoe\Brainstorm;

use Flarum\Formatter\Formatter;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use s9e\TextFormatter\Utils;

/**
 * A quiz's description, its questions and their explanations go through the
 * forum's own formatter, the one posts use: whatever the forum's editor writes
 * (Markdown, BBCode, Scribe's HTML) is parsed on save into s9e XML and
 * rendered to safe HTML on read. Nothing a member typed reaches the page any
 * other way.
 */
class RichText
{
    public function __construct(protected Formatter $formatter)
    {
    }

    /** Parse what was typed into the XML that is stored; null when empty. */
    public function store(string $source, Quiz $context, User $actor, int $max = 20000): ?string
    {
        $source = trim(mb_substr($source, 0, $max));

        return $source === '' ? null : $this->formatter->parse($source, $context, $actor);
    }

    /** The stored XML as safe HTML, or null. */
    public function html(?string $stored, Quiz $context): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return $this->formatter->render($stored, $context);
        } catch (\Throwable) {
            // Never let a formatter hiccup take the page down: show the text.
            return '<p>'.nl2br(e(strip_tags($stored))).'</p>';
        }
    }

    /**
     * flarum/mentions looks up the members, posts, groups and tags a text
     * mentions when it is rendered: one query per text, unless its context
     * already carries them. Rendering a page of quizzes, or a paper of
     * questions, is many texts — so every mention in all of them is looked
     * up here, once per kind, and handed to each quiz. Nothing is queried
     * when nothing is mentioned.
     *
     * @param iterable<Quiz> $quizzes
     * @param iterable<string|null> $texts the stored XML about to be rendered
     */
    public function prime(iterable $quizzes, iterable $texts): void
    {
        $kinds = [
            'USERMENTION' => ['mentionsUsers', User::class, []],
            'POSTMENTION' => ['mentionsPosts', 'Flarum\Post\Post', ['user', 'discussion']],
            'GROUPMENTION' => ['mentionsGroups', 'Flarum\Group\Group', []],
            'TAGMENTION' => ['mentionsTags', 'Flarum\Tags\Tag', []],
        ];

        $ids = [];
        foreach ($texts as $xml) {
            if ($xml !== null && str_contains($xml, 'MENTION')) {
                foreach ($kinds as $tag => $kind) {
                    foreach (Utils::getAttributeValues($xml, $tag, 'id') as $id) {
                        $ids[$tag][$id] = true;
                    }
                }
            }
        }

        foreach ($ids as $tag => $found) {
            [$relation, $class, $with] = $kinds[$tag];
            if (! class_exists($class)) {
                continue;
            }

            /** @var Collection<int, \Flarum\Database\AbstractModel> $models */
            $models = $class::query()->with($with)->findMany(array_keys($found));

            foreach ($quizzes as $quiz) {
                $quiz->setRelation($relation, $models);
            }
        }
    }

    /** What the editor is filled with when the quiz is edited. */
    public function source(?string $stored, Quiz $context): string
    {
        return $stored === null || $stored === '' ? '' : (string) $this->formatter->unparse($stored, $context);
    }
}
