import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import LinkButton from 'flarum/common/components/LinkButton';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { deleteAssessment, quizUrl, showAssessment } from '../../common/api';
import type { Assessment, AssessmentQuiz } from '../../common/api';
import { percent } from '../../common/format';
import AssessmentSummary from '../components/AssessmentSummary';
import personLink from '../components/PersonLink';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-brainstorm.forum.assessment.${key}`, params);

/** /quizzes/assessments/3-javascript — a group of quizzes and the reader's overall result. */
export default class AssessmentPage extends Page {
  assessment: Assessment | null = null;
  missing = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    showAssessment(parseInt(m.route.param('id'), 10)).then(
      ({ data }) => {
        this.assessment = data;
        app.setTitle(data.title);
        app.history.push('assessment', data.title);
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
      <PageStructure className="BrainstormAssessmentPage" sidebar={() => <IndexSidebar />} loading={!this.assessment && !this.missing}>
        {this.missing ? <p className="BrainstormEmpty">{t('missing')}</p> : this.assessment ? this.content(this.assessment) : null}
      </PageStructure>
    );
  }

  content(a: Assessment): Mithril.Children {
    return (
      <div className="BrainstormAssessmentPage-main">
        <LinkButton className="Button Button--link BrainstormQuizPage-back" icon="fas fa-arrow-left" href={app.route('brainstorm.index')}>
          {app.translator.trans('ernestdefoe-brainstorm.forum.quiz.all')}
        </LinkButton>
        <h2 className="BrainstormQuizPage-title">
          {a.title}
          {a.status === 'draft' ? (
            <span className="BrainstormChip BrainstormChip--draft">{app.translator.trans('ernestdefoe-brainstorm.forum.draft')}</span>
          ) : null}
        </h2>
        <div className="BrainstormQuizPage-byline">
          {a.author ? personLink(a.author) : null}
          <span>{t('count', { count: a.quizzes.length })}</span>
        </div>
        {a.description ? <p className="BrainstormAssessmentPage-description">{a.description}</p> : null}
        {a.canEdit ? this.tools(a) : null}

        <AssessmentSummary assessment={a} />

        <ol className="BrainstormAssessmentPage-quizzes">{a.quizzes.map((q, i) => this.quiz(a, q, i))}</ol>
      </div>
    );
  }

  tools(a: Assessment): Mithril.Children {
    const remove = () => {
      if (!confirm(extractText(t('delete_confirm')))) return;
      deleteAssessment(a.id).then(() => m.route.set(app.route('brainstorm.index')));
    };

    return (
      <div className="BrainstormQuizPage-tools">
        <LinkButton className="Button" icon="fas fa-pen" href={app.route('brainstorm.assessment.edit', { id: a.id })}>
          {t('edit')}
        </LinkButton>
        <Button className="Button Button--danger" icon="fas fa-trash" onclick={remove}>
          {t('delete')}
        </Button>
      </div>
    );
  }

  /** One quiz: its title (or "locked"), its weight and whether it's required, and the reader's best score. */
  quiz(a: Assessment, q: AssessmentQuiz, i: number): Mithril.Children {
    const best = q.best;
    const state = q.locked ? 'locked' : best ? (best.passed ? 'passed' : 'taken') : 'todo';

    return (
      <li className={'BrainstormAssessmentQuiz is-' + state}>
        <span className="BrainstormAssessmentQuiz-number">{i + 1}</span>
        <span className="BrainstormAssessmentQuiz-title">
          {q.locked ? (
            <span className="BrainstormAssessmentQuiz-locked">
              <Icon name="fas fa-lock" /> {t('locked')}
            </span>
          ) : (
            <Link href={quizUrl({ id: q.id!, slug: q.slug || '' })}>{q.title}</Link>
          )}
          <span className="BrainstormAssessmentQuiz-meta">
            {q.required ? null : <span className="BrainstormChip">{t('optional')}</span>}
            {a.weighting === 'weighted' ? <span className="BrainstormChip">{t('weight', { weight: q.weight })}</span> : null}
          </span>
        </span>
        <span className="BrainstormAssessmentQuiz-score">
          {best ? (
            [<Icon name={best.passed ? 'fas fa-circle-check' : 'far fa-circle-dot'} />, ' ', percent(best.percent)]
          ) : q.locked ? null : (
            <span className="BrainstormAssessmentQuiz-todo">{t('not_taken')}</span>
          )}
        </span>
      </li>
    );
  }
}
