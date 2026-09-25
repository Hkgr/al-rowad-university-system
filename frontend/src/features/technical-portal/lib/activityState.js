// Pure helpers for the Technical Office activity log (no React, no network).

export const OUTCOME_AR = Object.freeze({ success: 'ناجح', failed: 'مرفوض أو فاشل' })
export const OUTCOME_BADGE = Object.freeze({ success: 'bg-green-100 text-green-700', failed: 'bg-red-100 text-red-600' })
export const SOURCE_AR = Object.freeze({ activity: 'حدث تدقيق', login: 'تسجيل دخول' })

export const EMPTY_FILTERS = Object.freeze({ search: '', module: '', action: '', actor: '', from: '', to: '', page: 1 })

const isDate = value => /^\d{4}-\d{2}-\d{2}$/.test(String(value ?? ''))

/** Build the list query; drops empty/invalid values, never sends a reversed period. */
export function buildActivityQuery(filters = {}, perPage = 25) {
  const params = new URLSearchParams({ page: String(Math.max(1, Number(filters.page) || 1)), per_page: String(perPage) })
  for (const key of ['search', 'module', 'action', 'actor']) {
    const value = String(filters[key] ?? '').trim()
    if (value) params.set(key, value)
  }
  const from = isDate(filters.from) ? filters.from : ''
  const to = isDate(filters.to) ? filters.to : ''
  if (from && to && from > to) {
    params.set('from', to)
    params.set('to', from)
  } else {
    if (from) params.set('from', from)
    if (to) params.set('to', to)
  }
  return params.toString()
}

export function hasActiveFilters(filters = {}) {
  return ['search', 'module', 'action', 'actor', 'from', 'to'].some(key => String(filters[key] ?? '').trim() !== '')
}

/** Actions offered for the selected module (all when none is selected). */
export function actionsForModule(actions = [], module = '') {
  return module ? actions.filter(action => action.module === module) : actions
}

export function formatActivityTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleString('ar-SY', { dateStyle: 'medium', timeStyle: 'short' })
}

/** Readable text of one change: values may be hidden by the server for sensitive fields. */
export function changeText(change) {
  if (!change) return ''
  if (change.values_hidden) return `${change.label}: تغيّر (القيمة غير معروضة)`
  const from = change.from === null || change.from === undefined || change.from === '' ? '—' : change.from
  const to = change.to === null || change.to === undefined || change.to === '' ? '—' : change.to
  return `${change.label}: ${from} ← ${to}`
}

/** Map an API error to an Arabic message for the page. */
export function activityErrorMessage(error) {
  const status = error?.status ?? 0
  if (!status) return 'تعذّر الاتصال بالخادم.'
  if (status === 401) return 'انتهت الجلسة، يرجى تسجيل الدخول مجددًا.'
  if (status === 403) return 'لا يملك حسابك صلاحية عرض سجل النشاط، أو سُحبت منك مؤخرًا.'
  if (status === 404) return 'الحدث غير موجود أو خارج نطاق ما يمكنك عرضه.'
  if (status === 422) return 'قيمة تصفية غير صالحة؛ راجع التواريخ والحقول.'
  return 'حدث خطأ غير متوقع في الخادم.'
}
