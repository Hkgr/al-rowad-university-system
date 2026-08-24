import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  buildCourseCollegeMap,
  fetchAllPaginated,
  filterCoursesByAffiliation,
  planDepartmentLinkSync,
} from '../src/features/exam-board/lib/courseCatalog.js'

function paginatedRequest(rows, { failPage, mutatePage } = {}) {
  const calls = []
  const request = async (path) => {
    const url = new URL(path, 'https://catalog.test')
    const page = Number(url.searchParams.get('page'))
    const perPage = Number(url.searchParams.get('per_page'))
    calls.push({ page, perPage })
    if (page === failPage) throw new Error('page failed')

    const response = {
      success: true,
      data: {
        data: rows.slice((page - 1) * perPage, page * perPage),
        meta: {
          current_page: page,
          per_page: perPage,
          total: rows.length,
          last_page: Math.max(1, Math.ceil(rows.length / perPage)),
        },
      },
    }

    return mutatePage ? mutatePage(response, page) : response
  }

  return { request, calls }
}

test('loads the complete production-sized catalog and fixes the page-two classification regression', async () => {
  const courses = Array.from({ length: 111 }, (_, index) => ({ course_id: index + 1 }))
  const assignments = Array.from({ length: 182 }, (_, index) => ({
    course_department_id: index + 1,
    course_id: (index % 110) + 2,
    department_id: 18 + (index % 3),
  }))
  assignments.push({ course_department_id: 183, course_id: 1, department_id: 18 })
  const departments = [18, 19, 20].map(department_id => ({ department_id, college_id: 10 }))
  const courseApi = paginatedRequest(courses)
  const assignmentApi = paginatedRequest(assignments)

  const loadedCourses = await fetchAllPaginated('/v1/courses', {
    request: courseApi.request,
    primaryKey: 'course_id',
  })
  const loadedAssignments = await fetchAllPaginated('/v1/course-departments', {
    request: assignmentApi.request,
    primaryKey: 'course_department_id',
  })
  const collegeMap = buildCourseCollegeMap(loadedCourses, departments, loadedAssignments)

  assert.equal(loadedCourses.length, 111)
  assert.equal(loadedAssignments.length, 183)
  assert.deepEqual(courseApi.calls, [{ page: 1, perPage: 100 }, { page: 2, perPage: 100 }])
  assert.deepEqual(assignmentApi.calls, [{ page: 1, perPage: 100 }, { page: 2, perPage: 100 }])
  assert.deepEqual([...collegeMap.get('1')], ['10'])
  assert.equal(filterCoursesByAffiliation(loadedCourses, collegeMap, 'unlinked').length, 0)
  assert.equal([...collegeMap.values()].every(colleges => colleges.size === 1 && colleges.has('10')), true)
})

test('multiple department and college mappings keep one course card', () => {
  const courses = [{ course_id: 1 }, { course_id: 2 }]
  const departments = [
    { department_id: 18, college_id: 10 },
    { department_id: 21, college_id: 11 },
  ]
  const assignments = [
    { course_department_id: 1, course_id: 1, department_id: 18 },
    { course_department_id: 2, course_id: 1, department_id: 21 },
  ]
  const collegeMap = buildCourseCollegeMap(courses, departments, assignments)

  assert.deepEqual([...collegeMap.get('1')].sort(), ['10', '11'])
  assert.deepEqual(filterCoursesByAffiliation(courses, collegeMap, '10'), [courses[0]])
  assert.deepEqual(filterCoursesByAffiliation(courses, collegeMap, '11'), [courses[0]])
  assert.deepEqual(filterCoursesByAffiliation(courses, collegeMap, 'unlinked'), [courses[1]])
})

test('page failure and changing or incomplete pagination fail explicitly', async () => {
  const rows = Array.from({ length: 111 }, (_, index) => ({ course_id: index + 1 }))
  const failing = paginatedRequest(rows, { failPage: 2 })
  await assert.rejects(
    fetchAllPaginated('/v1/courses', { request: failing.request, primaryKey: 'course_id' }),
    error => (
      error.code === 'catalog_page_failed'
      && error.message.includes('courses (/v1/courses)')
      && error.message.includes('الصفحة 2')
    ),
  )

  const changing = paginatedRequest(rows, {
    mutatePage(response, page) {
      if (page === 2) response.data.meta.total = 112
      return response
    },
  })
  await assert.rejects(
    fetchAllPaginated('/v1/courses', { request: changing.request, primaryKey: 'course_id' }),
    error => error.code === 'catalog_pagination_inconsistent' || error.code === 'catalog_snapshot_changed',
  )

  const incomplete = paginatedRequest(rows, {
    mutatePage(response, page) {
      if (page === 2) response.data.data = []
      return response
    },
  })
  await assert.rejects(
    fetchAllPaginated('/v1/courses', { request: incomplete.request, primaryKey: 'course_id' }),
    error => error.code === 'catalog_total_mismatch',
  )
})

test('page failure identifies the collection, page, and safe upstream HTTP context', async () => {
  const upstream = Object.assign(new Error('Unexpected error occurred'), {
    status: 500,
    errorCode: 'unexpected_error',
  })
  await assert.rejects(
    fetchAllPaginated('/v1/courses', {
      request: async () => { throw upstream },
      primaryKey: 'course_id',
    }),
    error => (
      error.code === 'catalog_page_failed'
      && error.message.includes('courses (/v1/courses)')
      && error.message.includes('الصفحة 1')
      && error.message.includes('HTTP 500')
      && error.message.includes('Unexpected error occurred')
      && error.message.includes('unexpected_error')
    ),
  )

  const unsafe = Object.assign(new Error('Bearer secret-token-value'), { status: 500 })
  await assert.rejects(
    fetchAllPaginated('/v1/courses', {
      request: async () => { throw unsafe },
      primaryKey: 'course_id',
    }),
    error => error.code === 'catalog_page_failed' && !error.message.includes('secret-token-value'),
  )
})

test('deduplication cannot silently hide duplicate or missing rows', async () => {
  const rows = Array.from({ length: 101 }, (_, index) => ({ course_id: index + 1 }))
  const duplicate = paginatedRequest(rows, {
    mutatePage(response, page) {
      if (page === 2) response.data.data = [{ course_id: 100 }]
      return response
    },
  })

  await assert.rejects(
    fetchAllPaginated('/v1/courses', { request: duplicate.request, primaryKey: 'course_id' }),
    error => error.code === 'catalog_total_mismatch',
  )

  const missingKey = paginatedRequest([{ course_id: 1 }, { course_name: 'missing id' }])
  await assert.rejects(
    fetchAllPaginated('/v1/courses', { request: missingKey.request, primaryKey: 'course_id' }),
    error => error.code === 'catalog_primary_key_missing',
  )
})

test('page and item safety limits stop unbounded collection', async () => {
  const request = async () => ({
    success: true,
    data: {
      data: Array.from({ length: 100 }, (_, index) => ({ course_id: index + 1 })),
      meta: { current_page: 1, per_page: 100, total: 10100, last_page: 101 },
    },
  })

  await assert.rejects(
    fetchAllPaginated('/v1/courses', { request, primaryKey: 'course_id' }),
    error => error.code === 'catalog_safety_limit_exceeded',
  )
})

test('department-link plan preserves one primary and never marks multiple additions primary', () => {
  const existingPrimary = { course_department_id: 1, department_id: 18, is_primary: true }
  const existingSecondary = { course_department_id: 2, department_id: 19, is_primary: false }

  assert.deepEqual(planDepartmentLinkSync([existingPrimary], [18, 19, 20]), {
    toAdd: ['19', '20'],
    toRemove: [],
    promoteDepartmentId: null,
  })
  assert.deepEqual(planDepartmentLinkSync([existingPrimary, existingSecondary], [19]), {
    toAdd: [],
    toRemove: [existingPrimary],
    promoteDepartmentId: '19',
  })
  assert.deepEqual(planDepartmentLinkSync([], [18, 19]), {
    toAdd: ['18', '19'],
    toRemove: [],
    promoteDepartmentId: null,
  })
  assert.deepEqual(planDepartmentLinkSync([], [18]), {
    toAdd: ['18'],
    toRemove: [],
    promoteDepartmentId: '18',
  })
})

test('courses page uses the complete loader, common API client, and truthful failure semantics', () => {
  const page = readFileSync(
    new URL('../src/features/exam-board/pages/CoursesPage.jsx', import.meta.url),
    'utf8',
  )

  assert.match(page, /import \{ apiRequest \} from '\.\.\/\.\.\/\.\.\/services\/apiClient'/)
  for (const [path, key] of [
    ['/v1/courses', 'course_id'],
    ['/v1/colleges', 'college_id'],
    ['/v1/departments', 'department_id'],
    ['/v1/course-departments', 'course_department_id'],
  ]) {
    assert.match(page, new RegExp(`fetchAllPaginated\\('${path}'.*primaryKey: '${key}'`))
  }
  assert.equal(page.includes('rust.alrowaduni.edu.sy'), false)
  assert.equal(page.includes('localStorage'), false)
  assert.equal(page.includes('fetch('), false)
  assert.equal(page.includes('per_page=500'), false)
  assert.match(page, /لن تُعرض تصنيفات أو أعداد جزئية على أنها بيانات معتمدة/)
  assert.match(page, /غير مرتبطة بقسم/)
  assert.match(page, /لا توجد مواد مرتبطة بهذه الكلية في قاعدة البيانات/)
  assert.equal(page.includes('مشتركة لكل الكليات'), false)
  assert.equal(page.includes('/v1/program-courses'), false)
  assert.match(page, /is_primary: false/)
  assert.match(page, /JSON\.stringify\(\{ is_primary: true \}\)/)
})
