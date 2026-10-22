import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { leaderboard } from '../../common/api';
import type { Row, Standing } from '../../common/api';
import { clock, percent } from '../../common/format';
import personLink from './PersonLink';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.leaderboard.${key}`, params);

interface Attrs {
  /** One quiz's board; without it, the board across every quiz. */
  quizId?: number;
  limit?: number;
  compact?: boolean;
}

/**
 * A leaderboard. The server leaves out members who chose to stay off them and
 * members this reader may not see; the reader's own standing comes back
 * separately, so "you" shows even when it is off the top of the board.
 */
export default class Leaderboard extends Component<Attrs> {
  rows: Row[] | null = null;
  me: Standing | null = null;
  off = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    leaderboard(this.attrs.quizId, this.attrs.limit).then(
      ({ data, meta }) => {
        this.rows = data;
        this.me = meta.me;
        this.off = !!meta.off;
        m.redraw();
      },
      () => {
        this.rows = [];
        m.redraw();
      }
    );
  }

  view(): Mithril.Children {
    if (!this.rows) return <LoadingIndicator display="block" size="small" />;
    if (this.off) return null;

    const perQuiz = !!this.attrs.quizId;
    const myId = app.session.user?.id();

    return (
      <div className={'BrainstormBoard' + (this.attrs.compact ? ' BrainstormBoard--compact' : '')}>
        {this.rows.length ? (
          <ol className="BrainstormBoard-list">
            {this.rows.map((row) => (
              <li
                className={
                  'BrainstormBoard-row' + (String(row.user.id) === myId ? ' is-me' : '') + (row.rank <= 3 ? ' is-top is-top-' + row.rank : '')
                }
              >
                <span className="BrainstormBoard-rank">{row.rank}</span>
                {personLink(row.user)}
                <span className="BrainstormBoard-value">{perQuiz ? percent(row.percent!) : t('passed', { count: row.passed })}</span>
                {this.attrs.compact ? null : (
                  <span className="BrainstormBoard-extra">{perQuiz ? clock(row.duration!) : t('average', { score: percent(row.average!) })}</span>
                )}
              </li>
            ))}
          </ol>
        ) : (
          <p className="BrainstormBoard-empty">{t('empty')}</p>
        )}
        {this.me && !this.rows.some((row) => String(row.user.id) === myId) ? (
          <p className="BrainstormBoard-me">
            {t(perQuiz ? 'you_quiz' : 'you_overall', {
              rank: this.me.rank,
              score: perQuiz ? percent(this.me.percent!) : '',
              count: perQuiz ? 0 : (this.me.passed as number),
            })}
          </p>
        ) : null}
      </div>
    );
  }
}
