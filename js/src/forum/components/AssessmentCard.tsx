import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import { assessmentUrl } from '../../common/api';
import type { Assessment } from '../../common/api';
import { percent } from '../../common/format';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.assessment.${key}`, params);

/** An assessment in a list: its quizzes, pass mark and the reader's standing. */
export default class AssessmentCard extends Component<{ assessment: Assessment }> {
  view(): Mithril.Children {
    const a = this.attrs.assessment;
    const me = a.me;

    return (
      <article className="BrainstormCard BrainstormAssessmentCard">
        <header className="BrainstormCard-header">
          <span className="BrainstormCard-icon" aria-hidden="true">
            <Icon name="fas fa-layer-group" />
          </span>
          <div className="BrainstormCard-heading">
            <h3 className="BrainstormCard-title">
              <Link href={assessmentUrl(a)}>{a.title}</Link>
              {a.status === 'draft' ? (
                <span className="BrainstormChip BrainstormChip--draft">{app.translator.trans('ernestdefoe-brainstorm.forum.draft')}</span>
              ) : null}
            </h3>
            <p className="BrainstormFacts">
              <span>{t('count', { count: a.quizzes.length })}</span>
              <span>{t('pass_mark', { mark: a.passMark })}</span>
            </p>
          </div>
        </header>
        <footer className="BrainstormCard-footer">
          {me && me.done ? (
            <span className={'BrainstormCard-standing' + (me.complete ? ' is-passed' : '')}>
              {me.complete ? <Icon name="fas fa-circle-check" /> : null}
              {t('standing', { percent: me.percent === null ? '—' : percent(me.percent), done: me.done, total: me.total })}
            </span>
          ) : (
            <span className="BrainstormCard-standing" />
          )}
          <Link className="Button Button--primary" href={assessmentUrl(a)}>
            {t('open')}
          </Link>
        </footer>
      </article>
    );
  }
}
