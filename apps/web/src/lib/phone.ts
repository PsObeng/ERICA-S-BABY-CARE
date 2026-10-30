/** Ghana phone numbers — same rules as App\Domain\GhanaPhone on the server (CRM-002). */
export const PREFIXES: Record<string, string[]> = {
  MTN: ['24', '54', '55', '59', '25', '53'],
  Telecel: ['20', '50'],
  AT: ['26', '56', '27', '57'],
};

export function toE164(input: string): string | null {
  let d = input.replace(/\D+/g, '');
  if (d.startsWith('233') && d.length === 12) d = d.slice(3);
  else if (d.startsWith('0') && d.length === 10) d = d.slice(1);
  if (d.length !== 9 || d[0] === '0') return null;
  return `+233${d}`;
}

export function localFormat(e164: string): string {
  const l = `0${e164.slice(4)}`;
  return `${l.slice(0, 3)} ${l.slice(3, 6)} ${l.slice(6)}`;
}

export function network(e164: string): string {
  const p = e164.slice(4, 6);
  for (const [name, list] of Object.entries(PREFIXES)) if (list.includes(p)) return name;
  return 'Other';
}

export const waLink = (e164: string, text: string) => `https://wa.me/${e164.replace('+', '')}?text=${encodeURIComponent(text)}`;
