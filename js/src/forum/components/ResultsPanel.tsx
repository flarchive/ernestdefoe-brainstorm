import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import { resetMember, results } from '../../common/api';
import type { Person, Quiz, Result } from '../../common/api';
import { clock, percent } from '../../common/format';
import personLink from './PersonLink';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.results.${key}`, params);

type Row = Result & { user: Person | null; submittedAt: string };

/**
 * For the quiz's author and managers: every finished attempt, and how often
 * each question is answered right — the question everyone misses is the one
 * to look at again.
 */
export default class ResultsPanel extends Component<{ quiz: Quiz }> {
  rows: Row[] = [];
  stats: { id: number; text: string; asked: number; correct: number }[] = [];
  next: number | null = null;
  loading = true;

  oninit(vnode: Mithril.Vnode<{ quiz: Quiz }, this>) {
    super.oninit(vnode);
    this.load(0);
  }

  load(offset: number) {
    this.loading = true;
    results(this.attrs.quiz.id, offset).then(
      ({ data, meta }) => {
        this.rows = offset ? [...this.rows, ...data] : data;
        if (meta.questions) this.stats = meta.questions;
        this.next = meta.next;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  reset(user: Person) {
    if (!confirm(extractText(t('reset_confirm', { name: user.displayName })))) return;

    resetMember(this.attrs.quiz.id, user.id).then(() => this.load(0));
  }

  view(): Mithril.Children {
    return (
      <div className="BrainstormResults">
        {this.stats.length ? (
          <section>
            <h4>{t('questions')}</h4>
            <ol className="BrainstormResults-questions">
              {this.stats.map((s) => (
                <li>
                  <span className="BrainstormResults-question">{s.text}</span>
                  <span className="BrainstormResults-bar" title={extractText(t('asked', { count: s.asked }))}>
                    <span style={{ width: s.correct + '%' }} />
                  </span>
                  <span className="BrainstormResults-value">{s.asked ? percent(s.correct) : '—'}</span>
                </li>
              ))}
            </ol>
          </section>
        ) : null}

        <section>
          <h4>{t('attempts')}</h4>
          {this.rows.length ? (
            <ul className="BrainstormResults-attempts">
              {this.rows.map((r) => (
                <li className={r.passed ? 'is-passed' : ''}>
                  {r.user ? personLink(r.user) : <span>{t('deleted_user')}</span>}
                  <span className="BrainstormResults-value">{percent(r.percent)}</span>
                  <span className="BrainstormResults-meta">
                    {clock(r.duration)} · {humanTime(new Date(r.submittedAt))}
                    {r.practice ? ' · ' + extractText(t('practice')) : ''}
                    {r.expired ? ' · ' + extractText(t('expired')) : ''}
                  </span>
                  {r.user ? (
                    <Button
                      className="Button Button--icon Button--link"
                      icon="fas fa-rotate-left"
                      aria-label={extractText(t('reset'))}
                      title={extractText(t('reset'))}
                      onclick={() => this.reset(r.user!)}
                    />
                  ) : null}
                </li>
              ))}
            </ul>
          ) : !this.loading ? (
            <p className="BrainstormBoard-empty">{t('none')}</p>
          ) : null}
          {this.loading ? <LoadingIndicator display="block" size="small" /> : null}
          {!this.loading && this.next ? (
            <Button className="Button" onclick={() => this.load(this.next!)}>
              {t('more')}
            </Button>
          ) : null}
        </section>
      </div>
    );
  }
}
