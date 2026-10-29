/**
 * The class for formatted quiz text (descriptions, questions, explanations).
 *
 * On its own page the text takes Flarum's post typography from `Post-body`.
 * Inside a post it must not carry that class again: the post around it already
 * styles it, and other extensions decorate a post's `.Post-body` (FoF and
 * Favorite Team badges, watermarks), which would land on every nested question.
 */
export default function prose(base: string, inPost?: boolean): string {
  return inPost ? base : base + ' Post-body';
}
