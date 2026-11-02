import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import { quizUrl } from '../../common/api';
import type { Quiz, Result } from '../../common/api';
import { percent } from '../../common/format';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.card.${key}`, params);

/** "Best: 80%" with a tick once passed; empty before the first attempt. */
export function quizStanding(quiz: Quiz): any {
  const best = quiz.me?.best;
  if (!best) return <span className="BrainstormCard-standing" />;

  return (
    <span className={'BrainstormCard-standing' + (best.passed ? ' is-passed' : '')}>
      <Icon name={best.passed ? 'fas fa-circle-check' : 'far fa-circle'} />
      {t('best', { score: percent(best.percent) })}
    </span>
  );
}

/**
 * What the reader can do next: log in, start, resume, try again, or why not.
 * With `onplay` the quiz is played in place; without, the button goes to its page.
 */
export function quizAction(quiz: Quiz, onplay?: () => void): any {
  const me = quiz.me;

  if (!app.session.user) {
    return quiz.isOpen ? (
      <Button className="Button" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
        {t('log_in')}
      </Button>
    ) : null;
  }

  if (!quiz.canTake) {
    return <span className="BrainstormCard-note">{t(quiz.isOpen ? 'cannot_take' : 'closed')}</span>;
  }

  if (me && me.attemptsLeft === 0 && !me.openAttemptId && !quiz.canEdit) {
    return <span className="BrainstormCard-note">{t('no_attempts')}</span>;
  }

  const label = me?.openAttemptId ? 'resume' : me?.best ? 'again' : quiz.canEdit && quiz.status === 'draft' ? 'preview' : 'start';

  return onplay ? (
    <Button className="Button Button--primary" icon="fas fa-play" onclick={onplay}>
      {t(label)}
    </Button>
  ) : (
    <Link className="Button Button--primary" href={quizUrl(quiz)}>
      {t(label)}
    </Link>
  );
}

/** Keep a quiz's own standing in step with a finished attempt, without asking the server again. */
export function recordFinish(quiz: Quiz, result: Result): void {
  if (!quiz.me || result.practice) return;

  const best = quiz.me.best;
  quiz.attemptCount++;
  if (result.passed && !best?.passed) quiz.passCount++;

  quiz.me.openAttemptId = null;
  quiz.me.attemptsUsed = result.attemptsUsed;
  quiz.me.attemptsLeft = result.attemptsLeft;

  if (!best || result.percent > best.percent || (result.percent === best.percent && result.duration < best.duration)) {
    quiz.me.best = { attemptId: result.id, percent: result.percent, duration: result.duration, passed: result.passed || !!best?.passed };
  } else if (result.passed) {
    best.passed = true;
  }
}
