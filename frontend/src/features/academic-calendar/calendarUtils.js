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

export const UNIVERSITY_TIME_ZONE = import.meta.env?.VITE_UNIVERSITY_TIME_ZONE || 'Asia/Damascus'

const zonedPartsFormatter = new Intl.DateTimeFormat('en-CA', {
  timeZone: UNIVERSITY_TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  hourCycle: 'h23',
})

function zonedParts(date) {
  return Object.fromEntries(
    zonedPartsFormatter.formatToParts(date)
      .filter(part => part.type !== 'literal')
      .map(part => [part.type, Number(part.value)]),
  )
}

function timeZoneOffset(timestamp) {
  const date = new Date(timestamp)
  const parts = zonedParts(date)
  const representedAsUtc = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second)
  return representedAsUtc - Math.floor(timestamp / 1000) * 1000
}

export function eventColor(code, kind = 'general') {
  return EVENT_COLORS[code] || (kind === 'system' ? 'bg-blue-100 text-blue-800 border-blue-300' : 'bg-slate-100 text-slate-800 border-slate-300')
}

export function monthBounds(cursor) {
  const year = cursor.getUTCFullYear()
  const month = cursor.getUTCMonth()
  const nextMonth = new Date(Date.UTC(year, month + 1, 1))
  const from = fromUniversityInput(`${year}-${String(month + 1).padStart(2, '0')}-01T00:00`)
  const next = fromUniversityInput(`${nextMonth.getUTCFullYear()}-${String(nextMonth.getUTCMonth() + 1).padStart(2, '0')}-01T00:00`)
  return {
    from,
    to: new Date(new Date(next).getTime() - 1).toISOString(),
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
  const dayStart = new Date(fromUniversityInput(`${dayKey}T00:00`)).getTime()
  const abstractDay = new Date(`${dayKey}T00:00:00.000Z`)
  abstractDay.setUTCDate(abstractDay.getUTCDate() + 1)
  const nextDayKey = abstractDay.toISOString().slice(0, 10)
  const dayEnd = new Date(fromUniversityInput(`${nextDayKey}T00:00`)).getTime() - 1
  return events.filter(event => {
    const version = eventVersion(event)
    return version && new Date(version.starts_at).getTime() <= dayEnd && new Date(version.ends_at).getTime() >= dayStart
  })
}

export function universityDateKey(value = new Date()) {
  const parts = zonedParts(new Date(value))
  return `${parts.year}-${String(parts.month).padStart(2, '0')}-${String(parts.day).padStart(2, '0')}`
}

export function toUniversityInput(iso) {
  if (!iso) return ''
  const parts = zonedParts(new Date(iso))
  return `${parts.year}-${String(parts.month).padStart(2, '0')}-${String(parts.day).padStart(2, '0')}T${String(parts.hour).padStart(2, '0')}:${String(parts.minute).padStart(2, '0')}`
}

export function fromUniversityInput(value) {
  if (!value) return null
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value)
  if (!match) return null
  const [, year, month, day, hour, minute] = match.map(Number)
  const wallClockAsUtc = Date.UTC(year, month - 1, day, hour, minute, 0)
  let timestamp = wallClockAsUtc
  for (let attempt = 0; attempt < 3; attempt += 1) {
    const corrected = wallClockAsUtc - timeZoneOffset(timestamp)
    if (corrected === timestamp) break
    timestamp = corrected
  }
  return new Date(timestamp).toISOString()
}

export function statusBadgeKind(publicationStatus, cancelled, canManage) {
  if (cancelled) return 'cancelled'
  return canManage && publicationStatus ? publicationStatus : null
}

export function withOptionalChangeReason(payload, reasonRequired = false) {
  const normalized = { ...payload }
  const reason = typeof normalized.change_reason === 'string' ? normalized.change_reason.trim() : ''
  if (reason) normalized.change_reason = reason
  else if (!reasonRequired) delete normalized.change_reason
  return normalized
}
