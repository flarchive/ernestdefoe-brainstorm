import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import CommentPost from 'flarum/forum/components/CommentPost';
import Switch from 'flarum/common/components/Switch';
import Button from 'flarum/common/components/Button';
import scan from './embed/scan';
import registerWidgets from './widgets';

/*
 * Everything here runs on every page for every visitor, so it is only the
 * wiring. The pages, the player, the editor and the in-post card are their
 * own chunks, fetched the first time something needs them.
 */
app.initializers.add('ernestdefoe-brainstorm', () => {
  app.routes['brainstorm.index'] = { path: '/quizzes', component: () => import('./pages/QuizzesPage') };
  app.routes['brainstorm.new'] = { path: '/quizzes/new', component: () => import('./pages/EditorPage') };
  app.routes['brainstorm.leaderboard'] = { path: '/quizzes/leaderboard', component: () => import('./pages/LeaderboardPage') };
  app.routes['brainstorm.quiz'] = { path: '/quizzes/:id', component: () => import('./pages/QuizPage') };
  app.routes['brainstorm.edit'] = { path: '/quizzes/:id/edit', component: () => import('./pages/EditorPage') };

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('canViewQuizzes') || app.forum.attribute('brainstormShowNav') === false) return;

    items.add(
      'quizzes',
      <LinkButton href={app.route('brainstorm.index')} icon="fas fa-graduation-cap">
        {app.translator.trans('ernestdefoe-brainstorm.forum.nav')}
      </LinkButton>,
      4
    );
  });

  // A quiz link on a line of its own in a post becomes the playable quiz.
  // onupdate too: an edited post replaces its content, links and all.
  extend(CommentPost.prototype, ['oncreate', 'onupdate'], function (this: CommentPost) {
    if (app.forum.attribute('canViewQuizzes')) scan(this.element);
  });

  // "Add quiz" in the discussion and reply composers. Composers are lazy in
  // Flarum 2, so they are extended by module name.
  ['flarum/forum/components/DiscussionComposer', 'flarum/forum/components/ReplyComposer'].forEach((path) =>
    extend(path, 'headerItems', function (this: any, items: any) {
      if (!app.forum.attribute('canViewQuizzes') || !app.session.user) return;

      items.add(
        'quiz',
        <Button
          className="Button Button--ua-reset ComposerBody-quiz"
          onclick={() => app.modal.show(() => import('./components/QuizPickerModal'), { composer: this.composer })}
        >
          <span className="QuizLabel">{app.translator.trans('ernestdefoe-brainstorm.forum.composer.add')}</span>
        </Button>,
        1
      );
    })
  );

  extend('flarum/forum/components/SettingsPage', 'privacyItems', function (this: any, items: any) {
    if (!app.forum.attribute('canViewQuizzes')) return;

    const user = this.user;

    items.add(
      'quizPrivate',
      <Switch
        state={!!user.attribute('quizPrivate')}
        loading={this.quizPrivateLoading}
        onchange={(value: boolean) => {
          this.quizPrivateLoading = true;
          user.save({ quizPrivate: value }).finally(() => {
            this.quizPrivateLoading = false;
            m.redraw();
          });
        }}
      >
        {app.translator.trans('ernestdefoe-brainstorm.forum.settings.private')}
      </Switch>
    );
  });

  registerWidgets();
});
