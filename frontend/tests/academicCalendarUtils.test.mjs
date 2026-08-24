import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { eventColor, eventsForDay, eventVersion, fromUtcInput, monthBounds, monthCells } from '../src/features/academic-calendar/calendarUtils.js'

test('month grid starts on Saturday and remains UTC stable', () => {
  const cells = monthCells(new Date('2026-09-01T00:00:00Z'))
  assert.equal(cells.length, 42)
  assert.equal(cells[0].date.getUTCDay(), 6)
  assert.equal(cells.find(cell => cell.key === '2026-09-01').inMonth, true)
  assert.deepEqual(monthBounds(new Date('2026-09-15T20:00:00-07:00')), {
    from: '2026-09-01T00:00:00.000Z',
    to: '2026-09-30T23:59:59.000Z',
  })
  assert.equal(fromUtcInput('2026-09-01T08:30'), '2026-09-01T08:30:00Z')
})

test('multi-day events use inclusive day intersection', () => {
  const event = { starts_at: '2026-09-01T10:00:00Z', ends_at: '2026-09-03T08:00:00Z' }
  assert.equal(eventsForDay([event], '2026-09-02').length, 1)
  assert.equal(eventsForDay([event], '2026-09-04').length, 0)
})

test('manager display prefers a replacement draft while public payload remains direct', () => {
  const event = { versions: [{ title: 'v1', publication_status: 'published' }, { title: 'v2', publication_status: 'draft' }] }
  assert.equal(eventVersion(event).title, 'v2')
  assert.equal(eventVersion({ title: 'public' }).title, 'public')
})

test('stable event codes determine presentation only', () => {
  assert.match(eventColor('theoretical_exams'), /indigo/)
  assert.match(eventColor('unknown', 'general'), /slate/)
})

test('shared routes and direct role plus permission management check are wired', () => {
  const app = readFileSync(new URL('../src/app/App.jsx', import.meta.url), 'utf8')
  const page = readFileSync(new URL('../src/features/academic-calendar/AcademicCalendarPage.jsx', import.meta.url), 'utf8')
  for (const route of ['/student/calendar', '/dean/calendar', '/professor/calendar', '/student-affairs/calendar', '/exam-board/calendar', '/academic-structure/calendar', '/hr/calendar', '/vp/scientific/calendar', '/vp/administrative/calendar']) {
    assert.match(app, new RegExp(route.replaceAll('/', '\\/')))
  }
  assert.match(page, /roles\?\.includes\(ROLES\.vicePresidentScientific\)/)
  assert.match(page, /permissions\?\.includes\(PERMISSIONS\.academicCalendarManage\)/)
})
