import assert from 'node:assert/strict'
import test from 'node:test'
import { HR, canHr, hrAccess, payrollAccess, proposalPayload, requestEditable, changeIntent, responseCurrent } from '../src/features/hr-office/lib/hrOffice.js'
import { canAccess, landingRoute } from '../src/features/auth/auth.js'
const hr = { roles: ['hr_officer'], permissions: Object.values(HR), access_scopes: [{ type: 'college', id: 1 }] }
test('actual HR role + assigned permission + actual scope, central administrator unchanged', () => {
  assert.ok(canHr('view', hr)); assert.ok(!canHr('view', { ...hr, roles: [] })); assert.ok(!canHr('view', { ...hr, permissions: [] })); assert.ok(!canHr('view', { ...hr, access_scopes: [] }))
  assert.ok(!canHr('view', { ...hr, access_scopes: [{ type: 'program', id: 1 }] }))
  assert.ok(!canHr('review', hr)); assert.ok(canHr('review', { ...hr, roles: ['vice_president_administrative'], access_scopes: [{ type: 'university', id: 1 }] }))
  assert.ok(canAccess(hrAccess(HR.view), { roles: ['super_admin'], permissions: [], access_scopes: [] }))
})
test('payroll entry does not grant financial actions, owner role, or university scope', () => {
  assert.ok(!canAccess(payrollAccess('owner_payroll.view'), hr))
  assert.ok(!canAccess(payrollAccess('owner_payroll.view'), { ...hr, access_scopes: [{ type: 'university', id: 1 }] }))
  const finance = { ...hr, access_scopes: [{ type: 'university', id: 1 }], permissions: [HR.payrollAccess, 'owner_payroll.view'] }
  assert.ok(canAccess(payrollAccess('owner_payroll.view'), finance)); assert.ok(!canAccess(payrollAccess('owner_payroll.amounts.edit'), finance))
  assert.equal(landingRoute({ ...finance, roles: ['finance_officer'] }), '/vp/administrative/payroll')
})
test('explicit placement and zero/null handling; no invented dates or mode', () => {
  const p = proposalPayload({ body: 'educational', college_id: '2', organizational_unit_id: '99', ends_on: '', position_id: '', employee_type_id: '', predecessor_id: '', starts_on: '', work_mode: '' })
  assert.equal(p.college_id, 2); assert.equal(p.organizational_unit_id, null); assert.equal(p.ends_on, null); assert.equal(p.starts_on, ''); assert.equal(p.work_mode, '')
})
test('workflow state, pending navigation, current-response guards', () => {
  for (const status of ['submitted', 'approved', 'rejected']) assert.ok(!requestEditable({ status }))
  assert.ok(requestEditable({ status: 'returned' })); assert.ok(!requestEditable({ status: 'draft', materialized_at: '2026-10-09' }))
  assert.equal(changeIntent('workers', 'workers', true, true), 'noop'); assert.equal(changeIntent('workers', 'needs', true, false), 'confirm'); assert.equal(changeIntent('workers', 'needs', false, true), 'blocked')
  assert.ok(responseCurrent(3, 3, 'a', 'a')); assert.ok(!responseCurrent(2, 3, 'a', 'a')); assert.ok(!responseCurrent(3, 3, 'a', 'b'))
})
