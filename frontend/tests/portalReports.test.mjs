import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { canAccess } from '../src/features/auth/auth.js'
import { reportAccess, REPORT_PATHS, reportEndpoint, reportQuery, changeReportFilter, reportLabel, reportSourceLabel } from '../src/features/portal-reports/reports.js'

test('role reports require assigned permissions and the actual source scope, not virtual admin grants',()=>{
  const dean={roles:['dean'],permissions:['students.view'],access_scopes:[{type:'college',id:1}]}
  assert.equal(canAccess(reportAccess('dean'),dean),true)
  for(const replacement of [{roles:['super_admin']},{permissions:[]},{access_scopes:[]},{access_scopes:[{type:'university',id:91}]}]) assert.equal(canAccess(reportAccess('dean'),{...dean,...replacement}),false)
  assert.equal(canAccess(reportAccess('student'),{roles:['student'],permissions:['grades.view'],student_id:1}),true)
  assert.equal(canAccess(reportAccess('student'),{roles:['student'],permissions:['grades.view']}),false)
  assert.equal(canAccess(reportAccess('professor'),{roles:['doctor_instructor'],permissions:['grades.manage'],employee_id:1}),true)
  assert.equal(canAccess(reportAccess('professor'),{roles:['dean'],permissions:['grades.manage'],employee_id:1}),false)
  assert.equal(canAccess(reportAccess('hr'),{permissions:['hr.view'],access_scopes:[{type:'program',id:1}]}),false)
  assert.equal(canAccess(reportAccess('technical'),{permissions:['system_activity.view']}),false)
})

test('period, scope and pagination state are allowlisted and dependent filters clear',()=>{
  const params=new URLSearchParams('academic_year_id=10&semester_id=2&college_id=1&program_id=2&page=3&category=active&student_id=99')
  assert.equal(reportQuery(params,{period:false,scoped:false}),'page=3&category=active')
  assert.equal(reportQuery(new URLSearchParams('semester_id=2'),{period:true,scoped:true}),'')
  assert.ok(reportQuery(params,{period:true,scoped:true}).includes('semester_id=2'))
  assert.ok(!reportQuery(params,{period:true,scoped:true}).includes('student_id'))
  assert.equal(changeReportFilter(params,'academic_year_id','11').has('semester_id'),false)
  assert.equal(changeReportFilter(params,'college_id','3').has('program_id'),false)
  assert.equal(changeReportFilter(params,'category','draft').has('page'),false)
  assert.equal(changeReportFilter(params,'report','results').toString(),'student_id=99&report=results')
  assert.equal(reportLabel(null),'غير محدد');assert.equal(reportLabel(0),'0')
})

test('all implemented shells receive guarded report navigation; no link directory or client aggregation',async()=>{
  const source=async file=>readFile(new URL('../src/'+file,import.meta.url),'utf8')
  const app=await source('app/App.jsx'),page=await source('features/portal-reports/PortalReportsPage.jsx')
  for(const portal of Object.keys(REPORT_PATHS))assert.equal(reportEndpoint(portal),portal==='ministry'?'/v1/ministry/reports':`/v1/portal-reports/${portal}`)
  for(const feature of ['student-affairs','student-dashboard','professor-dashboard','hr-dashboard','academic-structure','technical-portal','ministry-portal','exam-board'])assert.match(await source(`features/${feature}/nav.js`),/reportNav/)
  assert.match(app,/ProtectedRoute \{\.\.\.reportAccess\(portal\)\}/)
  assert.match(app,/PortalReportsPage portal="president"/)
  assert.match(page,/response\.meta\.last_page/);assert.match(page,/response\.generated_at/)
  assert.match(page,/response\.total/);assert.match(page,/response\.groups/)
  assert.match(page,/لا يملك حسابك صلاحية هذا التقرير/)
  assert.match(page,/reportSourceLabel/)
  assert.equal(reportSourceLabel.activity,'أكواد النشاط المرئية')
  assert.doesNotMatch(page,/response\.source/)
  assert.match(page,/identity=JSON\.stringify\(getIdentity\(\)\)/)
  assert.doesNotMatch(page,/method:\s*['"](?:POST|PUT|DELETE|PATCH)|\.reduce\(/)
  const hook=await source('features/ministry-portal/lib/useMinistryResource.js')
  assert.match(hook,/result\.key !== key/);assert.match(hook,/active = false/)
  const vp=await source('features/executive-reports/pages/ExecutiveReportsPage.jsx')
  assert.match(vp,/تقارير جاهزة لاختصاص النيابة/);assert.match(vp,/<details/)
})
