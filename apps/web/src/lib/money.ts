/** Money in integer pesewas (1 GH₵ = 100 pesewas). Never floats for stored values (PRC-001). */
export type Pesewas = number;

export function parseCedis(input: string): Pesewas {
  const clean = input.replace(/[, ]|GH₵|GHS/g, '').trim();
  if (!/^-?\d+(\.\d{1,2})?$/.test(clean)) throw new Error(`Not a valid cedi amount: ${input}`);
  const neg = clean.startsWith('-');
  const [whole, frac = '0'] = clean.replace('-', '').split('.');
  const p = Number(whole) * 100 + Number(frac.padEnd(2, '0'));
  return neg ? -p : p;
}

export function formatGHS(p: Pesewas): string {
  const abs = Math.abs(p);
  const s = `GH₵${Math.floor(abs / 100).toLocaleString('en-GB')}.${String(abs % 100).padStart(2, '0')}`;
  return p < 0 ? `−${s}` : s;
}
