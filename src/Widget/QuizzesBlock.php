<?php

namespace ErnestDefoe\Brainstorm\Widget;

use Ernestdefoe\PageBuilder\Block\BlockInterface;
use Flarum\User\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Brainstorm's widget as a Page Builder block: the top quiz takers, or the
 * newest quizzes.
 *
 * The block resolves nothing on the server: its browser half asks the same
 * API the quizzes page uses, which already applies the reader's visibility
 * and every member's leaderboard choice. One gate, not two.
 *
 * Registered from extend.php only when Page Builder is enabled.
 */
class QuizzesBlock implements BlockInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    private function t(string $key): string
    {
        return $this->translator->trans('ernestdefoe-brainstorm.forum.widget.'.$key);
    }

    public function type(): string
    {
        return 'brainstorm-quizzes';
    }

    public function name(): string
    {
        return $this->t('name');
    }

    public function icon(): string
    {
        return 'fas fa-graduation-cap';
    }

    public function category(): string
    {
        return 'forum';
    }

    public function defaultSettings(): array
    {
        return ['show' => 'leaderboard', 'limit' => 5];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'show',
                'type' => 'select',
                'label' => $this->t('show'),
                'default' => 'leaderboard',
                'options' => [
                    ['value' => 'leaderboard', 'label' => $this->t('show_leaderboard')],
                    ['value' => 'latest', 'label' => $this->t('show_latest')],
                ],
            ],
            [
                'key' => 'limit',
                'type' => 'number',
                'label' => $this->t('limit'),
                'default' => 5,
            ],
        ];
    }

    public function resolve(array $settings, User $actor): array
    {
        return [];
    }
}
