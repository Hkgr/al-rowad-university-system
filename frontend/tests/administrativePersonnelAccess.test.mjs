import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { canAccess, PERMISSIONS } from '../src/features/auth/auth.js'
import { ROUTE_ACCESS } from '../src/features/user-guide/guideAccess.js'

const root = new URL('../src/', import.meta.url)
const source = path => readFile(new URL(path, root), 'utf8')
const person = (roles, permissions, scopes) => ({ roles, permissions, access_scopes: scopes })
const uni = [{ type: 'university', id: 1 }]
const college = [{ type: 'college', id: 10 }]
const admin = ['vice_president_administrative']

test('administrative personnel pages require role, assigned permission and actual university scope', () => {
  const paths = ['/vp/administrative/faculty', '/vp/administrative/deans']
  const privileges = [PERMISSIONS.administrativeStaffView, PERMISSIONS.administrativeDeansView]
  paths.forEach((path, i) => {
    const guards = ROUTE_ACCESS[path]
    assert.ok(guards, `${path} registered in guide access`)
    const allowed = person(admin, ['vice_presidency.administrative.access', privileges[i]], uni)
    const missingPermission = person(admin, ['vice_presidency.administrative.access'], uni)
    const wrongRole = person(['super_admin'], [privileges[i], 'vice_presidency.administrative.access'], uni)
    const wrongScope = person(admin, ['vice_presidency.administrative.access', privileges[i]], college)
    assert.ok(guards.every(guard => canAccess(guard, allowed)))
    for (const denied of [missingPermission, wrongRole, wrongScope]) {
      assert.ok(guards.some(guard => !canAccess(guard, denied)), `${path} rejects unqualified identity`)
    }
  })
})

test('management does not follow read permission, virtual super-admin permission or college scope', () => {
  for (const permission of [PERMISSIONS.administrativeStaffManage, PERMISSIONS.administrativeDeansManage]) {
    const gate = { allRoles: admin, assignedPermissions: [permission], actualUniversityScope: true }
    assert.equal(canAccess(gate, person(admin, [permission], uni)), true)
    assert.equal(canAccess(gate, person(admin, [], uni)), false)
    assert.equal(canAccess(gate, person(admin, [permission], college)), false)
    assert.equal(canAccess(gate, person(['super_admin'], [], uni)), false)
  }
})

test('sidebars and routes guard both administrative pages', async () => {
  const nav = await source('features/vice-presidency/nav.js')
  const app = await source('app/App.jsx')
  for (const [path, page] of [['/vp/administrative/faculty', 'AdministrativeFacultyPage'], ['/vp/administrative/deans', 'AdministrativeDeansPage']]) {
    assert.equal(nav.split(`to: '${path}'`).length - 1, 1)
    assert.match(app, new RegExp(`path="${path}" element=\\{protect\\(<${page} \\/>`))
  }
})
