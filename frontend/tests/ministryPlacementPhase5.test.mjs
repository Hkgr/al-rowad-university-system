import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  reconciliationGateLabel,
  reconciliationIssueLabel,
  reconciliationSeverityLabel,
  reconciliationStateLabel,
} from '../src/features/student-affairs/lib/ministryPlacement.js'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8')
const page = read('src/features/student-affairs/pages/MinistryPlacementsPage.jsx')
const panel = read('src/features/student-affairs/components/MinistryReconciliationPanel.jsx')
const addStudent = read('src/features/student-affairs/pages/AddStudentPage.jsx')
const nav = read('src/features/student-affairs/nav.js')
const app = read('src/app/App.jsx')
const { hasActualUniversityScope, hasAssignedPermission, landingRoute, PERMISSIONS } = await import('../src/features/auth/auth.js')

assert.match(page, /setBatchView\('reconciliation'\)/, 'MINISTRY-UI-P5-01: fifth tab must exist')
assert.match(page, /<MinistryGlobalReconciliationCard/, 'MINISTRY-UI-P5-02: global gate card must exist')
assert.match(page, /<MinistryReconciliationPanel/, 'MINISTRY-UI-P5-03: batch gate panel must exist')
assert.match(panel, /production_gate/, 'MINISTRY-UI-P5-04: backend gate is authoritative')
assert.match(panel, /payload\.metrics/, 'MINISTRY-UI-P5-05: backend metrics are displayed')
assert.match(panel, /payload\.checksum/, 'MINISTRY-UI-P5-06: batch checksum is displayed')
assert.match(panel, /reconciliation_checksum/, 'MINISTRY-UI-P5-07: global checksum is displayed')
assert.match(panel, /severity/, 'MINISTRY-UI-P5-08: severity filter is present')
assert.match(panel, /pipeline_state/, 'MINISTRY-UI-P5-09: state filter is present')
assert.match(panel, /issue_code/, 'MINISTRY-UI-P5-10: issue filter is present')
assert.match(panel, /generation\.current/, 'MINISTRY-UI-P5-11: stale responses must be rejected')
assert.match(panel, /identity_conflict_multiple_terminal_records/, 'MINISTRY-UI-P5-12: multiple terminal blockers are distinguished')
assert.match(panel, /التدقيق فقط/, 'MINISTRY-UI-P5-13: page states its read-only boundary')
assert.doesNotMatch(panel, /<button[^>]*>[^<]*(إصلاح|دمج|تجاوز|فرض)/u, 'MINISTRY-UI-P5-14: no repair controls')
for (const pii of ['national_civil_id', 'phone_number', 'email', 'date_of_birth', 'first_name', 'last_name']) {
  assert.doesNotMatch(panel, new RegExp(pii), `MINISTRY-UI-P5-15: reconciliation UI must not consume ${pii}`)
}

assert.equal(reconciliationGateLabel('READY'), 'جاهز للإنتاج')
assert.equal(reconciliationGateLabel('BLOCKED'), 'الإنتاج محظور')
assert.equal(reconciliationSeverityLabel('warning'), 'تحذير')
assert.equal(reconciliationStateLabel('enrolled'), 'طالب منشأ')
assert.match(reconciliationIssueLabel({ code: 'identity_conflict_multiple_terminal_records' }), /تحقيق يدوي/)
assert.match(reconciliationIssueLabel({ code: 'identity_conflict_terminal_record' }), /تحذير تاريخي/)

assert.match(addStudent, /رفع طلاب المفاضلة/, 'MINISTRY-UX-P5-01: Add Student must expose the Ministry entry')
assert.match(addStudent, /hasAssignedPermission\(PERMISSIONS\.admissionsManage\) && hasActualUniversityScope\(\)/, 'MINISTRY-UX-P5-02: entry requires assigned manage and actual scope')
assert.match(addStudent, /navigate\('\/student-affairs\/ministry-placements'\)/, 'MINISTRY-UX-P5-03: entry uses same-tab router navigation')
assert.doesNotMatch(nav, /student-affairs\/ministry-placements/, 'MINISTRY-UX-P5-04: Ministry must not be in sidebar navigation')
assert.doesNotMatch(nav + app, /ministryPlacementNav/, 'MINISTRY-UX-P5-05: dedicated Ministry-only nav must not remain')
assert.match(app, /assignedPermissions=\{\[PERMISSIONS\.admissionsView\]\} actualUniversityScope>[\s\S]*?<DashboardLayout nav=\{studentAffairsNav\}/, 'MINISTRY-UX-P5-06: normal Student Affairs layout must wrap Ministry page')
assert.match(page, /العودة إلى إضافة طالب/, 'MINISTRY-UX-P5-07: explicit return action is required')
assert.match(page, /navigate\('\/student-affairs\/students\/add'\)/, 'MINISTRY-UX-P5-08: return action targets Add Student')
assert.match(addStudent, /<form onSubmit=\{handleSubmit\}/, 'MINISTRY-UX-P5-09: manual Student form remains')
const ministryManager = { permissions: ['admissions.view', 'admissions.manage'], access_scopes: [{ type: 'university', id: 1 }], roles: [] }
const managerWithoutScope = { permissions: ['admissions.view', 'admissions.manage'], access_scopes: [], roles: [] }
const superAdminWithoutAssignment = { permissions: [], access_scopes: [{ type: 'university', id: 1 }], roles: ['super_admin'] }
assert.equal(hasAssignedPermission(PERMISSIONS.admissionsManage, ministryManager) && hasActualUniversityScope(ministryManager), true, 'MINISTRY-UX-P5-10: assigned manage plus scope shows entry')
assert.equal(hasAssignedPermission(PERMISSIONS.admissionsManage, managerWithoutScope) && hasActualUniversityScope(managerWithoutScope), false, 'MINISTRY-UX-P5-11: missing scope hides entry')
assert.equal(hasAssignedPermission(PERMISSIONS.admissionsManage, superAdminWithoutAssignment), false, 'MINISTRY-UX-P5-12: super admin role is not an assigned Ministry permission')
assert.equal(landingRoute(ministryManager), '/student-affairs/students/add', 'MINISTRY-UX-P5-13: Ministry manager lands on the official Add Student entry')
assert.match(page, /\/v1\/ministry-placement-academic-years/, 'MINISTRY-UX-P5-10: Ministry-specific year catalog is used')
assert.match(page, /Promise\.allSettled/, 'MINISTRY-UX-P5-11: independent initial errors are supported')
assert.match(page, /تعذر تحميل دفعات المفاضلة/, 'MINISTRY-UX-P5-12: batch error is granular')
assert.match(page, /تعذر تحميل السنوات الأكاديمية/, 'MINISTRY-UX-P5-13: year error is granular')

console.log('Ministry Placement Phase 5 frontend tests passed.')
