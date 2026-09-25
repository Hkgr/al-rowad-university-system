import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { canAccess, landingRoute } from '../src/features/auth/auth.js'
import { presidentAccess, SECTIONS, RESOURCES, queryFor, changedFilter } from '../src/features/president-portal/president.js'
const president={ roles:['university_president'], permissions:['president_portal.access',...SECTIONS.map(s=>`president_portal.${s}.view`)], access_scopes:[{type:'university',id:91}] }
test('president requires actual role, assigned permissions and university scope for each section',()=>{
  for(const section of SECTIONS){
    assert.equal(canAccess(presidentAccess(section),president),true)
    for(const role of ['super_admin','dean','vice_president_scientific','ministry_observer']) assert.equal(canAccess(presidentAccess(section),{...president,roles:[role]}),false)
    assert.equal(canAccess(presidentAccess(section),{...president,access_scopes:[]}),false)
    assert.equal(canAccess(presidentAccess(section),{...president,roles:['university_president','super_admin'],permissions:['president_portal.access']}),false)
  }
})
test('landing preserves ministry confinement and other portal roles',()=>{
  assert.equal(landingRoute(president),'/president')
  assert.equal(landingRoute({...president,roles:[...president.roles,'dean']}),'/president')
  assert.equal(landingRoute({...president,roles:[...president.roles,'ministry_observer']}),'/forbidden')
  assert.equal(landingRoute({...president,roles:['dean']}),'/dean')
  assert.equal(landingRoute({...president,roles:['vice_president_scientific']}),'/vp/scientific')
  assert.equal(landingRoute({...president,roles:['vice_president_administrative']}),'/vp/administrative')
  assert.equal(landingRoute({...president,permissions:['president_portal.access','president_portal.students.view']}),'/president/students')
})
test('URL state retains active zero, exact period drilldowns and resets dependent filters',()=>{
  assert.equal(queryFor('colleges',new URLSearchParams('active=0&college_id=4&private=1')),'active=0&college_id=4')
  assert.equal(queryFor('students',new URLSearchParams('registered_year_id=11&registered_semester_id=1')),'registered_year_id=11&registered_semester_id=1')
  const p=changedFilter(new URLSearchParams('college_id=1&program_id=2&page=3'),'college_id','4')
  assert.equal(p.has('program_id'),false);assert.equal(p.has('page'),false)
  assert.equal(changedFilter(new URLSearchParams('semester_id=2'),'academic_year_id','11').has('semester_id'),false)
})
test('all sections use guarded shared shell and independent API, no mutation controls',async()=>{
  const app=await readFile(new URL('../src/app/App.jsx',import.meta.url),'utf8')
  const page=await readFile(new URL('../src/features/president-portal/PresidentPages.jsx',import.meta.url),'utf8')
  const nav=await readFile(new URL('../src/features/president-portal/nav.jsx',import.meta.url),'utf8')
  assert.match(app,/ProtectedRoute \{\.\.\.presidentAccess\(\)\}/)
  assert.match(app,/presidentAccess\(config\[1\]\)/)
  for(const section of SECTIONS) assert.ok(nav.includes(`'${section}'`))
  assert.equal(Object.keys(RESOURCES).length,9)
  assert.match(page,/\/v1\/president\//);assert.doesNotMatch(page,/method:\s*['"](POST|PUT|DELETE|PATCH)/)
  assert.match(page,/MiniTable rows=\{rows\}/);assert.match(page,/authKey\(\)/)
})
