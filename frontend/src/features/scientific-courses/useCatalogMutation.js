import { useEffect, useRef, useState } from 'react'
import { catalogError, catalogRead, catalogWrite } from './catalog'

/** A rejected or uncertain write never adopts a new revision or retries itself. */
export default function useCatalogMutation({ currentPath, onSaved, onBusy, onBlocked, onUnauthorized, readCurrent = catalogRead, send = catalogWrite }) {
  const [state, setState] = useState({ error: null, fields: {}, blocked: false, current: null, checking: false })
  const flight = useRef(false), alive = useRef(true)
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  async function write(path, method, payload) {
    if (flight.current || state.blocked) return
    flight.current = true; onBusy(true); setState(s => ({ ...s, error: null, fields: {} }))
    try { const result = await send(path, method, payload); if (alive.current) onSaved(result) }
    catch (e) {
      if (!alive.current) return
      if (e.status === 403 || e.status === 401) { onUnauthorized(); return }
      const blocked = e.status !== 422
      setState({ error: catalogError(e), fields: e.details || {}, blocked, current: null, checking: false })
      onBlocked(blocked)
    } finally { flight.current = false; if (alive.current) onBusy(false) }
  }
  async function inspect() {
    if (flight.current) return
    setState(s => ({ ...s, checking: true }))
    try { const current = await readCurrent(currentPath); if (alive.current) setState(s => ({ ...s, current, checking: false })) }
    catch (e) { if (alive.current) setState(s => ({ ...s, checking: false, error: catalogError(e) })) }
  }
  return { ...state, write, inspect }
}
