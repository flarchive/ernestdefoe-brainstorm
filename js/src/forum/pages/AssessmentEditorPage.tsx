import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { assessmentUrl, listQuizzes, saveAssessment, showAssessment } from '../../common/api';
import type { AssessmentItem, EditableAssessment, Quiz, Weighting } from '../../common/api';

const t = (key: string, params: Record<string, unknown> = {}) =>
  app.translator.trans(`ernestdefoe-brainstorm.forum.assessment_editor.${key}`, params);
const text = (key: string, params: Record<string, unknown> = {}) => extractText(t(key, params));

/** /quizzes/assessments/new and /quizzes/assessments/3/edit. */
export default class AssessmentEditorPage extends Page {
  id: number | null = null;
  slug = '';
  data: EditableAssessment | null = null;
  /** Quizzes that can go in, by id: published ones, and the writer's own drafts. */
  quizzes: Record<number, Quiz> = {};
  saving = false;
  error: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const id = parseInt(m.route.param('id'), 10);
    app.setTitle(text(id ? 'edit_title' : 'new_title'));

    if (!app.forum.attribute('canCreateQuizzes')) {
      m.route.set(app.route('brainstorm.index'));
      return;
    }

    const lists = [listQuizzes({ page: { limit: 50 } }), listQuizzes({ filter: { mine: '1' }, page: { limit: 50 } })];
    // Each list on its own: one that fails (app.request has shown its alert) doesn't empty the other.
    Promise.allSettled(lists).then((pages) => {
      pages.forEach((page) => page.status === 'fulfilled' && page.value.data.forEach((q) => (this.quizzes[q.id] = q)));
      m.redraw();
    });

    if (!id) {
      this.data = { title: '', description: '', status: 'draft', passMark: 70, weighting: 'equal', items: [] };
      return;
    }

    showAssessment(id).then(
      ({ data }) => {
        if (!data.items) return m.route.set(assessmentUrl(data));
        this.id = data.id;
        this.slug = data.slug;
        this.data = {
          title: data.title,
          description: data.description || '',
          status: data.status,
          passMark: data.passMark,
          weighting: data.weighting,
          items: data.items,
        };
        // Quizzes already in it that the lists above don't carry (another writer's draft, say).
        data.quizzes.forEach((q) => q.id && !this.quizzes[q.id] && (this.quizzes[q.id] = { id: q.id, title: q.title, slug: q.slug } as Quiz));
        m.redraw();
      },
      () => m.route.set(app.route('brainstorm.index'))
    );
  }

  view(): Mithril.Children {
    const d = this.data;

    return (
      <PageStructure className="BrainstormEditorPage BrainstormAssessmentEditor" sidebar={() => <IndexSidebar />} loading={!d}>
        {d ? (
          <div className="BrainstormEditor">
            <div className="BrainstormEditor-head">
              <h2>{t(this.id ? 'edit_title' : 'new_title')}</h2>
            </div>
            <div className="BrainstormEditor-layout">
              <div className="BrainstormEditor-main">
                {this.basics(d)}
                {this.items(d)}
              </div>
              <aside className="BrainstormEditor-side">{this.settings(d)}</aside>
            </div>
          </div>
        ) : null}
      </PageStructure>
    );
  }

  basics(d: EditableAssessment): Mithril.Children {
    return [
      <div className="Form-group">
        <label for="brainstorm-a-title">{t('title')}</label>
        <input
          id="brainstorm-a-title"
          className="FormControl"
          maxlength={200}
          value={d.title}
          oninput={(e: InputEvent) => (d.title = (e.target as HTMLInputElement).value)}
        />
      </div>,
      <div className="Form-group">
        <label for="brainstorm-a-description">{t('description')}</label>
        <textarea
          id="brainstorm-a-description"
          className="FormControl"
          rows={3}
          maxlength={2000}
          value={d.description}
          oninput={(e: InputEvent) => (d.description = (e.target as HTMLTextAreaElement).value)}
        />
      </div>,
    ];
  }

  /** The quizzes, in order, with their weight and whether each is required; and a way to add one. */
  items(d: EditableAssessment): Mithril.Children {
    const free = Object.values(this.quizzes).filter((q) => !d.items.some((i) => i.quizId === q.id));
    const add = (value: string) => {
      if (value) d.items.push({ quizId: Number(value), weight: 1, required: true });
    };

    return [
      <h3 className="BrainstormEditor-section">{t('quizzes', { count: d.items.length })}</h3>,
      <p className="helpText">{t('quizzes_help')}</p>,
      <ol className="BrainstormAssessmentEditor-items">{d.items.map((item, i) => this.item(d, item, i))}</ol>,
      free.length ? (
        <div className="Form-group BrainstormAssessmentEditor-add">
          <Select
            value=""
            aria-label={text('add')}
            options={{ '': text('add'), ...Object.fromEntries(free.map((q) => ['q' + q.id, q.title])) }}
            onchange={(v: string) => add(v.replace(/^q/, ''))}
          />
        </div>
      ) : (
        <p className="helpText">{t(Object.keys(this.quizzes).length ? 'all_added' : 'no_quizzes')}</p>
      ),
    ];
  }

  item(d: EditableAssessment, item: AssessmentItem, i: number): Mithril.Children {
    const move = (to: number) => {
      const [moved] = d.items.splice(i, 1);
      d.items.splice(to, 0, moved);
    };
    const title = this.quizzes[item.quizId]?.title || text('quiz_number', { id: item.quizId });

    return (
      <li className="BrainstormAssessmentEditor-item" key={item.quizId}>
        <span className="BrainstormAssessmentEditor-title">{title}</span>
        {d.weighting === 'weighted' ? (
          <label className="BrainstormAssessmentEditor-weight">
            {t('weight')}
            <input
              className="FormControl"
              type="number"
              min={1}
              max={100}
              value={item.weight}
              oninput={(e: InputEvent) => (item.weight = Math.max(1, Math.min(100, Number((e.target as HTMLInputElement).value) || 1)))}
            />
          </label>
        ) : null}
        <label className="checkbox BrainstormAssessmentEditor-required">
          <input type="checkbox" checked={item.required} onchange={() => (item.required = !item.required)} />
          {t('required')}
        </label>
        <span className="BrainstormAssessmentEditor-tools">
          <Button className="Button Button--icon" icon="fas fa-arrow-up" aria-label={text('up')} disabled={i === 0} onclick={() => move(i - 1)} />
          <Button
            className="Button Button--icon"
            icon="fas fa-arrow-down"
            aria-label={text('down')}
            disabled={i === d.items.length - 1}
            onclick={() => move(i + 1)}
          />
          <Button className="Button Button--icon" icon="fas fa-xmark" aria-label={text('remove')} onclick={() => d.items.splice(i, 1)} />
        </span>
      </li>
    );
  }

  settings(d: EditableAssessment): Mithril.Children {
    const published = d.status === 'published';

    return (
      <div className="BrainstormEditor-settings">
        <h3 className="BrainstormEditor-section">{t('settings')}</h3>
        <div className="Form-group">
          <label for="brainstorm-a-mark">{t('pass_mark')}</label>
          <input
            id="brainstorm-a-mark"
            className="FormControl"
            type="number"
            min={0}
            max={100}
            value={d.passMark}
            oninput={(e: InputEvent) => (d.passMark = Math.max(0, Math.min(100, Math.round(Number((e.target as HTMLInputElement).value)) || 0)))}
          />
          <p className="helpText">{t('pass_mark_help')}</p>
        </div>
        <div className="Form-group">
          <label>{t('weighting')}</label>
          <Select
            value={d.weighting}
            options={{ equal: text('weighting_equal'), weighted: text('weighting_weighted') }}
            onchange={(v: Weighting) => (d.weighting = v)}
          />
        </div>
        {this.error ? (
          <p className="BrainstormEditor-error" role="alert">
            {this.error}
          </p>
        ) : null}
        <div className="BrainstormEditor-actions">
          <Button className="Button" loading={this.saving} onclick={() => this.save('draft')}>
            {t(published ? 'unpublish' : 'save_draft')}
          </Button>
          <Button className="Button Button--primary" loading={this.saving} onclick={() => this.save('published')}>
            {t(published ? 'save' : 'publish')}
          </Button>
        </div>
      </div>
    );
  }

  save(status: 'draft' | 'published') {
    this.saving = true;
    this.error = null;

    saveAssessment({ ...this.data!, status }, this.id ?? undefined).then(
      ({ data }) => {
        this.saving = false;
        app.alerts.show({ type: 'success' }, t(status === 'published' ? 'published' : 'saved'));
        m.route.set(assessmentUrl(data));
      },
      (error: any) => {
        this.saving = false;
        this.error = error?.response?.errors?.[0]?.detail || text('save_failed');
        m.redraw();
      }
    );
  }
}
