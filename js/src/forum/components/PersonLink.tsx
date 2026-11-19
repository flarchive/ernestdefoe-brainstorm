import app from 'flarum/forum/app';
import Link from 'flarum/common/components/Link';
import stringToColor from 'flarum/common/utils/stringToColor';
import type { Person } from '../../common/api';

/** A member's avatar and name, linking to their profile, from Brainstorm's plain user objects. */
export default function personLink(person: Person, avatarOnly = false): any {
  const avatar = person.avatarUrl ? (
    <img className="Avatar" src={person.avatarUrl} alt="" loading="lazy" decoding="async" />
  ) : (
    <span className="Avatar" style={{ '--avatar-bg': '#' + stringToColor(person.displayName) }}>
      {person.displayName.charAt(0).toUpperCase()}
    </span>
  );

  return (
    <Link className="BrainstormPerson" href={app.route('user', { username: person.slug })}>
      {avatar}
      {avatarOnly ? null : <span className="BrainstormPerson-name">{person.displayName}</span>}
    </Link>
  );
}
