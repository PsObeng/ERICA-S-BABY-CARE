import 'fake-indexeddb/auto';
import { describe, expect, it } from 'vitest';
import { queueEvent, pendingCount } from '../offline/sync';
import { deviceKeys } from '../offline/signer';
import { canonical } from '../offline/canonical';

describe('signed offline queue', () => {
  it('queues a sale with a verifiable signature from a non-extractable key', async () => {
    const e = await queueEvent('sale', { lines: [{ variant_id: 'v1', unit: 'pack', qty: 1 }], tenders: { cash_pesewas: 11500 } });
    expect(await pendingCount()).toBe(1);
    const keys = await deviceKeys();
    expect(keys.privateKey.extractable).toBe(false);
    const raw = Uint8Array.from(atob(e.signature), (c) => c.charCodeAt(0));
    expect(raw.length).toBe(64);
    const ok = await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, keys.publicKey, raw, new TextEncoder().encode(canonical(e)));
    expect(ok).toBe(true);
    const edited = canonical({ ...e, payload: { ...(e.payload as object), tenders: { cash_pesewas: 1 } } });
    expect(await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, keys.publicKey, raw, new TextEncoder().encode(edited))).toBe(false);
  });
});
