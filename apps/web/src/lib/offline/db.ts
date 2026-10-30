import Dexie, { type Table } from 'dexie';

/** Local device storage (OFF-002). Never holds full exports or any payment credential. */
export interface QueuedEvent {
  client_uuid: string; type: 'sale' | 'customer' | 'shift_open' | 'shift_close';
  client_created_at: string; payload: unknown; signature: string; snapshot_version: string | null;
  status: 'queued' | 'accepted' | 'flagged' | 'rejected'; result?: string | null;
}
export interface KV { key: string; value: unknown }

class LocalDb extends Dexie {
  events!: Table<QueuedEvent, string>;
  kv!: Table<KV, string>;
  constructor() {
    super('dw360');
    this.version(1).stores({ events: 'client_uuid, status, client_created_at', kv: 'key' });
  }
}
export const localDb = new LocalDb();

export async function getKV<T>(key: string): Promise<T | undefined> {
  return (await localDb.kv.get(key))?.value as T | undefined;
}
export async function setKV(key: string, value: unknown) {
  await localDb.kv.put({ key, value });
}

/** OFF-006: offline data older than 30 days is removed once synced. */
export async function pruneSynced(now = Date.now()) {
  const cutoff = new Date(now - 30 * 864e5).toISOString();
  await localDb.events.where('client_created_at').below(cutoff).and((e) => e.status !== 'queued').delete();
}
