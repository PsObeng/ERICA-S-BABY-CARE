import { useEffect, useMemo, useState } from 'react';
import { api, ApiError, newKey } from '../../lib/api';
import { formatGHS, parseCedis } from '../../lib/money';
import { tenderCheck, totals, type CartLine, type Variant } from '../../lib/pricing';
import { getKV, setKV } from '../../lib/offline/db';
import { pendingCount, queueEvent, snapshotAge, syncNow } from '../../lib/offline/sync';

interface Snapshot { generated_at: string; snapshot_version: string; variants: Variant[] }

const safeCedis = (s: string) => { try { return s.trim() ? parseCedis(s) : 0; } catch { return NaN; } };

export function PosScreen() {
  const [snap, setSnap] = useState<Snapshot | null>(null);
  const [online, setOnline] = useState(navigator.onLine);
  const [pending, setPending] = useState(0);
  const [cart, setCart] = useState<CartLine[]>([]);
  const [q, setQ] = useState('');
  const [cash, setCash] = useState('');
  const [momo, setMomo] = useState('');
  const [net, setNet] = useState('MTN');
  const [txn, setTxn] = useState('');
  const [payer, setPayer] = useState('');
  const [msg, setMsg] = useState<{ text: string; bad?: boolean } | null>(null);

  const refresh = async () => {
    try {
      const s = await api<Snapshot>('GET', '/sync/snapshot');
      await setKV('snapshot', s);
      await setKV('snapshot_version', s.snapshot_version);
      setSnap(s);
    } catch {
      setSnap((await getKV<Snapshot>('snapshot')) ?? null); // offline: use the cached catalogue
    }
    setPending(await pendingCount());
  };
  useEffect(() => {
    void refresh();
    const on = () => { setOnline(true); void syncNow().then(refresh); };
    const off = () => setOnline(false);
    addEventListener('online', on); addEventListener('offline', off);
    return () => { removeEventListener('online', on); removeEventListener('offline', off); };
  }, []);

  const variants = useMemo(() => new Map((snap?.variants ?? []).map((v) => [v.id, v])), [snap]);
  const t = useMemo(() => totals(cart, variants, false), [cart, variants]);
  const age = snapshotAge(snap?.generated_at);
  const cashP = safeCedis(cash), momoP = safeCedis(momo);
  const check = tenderCheck(t.total, cashP || 0, momoP || 0, 0);

  const add = (id: string) => setCart((c) => {
    const i = c.findIndex((l) => l.variantId === id && l.unit === 'pack');
    return i >= 0 ? c.map((l, k) => (k === i ? { ...l, qty: l.qty + 1 } : l)) : [...c, { variantId: id, unit: 'pack', qty: 1 }];
  });

  const complete = async () => {
    setMsg(null);
    if (!check.ok || Number.isNaN(cashP) || Number.isNaN(momoP)) return setMsg({ text: check.ok ? 'Check the amounts.' : check.error, bad: true });
    if (momoP > 0 && txn.trim().length < 6) return setMsg({ text: 'Enter the MoMo transaction ID. Never ask for the customer’s PIN.', bad: true });
    const change = check.change;
    const body = {
      client_uuid: crypto.randomUUID(),
      lines: t.lines.map((l) => ({ variant_id: l.variantId, unit: l.unit, qty: l.qty, charged_unit_pesewas: l.unitPrice })),
      tenders: { cash_pesewas: cashP || 0, ...(momoP > 0 ? { momo: { amount_pesewas: momoP, network: net, txn_id: txn.trim().toUpperCase(), payer } } : {}) },
    };
    try {
      const order = await api<{ number: string }>('POST', '/sales', { ...body, lines: body.lines.map(({ charged_unit_pesewas: _x, ...l }) => l) }, newKey());
      setMsg({ text: `Sale ${order.number} saved. Change: ${formatGHS(change)}` });
    } catch (e) {
      if (e instanceof ApiError && e.code === 'offline') {
        await queueEvent('sale', body); // signed on the device; synced later (OFF-003)
        setMsg({ text: `Saved offline. Change: ${formatGHS(change)}. It will sync when the network is back.` });
      } else {
        return setMsg({ text: e instanceof ApiError ? e.message : 'Could not save the sale.', bad: true });
      }
    }
    setCart([]); setCash(''); setMomo(''); setTxn(''); setPayer('');
    setPending(await pendingCount());
  };

  const list = (snap?.variants ?? []).filter((v) => `${v.brand} ${v.name} ${v.sku} ${v.size}`.toLowerCase().includes(q.toLowerCase()));
  return (
    <>
      <header className="top">
        <b>ERICA'S BABY CARE</b><span>Sell</span><span className="sp" />
        <span className={`chip ${online ? '' : 'off'}`}>{online ? 'Online' : 'Offline'}{pending ? ` · ${pending} waiting to sync` : ''}</span>
        <button className="btn sec" onClick={() => void syncNow().then(refresh)} disabled={!online}>Sync</button>
      </header>
      {age !== 'fresh' && <div className="card" role="alert" style={{ margin: 14, color: 'var(--warn)' }}>
        {age === 'block' ? 'The product list is too old to sell from. Connect and sync first.' : 'The product list is over 3 days old. Sync when you can.'}</div>}
      <div className="wrap">
        <section aria-label="Products">
          <label htmlFor="search">Search or scan</label>
          <input id="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Brand, size, SKU…" />
          <div className="grid" style={{ marginTop: 10 }}>
            {list.map((v) => (
              <button key={v.id} className="pc" onClick={() => add(v.id)} disabled={v.available_packs <= 0 || age === 'block'}>
                <small>{v.brand} · {v.size}</small><span>{v.name}</span>
                <small>{v.available_packs} packs{v.near_expiry ? ' · near expiry' : ''}</small>
                <span className="pr">{formatGHS(v.retail)}</span>
              </button>
            ))}
          </div>
        </section>
        <aside className="card" aria-label="Current sale">
          <h2 style={{ margin: 0, fontSize: 16, color: 'var(--bb)' }}>Current sale</h2>
          {t.lines.map((l, i) => (
            <div className="line" key={`${l.variantId}-${l.unit}`}>
              <span>{variants.get(l.variantId)?.brand} {variants.get(l.variantId)?.name} × {l.qty} {l.unit}<br /><small className="hint">{l.tier} · {formatGHS(l.unitPrice)}</small></span>
              <span><b>{formatGHS(l.lineTotal)}</b><br /><button className="btn sec" style={{ minHeight: 30 }} onClick={() => setCart((c) => c.filter((_, k) => k !== i))} aria-label="Remove">✕</button></span>
            </div>
          ))}
          <div className="total"><span>Total</span><span>{formatGHS(t.total)}</span></div>
          <label htmlFor="cash">Cash (GH₵)</label><input id="cash" inputMode="decimal" value={cash} onChange={(e) => setCash(e.target.value)} />
          <label htmlFor="momo">MoMo (GH₵)</label><input id="momo" inputMode="decimal" value={momo} onChange={(e) => setMomo(e.target.value)} />
          {momoP > 0 && <>
            <label htmlFor="net">Network</label><select id="net" value={net} onChange={(e) => setNet(e.target.value)}><option>MTN</option><option>Telecel</option><option>AT</option></select>
            <label htmlFor="txn">Transaction ID</label><input id="txn" value={txn} onChange={(e) => setTxn(e.target.value)} autoComplete="off" />
            <label htmlFor="payer">Payer number</label><input id="payer" inputMode="tel" value={payer} onChange={(e) => setPayer(e.target.value)} />
          </>}
          <p className="hint">{check.ok ? `Change: ${formatGHS(check.change)}` : check.error}</p>
          {msg && <div className={msg.bad ? 'err' : 'hint'} role="status">{msg.text}</div>}
          <button className="btn gold" style={{ width: '100%', marginTop: 10 }} onClick={() => void complete()} disabled={!t.lines.length || age === 'block'}>Complete sale</button>
        </aside>
      </div>
    </>
  );
}
