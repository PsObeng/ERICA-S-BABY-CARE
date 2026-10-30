import { useState } from 'react';
import { Login, type Me } from './features/auth/Login';
import { PosScreen } from './features/pos/PosScreen';

export default function App() {
  const [me, setMe] = useState<Me | null>(null);
  return me ? <PosScreen /> : <Login onDone={setMe} />;
}
