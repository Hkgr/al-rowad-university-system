export const EVENT_COLORS = Object.freeze({
  admission_registration: 'bg-sky-100 text-sky-800 border-sky-300',
  course_registration: 'bg-emerald-100 text-emerald-800 border-emerald-300',
  withdrawal: 'bg-orange-100 text-orange-800 border-orange-300',
  study_period: 'bg-lime-100 text-lime-800 border-lime-300',
  exam_preparation: 'bg-amber-100 text-amber-900 border-amber-300',
  practical_exams: 'bg-violet-100 text-violet-800 border-violet-300',
  theoretical_exams: 'bg-indigo-100 text-indigo-800 border-indigo-300',
  grade_appeals: 'bg-rose-100 text-rose-800 border-rose-300',
  supplementary_exams: 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-300',
  university_break: 'bg-teal-100 text-teal-800 border-teal-300',
  preparation_period: 'bg-yellow-100 text-yellow-900 border-yellow-300',
  holiday: 'bg-red-100 text-red-800 border-red-300',
  general_event: 'bg-slate-100 text-slate-800 border-slate-300',
})

export function eventColor(code, kind = 'general') {
  return EVENT_COLORS[code] || (kind === 'system' ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-slate-100 text-slate-800 border-slate-300')
}

export function monthBounds(cursor) {
  const year = cursor.getUTCFullYear()
  const month = cursor.getUTCMonth()
  return {
    from: new Date(Date.UTC(year, month, 1, 0, 0, 0)).toISOString(),
    to: new Date(Date.UTC(year, month + 1, 0, 23, 59, 59)).toISOString(),
  }
}

export function monthCells(cursor) {
  const year = cursor.getUTCFullYear()
  const month = cursor.getUTCMonth()
  const first = new Date(Date.UTC(year, month, 1))
  const saturdayOffset = (first.getUTCDay() + 1) % 7
  const start = new Date(Date.UTC(year, month, 1 - saturdayOffset))
  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start)
    date.setUTCDate(start.getUTCDate() + index)
    return { date, key: date.toISOString().slice(0, 10), inMonth: date.getUTCMonth() === month }
  })
}

export function eventVersion(event) {
  if (!event.versions) return event
  return event.versions.find(version => version.publication_status === 'draft')
    || event.versions.find(version => version.publication_status === 'published')
    || event.versions[0]
}

export function eventsForDay(events, dayKey) {
  const dayStart = `${dayKey}T00:00:00.000Z`
  const dayEnd = `${dayKey}T23:59:59.999Z`
  return events.filter(event => {
    const version = eventVersion(event)
    return version?.starts_at <= dayEnd && version?.ends_at >= dayStart
  })
}

export function toUtcInput(iso) {
  return iso ? new Date(iso).toISOString().slice(0, 16) : ''
}

export function fromUtcInput(value) {
  return value ? `${value}:00Z` : null
}
