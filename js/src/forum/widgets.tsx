import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type Mithril from 'mithril';

/**
 * Brainstorm's widget — the top quiz takers or the newest quizzes — offered to
 * every widget host there is: Bespoke, FoF Forum Widgets and Page Builder.
 *
 * 🚨 No host is a build-time dependency: each is found at runtime through its
 * own registry or queue, so the bundle is the same with all, some or none
 * installed. And what the widget shows is its own lazy chunk, fetched only by
 * a page that actually places it.
 */

type Show = 'leaderboard' | 'latest';

let body: any = null;

class QuizzesWidget extends Component<{ show?: Show; limit?: number }> {
  oninit(vnode: Mithril.Vnode<{ show?: Show; limit?: number }, this>) {
    super.oninit(vnode);
    if (!body) {
      import('./components/WidgetBody').then((mod) => {
        body = mod.default;
        m.redraw();
      });
    }
  }

  view(): Mithril.Children {
    if (!app.forum.attribute('canViewQuizzes')) return null;
    const limit = Math.max(1, Math.min(25, Number(this.attrs.limit) || 5));

    return body ? m(body, { show: this.attrs.show === 'latest' ? 'latest' : 'leaderboard', limit }) : <div className="BrainstormWidget" />;
  }
}

const flarumGlobal = (): any => (typeof globalThis !== 'undefined' ? (globalThis as any).flarum : undefined);

export default function registerWidgets(): void {
  const flarum = flarumGlobal();
  const has = (id: string) => !!flarum?.extensions && id in flarum.extensions;

  if (has('fof-forum-widgets-core')) {
    const widgetsMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/extend/Widgets');
    const widgetMod = flarum.reg?.get?.('fof-forum-widgets-core', 'common/components/Widget');
    const Widgets = widgetsMod?.default ?? widgetsMod;
    const WidgetBase = widgetMod?.default ?? widgetMod;

    // A version whose internals moved: decline quietly rather than throw out
    // of an initializer and take the forum's JS down with it.
    if (Widgets && WidgetBase) {
      class FofQuizzesWidget extends WidgetBase {
        className() {
          return 'BrainstormFofWidget';
        }
        icon() {
          return 'fas fa-graduation-cap';
        }
        title() {
          return app.translator.trans('ernestdefoe-brainstorm.forum.widget.title');
        }
        content() {
          return <QuizzesWidget />;
        }
      }

      new Widgets()
        .add({ key: 'brainstormQuizzes', component: FofQuizzesWidget, isDisabled: false, isUnique: true, placement: 'end', position: 1 })
        .extend(app, 'ernestdefoe-brainstorm');
    }
  }

  if (has('ernestdefoe-bespoke')) {
    const g = globalThis as any;
    g.BespokeWidgetQueue = g.BespokeWidgetQueue || [];
    // Labels are translation KEYS: Bespoke resolves them itself, in the reader's locale.
    g.BespokeWidgetQueue.push({
      type: 'brainstorm-quizzes',
      label: 'ernestdefoe-brainstorm.forum.widget.name',
      icon: 'fas fa-graduation-cap',
      zones: ['sidebar', 'above-list', 'below-list', 'footer'],
      schema: [
        { key: 'heading', type: 'text', label: 'ernestdefoe-brainstorm.forum.widget.heading', default: '' },
        {
          key: 'show',
          type: 'select',
          label: 'ernestdefoe-brainstorm.forum.widget.show',
          default: 'leaderboard',
          options: [
            { value: 'leaderboard', label: 'ernestdefoe-brainstorm.forum.widget.show_leaderboard' },
            { value: 'latest', label: 'ernestdefoe-brainstorm.forum.widget.show_latest' },
          ],
        },
        { key: 'limit', type: 'number', label: 'ernestdefoe-brainstorm.forum.widget.limit', default: 5 },
      ],
      component: {
        view(vnode: any) {
          const s = vnode.attrs?.settings || {};
          return (
            <div className="BrainstormBespokeWidget">
              <h4 className="BrainstormWidget-heading">{s.heading || app.translator.trans('ernestdefoe-brainstorm.forum.widget.title')}</h4>
              <QuizzesWidget show={s.show} limit={s.limit} />
            </div>
          );
        },
      },
    });
  }

  if (has('ernestdefoe-page-builder')) {
    const g = globalThis as any;
    g.PageBuilderBlockQueue = g.PageBuilderBlockQueue || [];
    // 🚨 The type matches QuizzesBlock::type() exactly.
    g.PageBuilderBlockQueue.push({
      type: 'brainstorm-quizzes',
      component: {
        view(vnode: any) {
          const s = vnode.attrs?.block?.settings || {};
          return (
            <div className="BrainstormPageBuilderBlock">
              <QuizzesWidget show={s.show} limit={s.limit} />
            </div>
          );
        },
      },
    });
  }
}
