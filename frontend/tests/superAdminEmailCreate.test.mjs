import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { ACCESS, canAccess, hasAssignedPermission, hasActualUniversityScope, hasRole, isSuperAdmin, landingRoute } from '../src/features/auth/auth.js'
import { reportAccess } from '../src/features/portal-reports/reports.js'
import { canAccessExecutiveReports } from '../src/features/executive-reports/access.js'
import { printableCredentials, provisioningFailure } from '../src/features/technical-portal/lib/emailProvisioning.js'
const admin = { roles: ['super_admin'], permissions: [], access_scopes: [], is_super_admin: true }
const source = path => readFileSync(new URL('../src/'+path, import.meta.url), 'utf8')
test('administrator reaches all administrative gates without fabricated assignments', () => {
  for (const access of Object.values(ACCESS)) assert.equal(canAccess(access, admin), true)
  for (const portal of ['president','ministry','dean','student-affairs','admissions','exam-board','professor','hr','academic-structure','technical']) assert.equal(canAccess(reportAccess(portal), admin), true, portal)
  for (const office of ['scientific','administrative']) assert.equal(canAccessExecutiveReports(office, admin), true)
  assert.equal(landingRoute(admin), '/technical')
  assert.equal(hasRole('technical_team', admin), false)
  assert.equal(hasAssignedPermission('university_email.provision', admin), false)
  assert.equal(hasActualUniversityScope(admin), false)
  assert.equal(canAccess({ allRoles: ['dean'], assignedPermissions: ['p'], actualScopeTypes: ['college'], actualAcademicScope: true }, admin), true)
})
test('self identity, revoked authority and invalid contexts fail closed', () => {
  assert.equal(canAccess({ studentIdentity: true, allRoles: ['student'] }, admin), false)
  assert.equal(canAccess({ employeeIdentity: true, allRoles: ['doctor_instructor'] }, admin), false)
  assert.equal(canAccess(reportAccess('student'), admin), false)
  assert.equal(canAccessExecutiveReports('invalid', admin), false)
  assert.equal(canAccess(reportAccess('invalid'), admin), false)
  assert.equal(isSuperAdmin({ ...admin, is_super_admin: false }), false)
  assert.equal(canAccess(ACCESS.universityEmail, { ...admin, is_super_admin: false }), false)
  assert.equal(canAccess(ACCESS.universityEmail, { roles: ['technical_team'], permissions: [] }), false)
  assert.equal(canAccess(ACCESS.universityEmail, { roles: ['ministry_observer'], permissions: ['university_email.view'] }), false)
})
test('credentials require exact confirmation and cannot be recovered from a refreshed state', () => {
  const c = { operation_id: 'a', generation: 1, kind: 'create', password: 'synthetic' }
  const state = { provisioning_status: 'created', credential_operation_id: 'a', operations: [{ operation_id: 'a', generation: 1, status: 'confirmed' }] }
  assert.equal(printableCredentials(state, c), true)
  assert.equal(printableCredentials(state, null), false)
  assert.equal(printableCredentials({ ...state, provisioning_status: 'draft' }, c), false)
  assert.equal(printableCredentials({ ...state, operations: [{ ...state.operations[0], status: 'uncertain' }] }, c), false)
  assert.equal(printableCredentials(state, { ...c, generation: 2 }), false)
})
test('one guarded create dialog reuses current UI and keeps exceptional diagnostics and receipts', () => {
  const dialog = source('features/technical-portal/components/UniversityEmailMailboxDialog.jsx')
  const page = source('features/technical-portal/pages/UniversityEmailPage.jsx')
  const manager = source('features/technical-portal/components/UniversityEmailProvisioning.jsx')
  assert.match(dialog, /UniversityEmailDialog/)
  assert.match(dialog, /sendCreation\(api, state, name, apiRequest\)/)
  assert.match(dialog, /writing\.current \|\| blocked/)
  assert.match(dialog, /!\['ready', 'retry', 'deleted'\]\.includes\(mode\)/)
  assert.match(dialog, /setCredentials\(null\); setReceipt\(null\)/)
  assert.match(dialog, /onSensitive\(false\)/)
  assert.match(dialog, /json\.data\.credentials/)
  assert.match(dialog, /تنزيل الإيصال PDF/)
  assert.match(page, /Workspace key=\{identity\}/)
  assert.match(page, /UniversityEmailMailboxDialog/)
  assert.match(manager, /!simple && mayCreate/)
  assert.match(manager, /تفاصيل متقدمة/)
  assert.doesNotMatch(page+dialog, /حفظ المسودة|\/provisioning\/password|localStorage\.setItem|sessionStorage\.setItem|retry\(/)
  const nav = source('features/auth/adminPortalNav.js')
  for (const path of ['/technical','/president','/ministry','/vp/scientific','/vp/administrative','/dean','/student-affairs','/exam-board','/hr','/academic-structure','/professor/reports']) assert.ok(nav.includes(`'${path}'`), path)
  assert.match(source('components/layout/DashboardLayout.jsx'), /isSuperAdmin/)
})
test('basic creation and navigation notices never describe internal drafts', () => {
  const page = source('features/technical-portal/pages/UniversityEmailPage.jsx')
  const dialog = source('features/technical-portal/components/UniversityEmailMailboxDialog.jsx')
  assert.doesNotMatch(page + dialog, /مسود/)
  assert.match(page, /بيانات إنشاء غير مكتملة/)
  assert.match(page, /لديك بيانات إنشاء لم تُنفذ بعد\. المغادرة ستلغي الاسم المدخل\. هل تريد المتابعة؟/)
  assert.match(dialog, /إنشاء البريد غير مفعّل على الخادم بعد/)
  for (const error of [{}, { status: 422 }, { status: 503, errorCode: 'university_email_provisioning_disabled' }]) {
    assert.doesNotMatch(provisioningFailure(error), /مسود|العملية|draft|operation|generation|write_started_at/)
  }
  assert.equal(provisioningFailure({ status: 503, errorCode: 'university_email_provisioning_disabled' }), 'إنشاء البريد غير مفعّل على الخادم بعد.')
})
