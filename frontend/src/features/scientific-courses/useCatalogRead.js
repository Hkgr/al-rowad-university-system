import { useEffect, useRef, useState } from 'react'
import { catalogRead, requestCounter } from './catalog.js'

/** Key-bound reads, including the debounce interval; abort is not the correctness proof. */
export default function useCatalogRead(path, { delay = 0, refresh = 0, loader = catalogRead } = {}) {
  const sequence = useRef(requestCounter())
  const [state, setState] = useState(null)
  const key = JSON.stringify([path, refresh])
  useEffect(() => {
    const counter = sequence.current, token = counter.next(), controller = new AbortController()
    if (!path) return () => { counter.invalidate(); controller.abort() }
    const timer = setTimeout(async () => {
      try {
        const data = await loader(path, controller.signal)
        if (counter.current(token)) setState({ key, loader, data, error: null })
      } catch (error) {
        if (counter.current(token) && !controller.signal.aborted) setState({ key, loader, data: null, error })
      }
    }, delay)
    return () => { clearTimeout(timer); counter.invalidate(); controller.abort() }
  }, [path, key, delay, loader])
  return state?.key === key && state.loader === loader ? { ...state, loading: false } : { data: null, error: null, loading: !!path }
}
