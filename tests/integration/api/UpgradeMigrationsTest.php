<?php

namespace ErnestDefoe\Brainstorm\Tests\integration\api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;

/** What upgrading a 1.1.x forum does to its data. */
class UpgradeMigrationsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-brainstorm');
        $this->prepareDatabase([User::class => [$this->normalUser(), ['id' => 3, 'username' => 'carol', 'email' => 'carol@machine.local', 'is_email_confirmed' => 1]]]);
    }

    #[Test]
    public function a_new_install_never_touches_users(): void
    {
        $this->assertFalse($this->database()->getSchemaBuilder()->hasColumn('users', 'quiz_private'));
    }

    #[Test]
    public function an_upgraded_forum_keeps_its_members_choices_up_down_and_up_again(): void
    {
        $schema = $this->database()->getSchemaBuilder();
        $move = require __DIR__.'/../../../migrations/2026_11_19_000001_move_quiz_private_off_users.php';

        // The forum as 1.1.x left it: carol had opted out.
        $schema->table('users', fn (Blueprint $table) => $table->boolean('quiz_private')->default(false));
        $this->database()->table('users')->where('id', 3)->update(['quiz_private' => true]);
        $optedOut = fn () => $this->database()->table('quiz_leaderboard_opt_outs')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        $move['up']($schema);
        $this->assertFalse($schema->hasColumn('users', 'quiz_private'));
        $this->assertSame([3], $optedOut());

        // Down: the column is back with carol's choice.
        $move['down']($schema);
        $this->assertTrue($schema->hasColumn('users', 'quiz_private'));
        $this->assertSame([3], $this->database()->table('users')->where('quiz_private', true)->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->database()->table('quiz_leaderboard_opt_outs')->delete();

        $move['up']($schema);
        $this->assertFalse($schema->hasColumn('users', 'quiz_private'));
        $this->assertSame([3], $optedOut());

        // Rolling back past 1.1's own migration takes the column away, as 1.1 did.
        $added = require __DIR__.'/../../../migrations/2026_10_20_000004_add_quiz_private_to_users.php';
        $move['down']($schema);
        $added['down']($schema);
        $this->assertFalse($schema->hasColumn('users', 'quiz_private'));
    }

    /**
     * MySQL commits a schema change on the spot, and with it the rows written
     * before it, so the test transaction can't undo them: tidy up by hand.
     */
    protected function tearDown(): void
    {
        $this->database()->table('quiz_leaderboard_opt_outs')->delete();
        if ($this->database()->getSchemaBuilder()->hasColumn('users', 'quiz_private')) {
            $this->database()->getSchemaBuilder()->table('users', fn (Blueprint $table) => $table->dropColumn('quiz_private'));
        }

        parent::tearDown();
    }

    #[Test]
    public function purging_an_upgraded_forum_rolls_every_migration_back_and_installing_again_works(): void
    {
        $c = $this->app()->getContainer();
        $migrator = $c->make(\Flarum\Database\Migrator::class);
        $extension = $c->make(\Flarum\Extension\ExtensionManager::class)->getExtension('ernestdefoe-brainstorm');
        $schema = $this->database()->getSchemaBuilder();
        $this->database()->table('quiz_leaderboard_opt_outs')->insert(['user_id' => 3]);

        try {
            $extension->migrate($migrator, 'down');

            $this->assertFalse($schema->hasTable('quizzes'));
            $this->assertFalse($schema->hasTable('quiz_leaderboard_opt_outs'));
            $this->assertFalse($schema->hasColumn('users', 'quiz_private'), 'users is left as it was before Brainstorm');
            $this->assertSame(0, $this->database()->table('migrations')->where('extension', 'ernestdefoe-brainstorm')->count());
        } finally {
            $extension->migrate($migrator, 'up');
        }

        $this->assertTrue($schema->hasTable('quiz_leaderboard_opt_outs'));
        $this->assertFalse($schema->hasColumn('users', 'quiz_private'));
    }

    #[Test]
    public function quizzes_written_before_the_search_table_are_found(): void
    {
        $this->database()->table('quizzes')->insert(['id' => 7, 'user_id' => 1, 'title' => 'Rivers & Lakes of Évora', 'slug' => 'rivers', 'status' => 'published', 'reveal' => 'answers']);
        $fill = require __DIR__.'/../../../migrations/2026_11_19_000003_fill_quiz_title_words.php';

        $fill['up']($this->database()->getSchemaBuilder());

        $words = $this->database()->table('quiz_title_words')->where('quiz_id', 7)->pluck('word')->all();
        sort($words);
        $this->assertSame(['lakes', 'of', 'rivers', 'évora'], $words);
    }
}
