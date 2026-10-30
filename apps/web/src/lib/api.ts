/** Typed API client. Every money/stock/message POST carries an Idempotency-Key (B15). */
export class ApiError extends Error {
  status: number;
  code: string;
  body?: unknown;
  constructor(status: number, code: string, message: string, body?: unknown) {
    super(message);
    this.status = status;
    this.code = code;
    this.body = body;
  }
}

const BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? '/api/v1';
let token: string | null = null;
export const setToken = (t: string | null) => { token = t; };

export const newKey = () => crypto.randomUUID().replace(/-/g, '');

export async function api<T>(method: string, path: string, body?: unknown, idempotencyKey?: string): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Request-ID': crypto.randomUUID() };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey;
  let res: Response;
  try {
    res = await fetch(BASE + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  } catch {
    throw new ApiError(0, 'offline', 'No connection.');
  }
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = (json as { error?: { code?: string; message?: string } }).error ?? {};
    throw new ApiError(res.status, err.code ?? 'error', err.message ?? res.statusText, json);
  }
  return json as T;
}
