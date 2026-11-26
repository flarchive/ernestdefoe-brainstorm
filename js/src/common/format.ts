import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';

/** 75.5 → "75.5%" in the reader's own locale ("75,5 %" in French). */
export function percent(value: number): string {
  return new Intl.NumberFormat(app.translator.getLocale() || undefined, { style: 'percent', maximumFractionDigits: 1 }).format(value / 100);
}

/** Seconds as "m:ss", or "h:mm:ss" past an hour. */
export function clock(seconds: number): string {
  const s = Math.max(0, Math.round(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const pad = (n: number) => String(n).padStart(2, '0');

  return h ? `${h}:${pad(m)}:${pad(s % 60)}` : `${m}:${pad(s % 60)}`;
}

/** A length of time in words: "10 minutes", "1 hour 30 minutes", "45 seconds". */
export function span(seconds: number): string {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  const t = (key: string, count: number) => extractText(app.translator.trans(`ernestdefoe-brainstorm.lib.units.${key}`, { count }));

  return [h && t('hours', h), m && t('minutes', m), (s || !(h || m)) && t('seconds', s)].filter(Boolean).join(' ');
}
