import { useState } from 'react';
import { api, ApiError, setToken } from '../../lib/api';

export interface Me { id: number; name: string; role: string }

export function Login({ onDone }: { onDone: (me: Me) => void }) {
  const [login, setLogin] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    try {
      const r = await api<{ token: string; user: Me }>('POST', '/auth/login', { login, password });
      setToken(r.token);
      onDone(r.user);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Sign-in failed.');
    }
  };
  return (
    <div className="login">
      <form className="card" onSubmit={submit}>
        <h1 style={{ margin: 0, color: 'var(--bb)', fontSize: 20 }}>ERICA'S BABY CARE</h1>
        <p className="hint">Diapers &amp; Wipes 360 · sign in</p>
        <label htmlFor="login">Phone or email</label>
        <input id="login" value={login} onChange={(e) => setLogin(e.target.value)} autoComplete="username" />
        <label htmlFor="password">Password</label>
        <input id="password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" />
        {error && <div className="err" role="alert">{error}</div>}
        <button className="btn gold" style={{ width: '100%', marginTop: 14 }}>Sign in</button>
      </form>
    </div>
  );
}
