import { getKV, setKV } from './db';
import { canonical } from './canonical';

/**
 * Device signing key (SEC-INT-052): ECDSA P-256, generated on the device as NON-EXTRACTABLE —
 * the private key can sign but can never be read out, copied or exported, even by this code.
 * The public key (SPKI → PEM) is registered with the server by the owner.
 */
export async function deviceKeys(): Promise<CryptoKeyPair> {
  const existing = await getKV<CryptoKeyPair>('device_keys');
  if (existing) return existing;
  const pair = await crypto.subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
  await setKV('device_keys', pair); // CryptoKey objects are stored by IndexedDB structured clone, still non-extractable
  return pair;
}

export async function publicKeyPem(pair: CryptoKeyPair): Promise<string> {
  const spki = new Uint8Array(await crypto.subtle.exportKey('spki', pair.publicKey));
  const b64 = btoa(String.fromCharCode(...spki)).replace(/(.{64})/g, '$1\n');
  return `-----BEGIN PUBLIC KEY-----\n${b64}\n-----END PUBLIC KEY-----\n`;
}

export async function signEvent(pair: CryptoKeyPair, e: Parameters<typeof canonical>[0]): Promise<string> {
  const sig = new Uint8Array(await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, pair.privateKey, new TextEncoder().encode(canonical(e))));
  return btoa(String.fromCharCode(...sig)); // raw r||s, 64 bytes — server converts to DER
}
