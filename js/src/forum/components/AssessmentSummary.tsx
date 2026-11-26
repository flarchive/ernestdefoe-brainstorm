import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type { Assessment } from '../../common/api';
import { percent } from '../../common/format';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.assessment.${key}`, params);

/**
 * The reader's overall result: the combined ring (their average over the
 * quizzes they've finished) and how far along they are.
 */
export default class AssessmentSummary extends Component<{ assessment: Assessment }> {
  view(): Mithril.Children {
    const a = this.attrs.assessment;
    const me = a.me;
    const value = me?.percent ?? null;
    const ring = value === null ? 0 : Math.max(0, Math.min(100, value));
    const state = me?.complete ? 'is-passed' : value !== null && value < a.passMark ? 'is-failed' : 'is-open';

    return (
      <div className={'BrainstormResult BrainstormAssessmentSummary ' + state}>
        <div className="BrainstormResult-summary">
          <div className="BrainstormResult-ring" style={{ '--brainstorm-ring': ring + '%' }} aria-hidden="true">
            <span>{value === null ? '—' : percent(value)}</span>
          </div>
          <div className="BrainstormResult-text">
            <h4 className="BrainstormResult-headline">
              {me?.complete ? <Icon name="fas fa-circle-check" /> : null}
              {me ? t(me.complete ? 'complete' : value === null ? 'not_started' : 'in_progress') : t('log_in')}
            </h4>
            <p>
              {me ? t('progress', { done: me.done, total: me.total, required: me.required, mark: a.passMark }) : t('pass_mark', { mark: a.passMark })}
            </p>
            {a.weighting === 'weighted' ? <p className="BrainstormResult-note">{t('weighted_note')}</p> : null}
          </div>
        </div>
      </div>
    );
  }
}
