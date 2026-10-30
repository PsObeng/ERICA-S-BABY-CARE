/**
 * Display pricing for the till — mirrors App\Domain\PriceResolver. The server re-prices every
 * sale; this only shows the cashier the same number before submitting (PRC-004).
 */
export interface Variant {
  id: string; sku: string; brand: string; name: string; category: string; size: string;
  pack_count: number; carton_packs: number; retail: number; tier_a: number | null; tier_a_min: number;
  carton_price: number | null; available_packs: number; low_stock_packs: number; near_expiry: boolean;
}
export type Unit = 'pack' | 'carton';
export interface CartLine { variantId: string; unit: Unit; qty: number }
export interface PricedLine extends CartLine { unitPrice: number; tier: string; packs: number; lineTotal: number }

export function priceLine(v: Variant, unit: Unit, qty: number, wholesale: boolean): Omit<PricedLine, keyof CartLine> {
  if (qty < 1) throw new Error('Quantity must be at least 1.');
  if (unit === 'carton') {
    if (v.carton_packs <= 1) throw new Error('This product has no carton unit.');
    const unitPrice = v.carton_price || v.retail * v.carton_packs;
    return { unitPrice, tier: v.carton_price ? 'carton' : 'retail_x_carton', packs: qty * v.carton_packs, lineTotal: unitPrice * qty };
  }
  const tierA = !!v.tier_a && (qty >= v.tier_a_min || wholesale);
  const unitPrice = tierA ? (v.tier_a as number) : v.retail;
  return { unitPrice, tier: tierA ? 'tier_a' : 'retail', packs: qty, lineTotal: unitPrice * qty };
}

export interface Totals { lines: PricedLine[]; subtotal: number; discount: number; total: number }

export function totals(cart: CartLine[], variants: Map<string, Variant>, wholesale: boolean,
  discount?: { type: 'pct' | 'amount'; value: number }): Totals {
  const lines = cart.map((l) => ({ ...l, ...priceLine(variants.get(l.variantId)!, l.unit, l.qty, wholesale) }));
  const subtotal = lines.reduce((s, l) => s + l.lineTotal, 0);
  let d = 0;
  if (discount && discount.value > 0) d = discount.type === 'pct' ? Math.round(subtotal * discount.value / 100) : Math.round(discount.value);
  d = Math.max(0, Math.min(d, subtotal));
  return { lines, subtotal, discount: d, total: subtotal - d };
}

/** Cash gives change; MoMo and credit never exceed the total (same rule as the server). */
export function tenderCheck(total: number, cash: number, momo: number, credit: number) {
  const paid = cash + momo + credit;
  if (momo + credit > total) return { ok: false as const, error: 'MoMo and credit together cannot exceed the total.' };
  if (paid < total) return { ok: false as const, error: `Short by ${total - paid} pesewas.`, due: total - paid };
  return { ok: true as const, change: paid - total };
}
