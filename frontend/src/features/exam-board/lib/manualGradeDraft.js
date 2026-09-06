import { changedComponents } from './manualGradeEntry.js'

export const createGradeDraft = row => ({ baseline: row, server: row, edits: {}, version: 0, conflict: false })

export function hasGradeDraft(draft) {
  try { return changedComponents(draft.baseline.components, draft.edits).length > 0 }
  catch { return true }
}

// Server refreshes never rebase an outstanding proposal. Only explicit operator decisions do.
export function gradeDraftReducer(draft, action) {
  switch (action.type) {
    case 'edit':
      return { ...draft, edits: { ...draft.edits, [action.id]: action.value }, version: draft.version + 1 }
    case 'receive':
      if (hasGradeDraft(draft) || draft.conflict) return {
        ...draft, server: action.row, conflict: draft.conflict || action.row.revision !== draft.baseline.revision,
      }
      return { ...createGradeDraft(action.row), version: draft.version }
    case 'conflict':
      return { ...draft, conflict: true }
    case 'discard':
      return { ...createGradeDraft(draft.server), version: draft.version + 1 }
    case 'rebase':
      // Preserve even removed component IDs for operator review; never silently omit them from a write.
      if (Object.keys(draft.edits).some(id => !draft.server.components.some(c => String(c.grade_component_id) === id))) return draft
      return { ...draft, baseline: draft.server, conflict: false, version: draft.version + 1 }
    case 'saved':
      // A response acknowledges only the exact draft that was sent, not another registration or newer edits.
      if (action.version !== draft.version || action.revision !== draft.baseline.revision) return draft
      return { ...createGradeDraft(action.row), version: draft.version + 1 }
    default:
      return draft
  }
}

export function navigationDecision({ authorized, dirty, pending }) {
  if (!authorized) return 'allow' // Authorization cleanup must never wait for draft confirmation.
  if (pending) return 'wait'
  return dirty ? 'confirm' : 'allow'
}
