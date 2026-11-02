import app from 'flarum/forum/app';

let prefix: string | null = null;

/** "https://forum.example/quizzes/" — where this forum's quiz links point. */
function quizzesPrefix(): string {
  if (prefix === null) {
    const base = new URL(app.forum.attribute<string>('baseUrl'), location.href);
    prefix = base.origin + base.pathname.replace(/\/$/, '') + '/quizzes/';
  }
  return prefix;
}

/** The quiz id a link to one of this forum's quizzes names, else null. */
function idOf(a: HTMLAnchorElement): number | null {
  const href = a.href.split(/[?#]/)[0].replace(/\/$/, '');
  const p = quizzesPrefix();
  if (!href.startsWith(p)) return null;

  const match = /^(\d+)(?:-[^/]*)?$/.exec(href.slice(p.length));
  return match ? Number(match[1]) : null;
}

/** Nothing but whitespace between the node and the edge of its line in that direction. */
function edge(node: Node | null, step: 'previousSibling' | 'nextSibling'): boolean {
  while (node && node.nodeType === Node.TEXT_NODE && !node.textContent!.trim()) node = node[step];
  return !node || node.nodeName === 'BR';
}

/**
 * A quiz link on a line of its own becomes the quiz itself, playable in the
 * post. Only the link is replaced, inside its paragraph: the post's top-level
 * nodes belong to Mithril's m.trust and must stay as they are. A link with
 * words around it stays a link.
 */
export default function scan(root: Element | undefined): void {
  if (!root) return;

  const found: Array<[HTMLAnchorElement, number]> = [];

  root.querySelectorAll<HTMLAnchorElement>('.Post-body a[href*="/quizzes/"]:not([data-brainstorm])').forEach((a) => {
    a.setAttribute('data-brainstorm', '');

    // Never a link inside a quiz already shown: the card's own title links to its quiz.
    if (a.closest('.BrainstormEmbed')) return;

    const id = idOf(a);
    const parent = a.parentElement;
    if (!id || !parent || parent.classList.contains('Post-body')) return;
    if (!edge(a.previousSibling, 'previousSibling') || !edge(a.nextSibling, 'nextSibling')) return;

    found.push([a, id]);
  });

  // The card, the player and the lookup only load on a page that has one.
  if (found.length) import('./embed').then(({ default: embed }) => embed(found));
}
