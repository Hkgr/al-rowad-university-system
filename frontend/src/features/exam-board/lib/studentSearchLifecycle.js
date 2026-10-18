// Intent changes invalidate reads synchronously, before React effects/debounce run.
export function studentSearchLifecycle() {
  let query = '', version = 0, controller = null
  const invalidate = () => { version += 1; controller?.abort(); controller = null }
  const valid = snapshot => !!snapshot && snapshot.version === version && snapshot.query === query
  return {
    change(value) {
      const normalized = value.trim()
      if (normalized === query) return null
      invalidate(); query = normalized
      return { query, page: 1, version }
    },
    paginate(applied, page) {
      if (!valid(applied) || !query) return null
      invalidate()
      return { query, page, version }
    },
    bind(snapshot, abort) {
      if (!valid(snapshot)) { abort.abort(); return false }
      controller = abort
      return true
    },
    valid,
    invalidate,
  }
}
