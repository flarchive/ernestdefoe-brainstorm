import { listQuizzes } from '../../common/api';
import type { Quiz } from '../../common/api';
import QuizCard from '../components/QuizCard';

type Lookup = Promise<Quiz | null>;

/** How long a looked-up quiz is reused: a post seen again later shows fresh standing. */
const KEEP = 60_000;

const cache = new Map<number, { at: number; quiz: Lookup }>();
let pending = new Map<number, (q: Quiz | null) => void>();
let timer = 0;

/**
 * Every quiz link on the page is looked up in one request, through the same
 * list endpoint, query and visibility as the quizzes page. A quiz that does
 * not come back (deleted, a draft, or hidden from this reader) leaves its link
 * as a plain link.
 */
function flush(): void {
  const batch = pending;
  pending = new Map();
  timer = 0;

  const ids = [...batch.keys()];
  for (let i = 0; i < ids.length; i += 50) {
    const chunk = ids.slice(i, i + 50);

    listQuizzes({ filter: { ids: chunk.join(',') } }).then(
      ({ data }) => chunk.forEach((id) => batch.get(id)!(data.find((q) => q.id === id) || null)),
      () =>
        chunk.forEach((id) => {
          // A failed lookup is not remembered: the next page tries again.
          cache.delete(id);
          batch.get(id)!(null);
        })
    );
  }
}

function lookup(id: number): Lookup {
  const found = cache.get(id);
  if (found && Date.now() - found.at < KEEP) return found.quiz;

  const quiz: Lookup = new Promise((resolve) => pending.set(id, resolve));
  cache.set(id, { at: Date.now(), quiz });
  timer ||= window.setTimeout(flush, 0);
  return quiz;
}

export default function embed(links: Array<[HTMLAnchorElement, number]>): void {
  links.forEach(([a, id]) =>
    lookup(id).then((quiz) => {
      if (!quiz || !a.isConnected) return;

      const holder = document.createElement('div');
      holder.className = 'BrainstormEmbed';
      // A block in place of the paragraph's link; the paragraph stays put.
      a.replaceWith(holder);

      // Mounted, not rendered once: the player in it redraws as it is
      // played. When the post goes (another page, an edit) the mount takes
      // itself down on the next redraw rather than redrawing a detached card.
      m.mount(holder, {
        view: () => {
          if (holder.isConnected) return <QuizCard quiz={quiz} embedded />;
          setTimeout(() => m.mount(holder, null));
          return null;
        },
      });
    })
  );
}
