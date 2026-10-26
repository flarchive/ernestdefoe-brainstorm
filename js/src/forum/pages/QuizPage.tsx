import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import extractText from 'flarum/common/utils/extractText';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import { deleteQuiz, showQuiz } from '../../common/api';
import type { Quiz, Result } from '../../common/api';
import { quizAction, quizStanding, recordFinish } from '../components/QuizActions';
import quizFacts from '../components/QuizFacts';
import Player from '../components/Player';
import Leaderboard from '../components/Leaderboard';
import ResultsPanel from '../components/ResultsPanel';
import personLink from '../components/PersonLink';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.${key}`, params);

/** /quizzes/12-capitals — the quiz, played on the page, with its leaderboard. */
export default class QuizPage extends Page {
  quiz: Quiz | null = null;
  missing = false;
  playing = false;
  /** Bumped to remount the leaderboard after a finished attempt. */
  boardKey = 0;
  tab: 'board' | 'results' = 'board';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const id = parseInt(m.route.param('id'), 10);
    showQuiz(id).then(
      ({ data }) => {
        this.quiz = data;
        app.setTitle(data.title);
        app.history.push('quiz', data.title);
        m.redraw();
      },
      () => {
        this.missing = true;
        m.redraw();
      }
    );
  }

  view(): Mithril.Children {
    return (
      <PageStructure className="BrainstormQuizPage" sidebar={() => <IndexSidebar />} loading={!this.quiz && !this.missing}>
        {this.missing ? <p className="BrainstormEmpty">{t('quiz.missing')}</p> : this.quiz ? this.content(this.quiz) : null}
      </PageStructure>
    );
  }

  content(quiz: Quiz): Mithril.Children {
    return (
      <div className="BrainstormQuizPage-layout">
        <div className="BrainstormQuizPage-main">
          <LinkButton className="Button Button--link BrainstormQuizPage-back" icon="fas fa-arrow-left" href={app.route('brainstorm.index')}>
            {t('quiz.all')}
          </LinkButton>
          <h2 className="BrainstormQuizPage-title">
            {quiz.title}
            {quiz.status === 'draft' ? <span className="BrainstormChip BrainstormChip--draft">{t('draft')}</span> : null}
          </h2>
          <div className="BrainstormQuizPage-byline">
            {quiz.author ? personLink(quiz.author) : null}
            {quiz.publishedAt ? humanTime(new Date(quiz.publishedAt)) : null}
            <span>{t('quiz.stats', { taken: quiz.attemptCount, passed: quiz.passCount })}</span>
          </div>
          {quizFacts(quiz)}
          {this.windowNote(quiz)}
          {quiz.descriptionHtml ? <div className="BrainstormQuizPage-description Post-body">{m.trust(quiz.descriptionHtml)}</div> : null}

          {quiz.canEdit ? (
            <div className="BrainstormQuizPage-tools">
              <LinkButton className="Button" icon="fas fa-pen" href={app.route('brainstorm.edit', { id: quiz.id })}>
                {t('quiz.edit')}
              </LinkButton>
              <Button className="Button" icon="far fa-copy" onclick={() => this.copyLink(quiz)}>
                {t('quiz.copy_link')}
              </Button>
              <Button className="Button Button--danger" icon="fas fa-trash" onclick={() => this.remove(quiz)}>
                {t('quiz.delete')}
              </Button>
            </div>
          ) : null}

          {this.playing ? (
            <div className="BrainstormQuizPage-play BrainstormCard is-playing">
              <Player
                quiz={quiz}
                onfinish={(result: Result) => {
                  recordFinish(quiz, result);
                  this.boardKey++;
                }}
                onclose={() => {
                  this.playing = false;
                }}
              />
            </div>
          ) : (
            <div className="BrainstormCard-footer BrainstormQuizPage-cta">
              {quizStanding(quiz)}
              {quizAction(quiz, () => (this.playing = true))}
            </div>
          )}
        </div>

        <aside className="BrainstormQuizPage-side">
          {quiz.canEdit ? (
            <div className="BrainstormTabs" role="tablist">
              {(['board', 'results'] as const).map((tab) => (
                <Button
                  className={'Button Button--flat' + (this.tab === tab ? ' active' : '')}
                  role="tab"
                  aria-selected={this.tab === tab}
                  onclick={() => (this.tab = tab)}
                >
                  {t('quiz.tabs.' + tab)}
                </Button>
              ))}
            </div>
          ) : (
            <h3>{t('leaderboard.title')}</h3>
          )}
          {this.tab === 'results' && quiz.canEdit ? (
            <ResultsPanel quiz={quiz} />
          ) : quiz.leaderboard ? (
            [<Leaderboard key={this.boardKey} quizId={quiz.id} />]
          ) : (
            <p className="BrainstormBoard-empty">{t('leaderboard.off')}</p>
          )}
        </aside>
      </div>
    );
  }

  windowNote(quiz: Quiz): Mithril.Children {
    const now = Date.now();
    if (quiz.opensAt && Date.parse(quiz.opensAt) > now)
      return <p className="BrainstormQuizPage-window">{t('quiz.opens', { time: humanTime(new Date(quiz.opensAt)) })}</p>;
    if (quiz.closesAt && Date.parse(quiz.closesAt) <= now) return <p className="BrainstormQuizPage-window">{t('quiz.closed')}</p>;
    if (quiz.closesAt) return <p className="BrainstormQuizPage-window">{t('quiz.closes', { time: humanTime(new Date(quiz.closesAt)) })}</p>;
    return null;
  }

  copyLink(quiz: Quiz) {
    const url = app.forum.attribute<string>('baseUrl') + '/quizzes/' + quiz.id + (quiz.slug ? '-' + quiz.slug : '');
    navigator.clipboard?.writeText(url).then(() => app.alerts.show({ type: 'success' }, t('quiz.link_copied')));
  }

  remove(quiz: Quiz) {
    if (!confirm(extractText(t('quiz.delete_confirm', { title: quiz.title })))) return;

    deleteQuiz(quiz.id).then(() => m.route.set(app.route('brainstorm.index')));
  }
}
