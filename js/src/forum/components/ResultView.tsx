import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type { Quiz, Result, ReviewQuestion } from '../../common/api';
import { clock, percent } from '../../common/format';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.result.${key}`, params);

interface Attrs {
  result: Result;
  quiz: Quiz;
  onretry?: () => void;
  onclose?: () => void;
}

/** A marked attempt: the score, pass or not, and as much of the marking as the quiz shows. */
export default class ResultView extends Component<Attrs> {
  view(): Mithril.Children {
    const r = this.attrs.result;
    const canRetry = this.attrs.onretry && this.attrs.quiz.canTake && (r.attemptsLeft === null || r.attemptsLeft > 0 || r.practice);
    const ring = Math.max(0, Math.min(100, r.percent));

    return (
      <div className={'BrainstormResult' + (r.passed ? ' is-passed' : ' is-failed')}>
        <div className="BrainstormResult-summary">
          <div className="BrainstormResult-ring" style={{ '--brainstorm-ring': ring + '%' }} aria-hidden="true">
            <span>{percent(r.percent)}</span>
          </div>
          <div className="BrainstormResult-text">
            <h4 className="BrainstormResult-headline">
              <Icon name={r.passed ? 'fas fa-circle-check' : 'fas fa-circle-xmark'} />
              {t(r.passed ? 'passed' : 'not_passed')}
            </h4>
            <p>
              {t('score', { score: r.score, max: r.maxScore, percent: percent(r.percent), mark: r.passMark })}
              {' · '}
              {t('time', { time: clock(r.duration) })}
            </p>
            {r.expired ? <p className="BrainstormResult-note">{t('expired')}</p> : null}
            {r.practice ? <p className="BrainstormResult-note">{t('practice')}</p> : null}
            {r.attemptsLeft !== null && !r.practice ? <p className="BrainstormResult-note">{t('attempts_left', { count: r.attemptsLeft })}</p> : null}
            <div className="BrainstormResult-actions">
              {canRetry ? (
                <Button className="Button Button--primary" icon="fas fa-rotate-right" onclick={() => this.attrs.onretry!()}>
                  {t('again')}
                </Button>
              ) : null}
              {this.attrs.onclose ? (
                <Button className="Button" onclick={() => this.attrs.onclose!()}>
                  {t('close')}
                </Button>
              ) : null}
            </div>
          </div>
        </div>

        {r.review ? <ol className="BrainstormReview">{r.review.map((q, i) => this.question(q, i))}</ol> : null}
        {r.review && r.answersLater ? <p className="BrainstormResult-note">{t('key_hidden')}</p> : null}
      </div>
    );
  }

  question(q: ReviewQuestion, i: number): Mithril.Children {
    const right = q.earned >= q.points;
    const part = !right && q.earned > 0;
    const state = right ? 'right' : part ? 'partial' : 'wrong';

    return (
      <li className={'BrainstormReview-item is-' + state} key={q.id}>
        <div className="BrainstormReview-head">
          <span className="BrainstormReview-number">{i + 1}</span>
          <span className="BrainstormReview-state">
            <Icon name={right ? 'fas fa-check' : part ? 'fas fa-circle-half-stroke' : 'fas fa-xmark'} />
            {t(state, { earned: q.earned, points: q.points })}
          </span>
        </div>
        <div className="BrainstormReview-body Post-body">{q.bodyHtml ? m.trust(q.bodyHtml) : null}</div>
        {q.imageUrl ? (
          <img className="BrainstormQuestion-image" src={q.imageUrl} alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" />
        ) : null}

        {q.type === 'text' ? (
          <div className="BrainstormReview-typed">
            <span>{t('you_typed')}</span> <strong>{q.typed ?? t('no_answer')}</strong>
            {q.accepted ? (
              <div>
                <span>{t('accepted')}</span> <strong>{q.accepted.join(' · ')}</strong>
              </div>
            ) : null}
          </div>
        ) : (
          <ul className="BrainstormReview-options">
            {q.options.map((o) => (
              <li
                className={
                  'BrainstormReview-option' +
                  (o.chosen ? ' is-chosen' : '') +
                  (o.correct === true ? ' is-correct' : '') +
                  (o.correct === false && o.chosen ? ' is-wrong' : '')
                }
              >
                <Icon name={o.correct ? 'fas fa-check' : o.chosen ? (o.correct === false ? 'fas fa-xmark' : 'fas fa-circle-dot') : 'far fa-circle'} />
                <span>
                  {q.type === 'boolean' ? app.translator.trans(`ernestdefoe-brainstorm.forum.player.${o.id === 'true' ? 'true' : 'false'}`) : o.text}
                </span>
                {o.chosen ? <span className="BrainstormReview-yours">{t('your_answer')}</span> : null}
              </li>
            ))}
          </ul>
        )}

        {q.explanationHtml ? <div className="BrainstormReview-explanation Post-body">{m.trust(q.explanationHtml)}</div> : null}
      </li>
    );
  }
}
