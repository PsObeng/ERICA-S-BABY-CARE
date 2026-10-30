import { describe, expect, it } from 'vitest';
import { formatGHS, parseCedis } from '../money';
import { localFormat, network, toE164 } from '../phone';
import { priceLine, tenderCheck, totals, type Variant } from '../pricing';
import { canonical } from '../offline/canonical';
import { snapshotAge } from '../offline/sync';

const v: Variant = { id: 'v1', sku: 'SKU-001', brand: 'Softcare', name: 'Diapers Medium (M)', category: 'diapers', size: 'M', pack_count: 40,
  carton_packs: 4, retail: 11500, tier_a: 10800, tier_a_min: 10, carton_price: 42000, available_packs: 40, low_stock_packs: 12, near_expiry: false };

describe('money', () => {
  it('parses without float error', () => { expect(parseCedis('0.1')).toBe(10); expect(parseCedis('1,250.50')).toBe(125050); });
  it('rejects 3 decimals', () => expect(() => parseCedis('1.234')).toThrow());
  it('formats negatives with a minus sign', () => expect(formatGHS(-34850)).toBe('−GH₵348.50'));
});

describe('Ghana phone (same rules as the server)', () => {
  it('normalises local and international forms', () => {
    expect(toE164('024 412 3456')).toBe('+233244123456');
    expect(toE164('233559998877')).toBe('+233559998877');
    expect(toE164('02441234')).toBeNull();
  });
  it('formats and detects networks', () => {
    expect(localFormat('+233244123456')).toBe('024 412 3456');
    expect(network('+233201234567')).toBe('Telecel');
    expect(network('+233271234567')).toBe('AT');
  });
});

describe('pricing (mirrors App\\Domain\\PriceResolver)', () => {
  it('retail, tier A and carton', () => {
    expect(priceLine(v, 'pack', 2, false).unitPrice).toBe(11500);
    expect(priceLine(v, 'pack', 10, false).tier).toBe('tier_a');
    expect(priceLine(v, 'pack', 1, true).unitPrice).toBe(10800);
    expect(priceLine(v, 'carton', 2, false)).toMatchObject({ unitPrice: 42000, packs: 8 });
  });
  it('totals with discount', () => {
    const t = totals([{ variantId: 'v1', unit: 'pack', qty: 2 }], new Map([['v1', v]]), false, { type: 'pct', value: 5 });
    expect(t).toMatchObject({ subtotal: 23000, discount: 1150, total: 21850 });
  });
  it('only cash gives change', () => {
    expect(tenderCheck(10000, 20000, 0, 0)).toEqual({ ok: true, change: 10000 });
    expect(tenderCheck(10000, 0, 12000, 0).ok).toBe(false);
    expect(tenderCheck(10000, 5000, 0, 0).ok).toBe(false);
  });
});

describe('offline', () => {
  it('canonical message sorts keys recursively (matches DeviceSignature::canonical)', () => {
    const a = canonical({ client_uuid: 'u', type: 'sale', client_created_at: 't', payload: { tenders: { cash_pesewas: 1 }, lines: [{ unit: 'pack', qty: 1 }] } });
    const b = canonical({ client_uuid: 'u', type: 'sale', client_created_at: 't', payload: { lines: [{ qty: 1, unit: 'pack' }], tenders: { cash_pesewas: 1 } } });
    expect(a).toBe(b);
    expect(a).toBe('u|sale|t|{"lines":[{"qty":1,"unit":"pack"}],"tenders":{"cash_pesewas":1}}');
  });
  it('catalogue staleness: warn after 72 h, block after 7 days', () => {
    const now = Date.parse('2026-10-30T12:00:00Z');
    expect(snapshotAge('2026-10-29T12:00:00Z', now)).toBe('fresh');
    expect(snapshotAge('2026-10-26T12:00:00Z', now)).toBe('warn');
    expect(snapshotAge('2026-10-22T12:00:00Z', now)).toBe('block');
  });
});
