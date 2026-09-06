import test from 'node:test'
import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8').replace(/\r\n/g, '\n')
const page = read('../src/features/exam-board/pages/ManualGradeEntryPage.jsx')
const dialog = read('../src/features/exam-board/components/ManualGradeDialog.jsx')
const digestBlock = (source, start, end) => {
  const from = source.indexOf(start)
  assert.ok(from >= 0)
  const to = source.indexOf(end, from)
  assert.ok(to > from)
  return createHash('sha256').update(source.slice(from, to)).digest('hex')
}

test('presentation-only freeze: merged editor/page logic and native dialog lifecycle are byte-for-byte unchanged', () => {
  // Baseline: develop 4394f89479c8bb11563f1257ff77bdcaaf50aa8e (PR #126 merged).
  // Hash only logic, not imports, visual constants, or JSX. No git history needed to execute this contract.
  assert.equal(digestBlock(page, 'function RegistrationEditor(', '  return <article'), '8c9bc38c8da2d761c07b3bb8dce7512ea5c637a1cdda2f6d3d60a08ac425140e')
  assert.equal(digestBlock(page, 'export default function ManualGradeEntryPage()', '  if (!allowed) return'), '5f148391705e75ed8b16b33cac8cc852760c254737f84653a81f74ffd82c7025')
  assert.equal(digestBlock(dialog, '  const dialog =', '  return <dialog'), '0c039f0e318968fc2915f10a17359127979d597291d312ba9534d3c7dd28a671')
})

test('source contract: safety callbacks and disabled conditions survive presentation changes', () => {
  for (const binding of [
    'disabled={busy || uncertain || conflict || readOnly || !state.can_edit}',
    'disabled={busy || uncertain || conflict || readOnly || !dirty || !acknowledged}',
    'disabled={busy || uncertain || conflict || readOnly || dirty}',
    'disabled={busy || uncertain || readOnly || removedComponents}',
    'readOnly={loading || !!dataError}', 'disabled={pendingCount > 0}',
    'onChange={event => edit(c.grade_component_id, event.target.value)}',
    'checked={acknowledged}', 'onChange={event => setAcknowledged(event.target.checked)}',
    "onClick={() => resolve('discard')}", "onClick={() => resolve('rebase')}",
    'onClick={prepareSave}', 'onClick={() => readiness(part)}', 'onClick={reload}',
    'onCancel={() => setDialog(null)}', 'onConfirm={discard}',
    'onConfirm={() => { if (!busy.current.size) blocker.proceed() }}',
    "onChange={e => changeTerm('academic_year_id', e.target.value)}",
    "onChange={e => changeTerm('semester_id', e.target.value)}",
  ]) assert.ok(page.includes(binding), binding)
  for (const binding of ['<dialog', 'onCancel={event => { event.preventDefault(); if (!busy) onCancel() }}',
    'disabled={busy || disabled}', 'disabled={busy}', 'onClick={onConfirm}', 'onClick={onCancel}']) assert.ok(dialog.includes(binding), binding)
})

test('source contract: Exam Board typography, tokens, light borders and primary/secondary action hierarchy', () => {
  for (const token of ['text-[20px] font-black text-text-dark', 'text-[12.5px] text-text-light',
    'Manual grade entry', 'rounded-[16px]', 'border-primary/12', 'border-primary/10',
    'shadow-[0_2px_12px_rgba(26,46,16,0.05)]', 'rounded-[10px]', 'focus:border-primary',
    'className={primaryButton}', 'className={warningButton}', 'className={discardButton}',
    'StatusChip status={state.status}', 'StatusChip status={row.registration_status}',
    'FaChevronRight', 'FaChevronLeft', 'divide-y divide-primary/10', 'aria-pressed=',
    'FaSearch', 'FaUserGraduate', 'FaExclamationTriangle', 'FaSave', 'FaPaperPlane',
  ]) assert.ok(page.includes(token), token)
  assert.ok(page.includes('عند بدء التحرير') && page.includes('المقترح') && page.includes('الخادم'))
  assert.ok(dialog.includes("confirmTone === 'discard'"))
  // Every literal visible border has an explicit color; no browser-default/currentColor card outlines.
  for (const source of [page, dialog]) {
    for (const [, classes] of source.matchAll(/className="([^"]*)"/g)) {
      if (/(?:^|\s)border(?:\s|$)/.test(classes)) assert.match(classes, /border-(?:primary|amber|red|transparent)/)
    }
    assert.doesNotMatch(source, /text-2xl|border-black|border-gray-900|rounded-lg border px/)
  }
})

test('source contract: RTL, wrapping, mobile stacking and bounded native dialog', () => {
  for (const token of ['dir="rtl"', 'min-w-0', 'max-w-full', 'flex-wrap', 'break-words',
    'max-[560px]:grid-cols-1', 'max-[560px]:p-4', 'focus-visible:outline-primary']) assert.ok(page.includes(token), token)
  for (const token of ['max-h-[90dvh]', 'overflow-y-auto', 'w-[calc(100%_-_2rem)]',
    'flex flex-wrap justify-end', 'max-[400px]:px-4']) assert.ok(dialog.includes(token), token)
  // Static responsive checks are not browser/pixel verification at these widths.
})
