import { api, ApiError } from '../api';
import { localDb, getKV, setKV, type QueuedEvent } from './db';
import { deviceKeys, signEvent } from './signer';

/** Queue an offline event, signed on the device (OFF-003/004). */
export async function queueEvent(type: QueuedEvent['type'], payload: unknown): Promise<QueuedEvent> {
  const e = { client_uuid: crypto.randomUUID(), type, client_created_at: new Date().toISOString(), payload };
  const signature = await signEvent(await deviceKeys(), e);
  const row: QueuedEvent = { ...e, signature, snapshot_version: (await getKV<string>('snapshot_version')) ?? null, status: 'queued' };
  await localDb.events.add(row);
  return row;
}

export const pendingCount = () => localDb.events.where('status').equals('queued').count();

/** Upload queued events; safe to call repeatedly — the server ignores events it already has. */
export async function syncNow(): Promise<{ sent: number; flagged: number; rejected: number }> {
  const deviceId = await getKV<string>('device_id');
  if (!deviceId) return { sent: 0, flagged: 0, rejected: 0 };
  const queued = await localDb.events.where('status').equals('queued').sortBy('client_created_at');
  if (!queued.length) return { sent: 0, flagged: 0, rejected: 0 };
  try {
    const res = await api<{ results: { client_uuid: string; status: QueuedEvent['status']; result: string | null }[] }>(
      'POST', '/sync/events', { device_id: deviceId, events: queued.map(({ status: _s, result: _r, ...e }) => e) });
    for (const r of res.results) await localDb.events.update(r.client_uuid, { status: r.status, result: r.result });
    await setKV('last_sync_at', new Date().toISOString());
    return { sent: res.results.length, flagged: res.results.filter((r) => r.status === 'flagged').length, rejected: res.results.filter((r) => r.status === 'rejected').length };
  } catch (e) {
    if (e instanceof ApiError && e.code === 'offline') return { sent: 0, flagged: 0, rejected: 0 };
    throw e;
  }
}

/** OFF-007: warn after 72 h without a fresh catalogue, block selling after 7 days. */
export function snapshotAge(generatedAt: string | undefined, now = Date.now()): 'fresh' | 'warn' | 'block' {
  if (!generatedAt) return 'block';
  const h = (now - Date.parse(generatedAt)) / 36e5;
  return h > 24 * 7 ? 'block' : h > 72 ? 'warn' : 'fresh';
}
