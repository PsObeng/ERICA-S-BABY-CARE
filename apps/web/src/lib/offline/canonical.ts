/**
 * Canonical message a device signs — must match App\Support\DeviceSignature::canonical() byte for byte:
 * client_uuid|type|client_created_at|json(payload with object keys sorted recursively).
 */
export function sortKeys(v: unknown): unknown {
  if (Array.isArray(v)) return v.map(sortKeys);
  if (v && typeof v === 'object') {
    return Object.keys(v as object).sort().reduce<Record<string, unknown>>((o, k) => { o[k] = sortKeys((v as Record<string, unknown>)[k]); return o; }, {});
  }
  return v;
}

export function canonical(e: { client_uuid: string; type: string; client_created_at: string; payload: unknown }): string {
  return [e.client_uuid, e.type, e.client_created_at, JSON.stringify(sortKeys(e.payload))].join('|');
}
