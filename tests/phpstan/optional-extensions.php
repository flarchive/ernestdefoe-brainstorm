<?php

// The parts of ernestdefoe/onramp and ernestdefoe/page-builder that Brainstorm uses, for PHPStan only.

namespace ErnestDefoe\Onramp\StepType {
    use Flarum\User\User;

    abstract class StepType
    {
        abstract public function key(): string;

        abstract public function icon(): string;

        /** @return array<class-string, callable(object): mixed> */
        abstract public function events(): array;

        /** @param array<string, mixed> $config */
        abstract public function progress(User $user, array $config): int;

        public function extension(): ?string
        {
            return null;
        }

        /** @return list<string> */
        public function fields(): array
        {
            return [];
        }
    }
}

namespace ErnestDefoe\Onramp\Extend {
    use Flarum\Extend\ExtenderInterface;
    use Flarum\Extension\Extension;
    use Illuminate\Contracts\Container\Container;

    class StepTypes implements ExtenderInterface
    {
        /** @param class-string $type */
        public function add(string $type): self
        {
            return $this;
        }

        public function extend(Container $container, ?Extension $extension = null): void
        {
        }
    }
}

namespace Ernestdefoe\PageBuilder\Block {
    use Flarum\User\User;

    interface BlockInterface
    {
        public function type(): string;

        public function name(): string;

        public function icon(): string;

        public function category(): string;

        /** @return array<string, mixed> */
        public function defaultSettings(): array;

        /** @return list<array<string, mixed>> */
        public function settingsSchema(): array;

        /**
         * @param array<string, mixed> $settings
         * @return array<string, mixed>
         */
        public function resolve(array $settings, User $actor): array;
    }
}

namespace Ernestdefoe\PageBuilder\Extend {
    use Flarum\Extend\ExtenderInterface;
    use Flarum\Extension\Extension;
    use Illuminate\Contracts\Container\Container;

    class PageBuilderBlock implements ExtenderInterface
    {
        public function __construct(protected string $blockClass)
        {
        }

        public function extend(Container $container, ?Extension $extension = null): void
        {
        }
    }
}
