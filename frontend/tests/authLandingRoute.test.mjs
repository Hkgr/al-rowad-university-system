import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { landingRoute } from '../src/features/auth/auth.js'

test('anonymous landing resolves to login while authenticated fallback remains forbidden', () => {
  assert.equal(landingRoute(null), '/login')
  assert.equal(landingRoute(undefined), '/login')
  assert.equal(landingRoute({ roles: [], permissions: [], access_scopes: [] }), '/forbidden')
})

test('existing portal role and identity landing precedence remains unchanged', () => {
  assert.equal(landingRoute({ roles: ['dean'], permissions: [] }), '/dean')
  assert.equal(landingRoute({ roles: ['exam_officer'], permissions: [] }), '/exam-board')
  assert.equal(landingRoute({ roles: ['registration_officer'], permissions: [] }), '/student-affairs')
  assert.equal(landingRoute({ roles: ['vice_president_scientific'], permissions: [] }), '/vp/scientific')
  assert.equal(landingRoute({ roles: ['vice_president_administrative'], permissions: [] }), '/vp/administrative')
  assert.equal(landingRoute({ roles: [], permissions: ['attendance.manage'], employee_id: 8 }), '/professor')
  assert.equal(landingRoute({ roles: [], permissions: ['registration.view'], student_id: 9 }), '/student')
})

test('root and wildcard use the landing resolver and protected routes still reject anonymous access', async () => {
  const source = await readFile(new URL('../src/app/App.jsx', import.meta.url), 'utf8')

  assert.match(source, /<Route path="\/"\s+element=\{<Navigate to=\{landingRoute\(getIdentity\(\)\)\} replace \/>\} \/>/)
  assert.match(source, /<Route path="\*"\s+element=\{<Navigate to=\{landingRoute\(getIdentity\(\)\)\} replace \/>\} \/>/)
  assert.match(source, /if \(!token\) return <Navigate to="\/login" replace \/>/)
  assert.match(source, /if \(!identity\) return <Navigate to="\/login" replace \/>/)
  assert.match(source, /return allowed \? children : <Navigate to="\/forbidden" replace \/>/)
})
