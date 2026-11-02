import app from 'flarum/forum/app';
import Icon from 'flarum/common/components/Icon';
import type { Quiz } from '../../common/api';
import { span } from '../../common/format';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.facts.${key}`, params);

/** One line of what a quiz asks of you: questions, time, pass mark, attempts, rewards. */
export default function quizFacts(quiz: Quiz): any {
  const me = quiz.me;
  const facts = [
    <span>
      <Icon name="far fa-circle-question" />
      {t('questions', { count: quiz.questionCount })}
    </span>,
    quiz.timeLimit ? (
      <span>
        <Icon name="far fa-clock" />
        {span(quiz.timeLimit)}
      </span>
    ) : null,
    <span>
      <Icon name="fas fa-flag-checkered" />
      {t('pass_mark', { mark: quiz.passMark })}
    </span>,
    quiz.maxAttempts ? (
      <span>
        <Icon name="fas fa-rotate-right" />
        {me ? t('attempts_left', { count: me.attemptsLeft ?? 0, max: quiz.maxAttempts }) : t('attempts', { count: quiz.maxAttempts })}
      </span>
    ) : null,
    quiz.rewardPoints ? (
      <span className="BrainstormFacts-reward">
        <Icon name="fas fa-coins" />
        {t('points', { count: quiz.rewardPoints })}
      </span>
    ) : null,
    quiz.rewardBadge ? (
      <span className="BrainstormFacts-reward">
        <span className="BrainstormBadgeIcon" style={{ color: quiz.rewardBadge.color, background: quiz.rewardBadge.background }}>
          <Icon name={quiz.rewardBadge.icon} />
        </span>
        {t('badge', { name: quiz.rewardBadge.name })}
      </span>
    ) : null,
  ];

  return <div className="BrainstormFacts">{facts}</div>;
}
