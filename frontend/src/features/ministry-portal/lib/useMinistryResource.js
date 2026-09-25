import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'

/**
 * Loads a ministry resource with cancellation. `loading` is derived (the stored result
 * belongs to another key), so no state is set synchronously inside the effect.
 * 401 sends the user to the login page.
 */
export function useMinistryResource(loader, deps) {
  const navigate = useNavigate()
  const [revision, setRevision] = useState(0)
  const key = JSON.stringify([...deps, revision])
  const [result, setResult] = useState({ key: null, data: null, error: null })
  const reload = useCallback(() => setRevision(value => value + 1), [])

  useEffect(() => {
    let active = true
    loader()
      .then(response => { if (active) setResult({ key, data: response, error: null }) })
      .catch(error => {
        if (!active) return
        if (error.status === 401) { navigate('/login', { replace: true }); return }
        setResult({ key, data: null, error })
      })
    return () => { active = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  const loading = result.key !== key
  return { data: loading ? null : result.data, error: loading ? null : result.error, loading, reload }
}
