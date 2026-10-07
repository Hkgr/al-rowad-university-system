import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker } from 'react-router-dom'
import { getIdentity } from '../auth/auth'

/** Single active-page blocker. Ref controls update before a success navigates. */
export default function useEntityEditorGuard(allowed) {
  const controls = useRef({ dirty: false, busy: false, blocked: false })
  const [state, setState] = useState({ dirty: false, busy: false, blocked: false }), [transition, setTransition] = useState(null), [notice, setNotice] = useState('')
  const mark = useCallback((key, value) => { controls.current = { ...controls.current, [key]: value }; setState(controls.current) }, [])
  const reset = useCallback(() => { controls.current = { dirty: false, busy: false, blocked: false }; setState(controls.current) }, [])
  const blocker = useBlocker(() => allowed(getIdentity()) && Object.values(controls.current).some(Boolean))
  useEffect(() => { const before = event => { if (allowed(getIdentity()) && Object.values(controls.current).some(Boolean)) { event.preventDefault(); event.returnValue = '' } }; window.addEventListener('beforeunload', before); return () => window.removeEventListener('beforeunload', before) }, [allowed])
  const go = action => { if (controls.current.busy) { setNotice('انتظر نتيجة الحفظ قبل الانتقال.'); return }; if (controls.current.dirty || controls.current.blocked) setTransition(() => action); else action() }
  const stay = () => { setTransition(null); if (blocker.state === 'blocked') blocker.reset() }
  const discard = () => { const action = transition; setTransition(null); reset(); if (blocker.state === 'blocked') blocker.proceed(); else action?.() }
  return { ...state, controls, reset, go, notice, setNotice, navigationBlocked: !!transition || blocker.state === 'blocked', stay, discard,
    onDirty: v => mark('dirty', v), onBusy: v => mark('busy', v), onBlocked: v => mark('blocked', v) }
}
