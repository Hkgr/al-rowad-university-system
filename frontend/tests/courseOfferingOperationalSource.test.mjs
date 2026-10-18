import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import {
  actualCourseOfferingRows,
  filterActualCourseOfferings,
  loadCourseOfferingsCatalog,
} from '../src/features/exam-board/lib/courseCatalog.js'

function requestFor(collections, { failPath = '', failPage = 0 } = {}) {
  const calls = []
  const request = async path => {
    const url = new URL(path, 'https://catalog.test')
    const endpoint = url.pathname
    const page = Number(url.searchParams.get('page'))
    const perPage = Number(url.searchParams.get('per_page'))
    calls.push({ endpoint, page, perPage })
    if (endpoint === failPath && page === failPage) throw new Error('page failed')
    const rows = collections[endpoint] ?? []
    return {
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
  }
  return { request, calls }
}

function collections() {
  const programCourses = Array.from({ length: 183 }, (_, index) => ({
    program_course_id: index + 1,
    academic_program_id: 7,
    course_id: index + 1,
    academic_level_id: 3,
    recommended_semester_id: 1,
    is_active: true,
  }))
  const offerings = Array.from({ length: 183 }, (_, index) => ({
    course_offering_id: index + 1,
    course_id: index + 1,
    academic_program_id: 7,
    academic_year_id: 26,
    semester_id: index === 182 ? 2 : 1,
    status: index === 182 ? 'open' : 'closed',
    capacity: 40,
    available_seats: 30,
    course: { course_code: index === 182 ? 'FMF321' : `C-${index + 1}` },
  }))
  return {
    '/v1/academic-years': [{ academic_year_id: 26, is_current: true }],
    '/v1/semesters': [{ semester_id: 1, semester_name: 'الأول' }, { semester_id: 2, semester_name: 'الثاني' }],
    '/v1/colleges': [{ college_id: 1 }],
    '/v1/departments': [{ department_id: 3, college_id: 1 }],
    '/v1/academic-programs': [{ academic_program_id: 7, department_id: 3 }],
    '/v1/academic-levels': [{ academic_level_id: 3, level_name: 'السنة الثالثة' }],
    '/v1/program-courses': programCourses,
    '/v1/course-offerings': offerings,
  }
}

test('OPSRC-EXAM-07/09 loads page two and matches the actual offering term', async () => {
  const api = requestFor(collections())
  const snapshot = await loadCourseOfferingsCatalog({ request: api.request })
  const projected = actualCourseOfferingRows(
    snapshot.offerings,
    snapshot.programCourses,
    snapshot.levels,
    snapshot.semesters,
  )
  const visible = filterActualCourseOfferings(projected, {
    courseId: 183,
    academicYearId: 26,
    semesterId: 2,
    academicProgramId: 7,
  })

  assert.equal(snapshot.offerings.length, 183)
  assert.deepEqual(visible.map(row => row.offering.course.course_code), ['FMF321'])
  assert.equal(api.calls.some(call => call.endpoint === '/v1/program-courses' && call.page === 2 && call.perPage === 100), true)
  assert.equal(api.calls.some(call => call.endpoint === '/v1/course-offerings' && call.page === 2 && call.perPage === 100), true)
})

test('OPSRC-EXAM-08 a required page-two failure rejects the complete snapshot', async () => {
  const api = requestFor(collections(), { failPath: '/v1/course-offerings', failPage: 2 })
  await assert.rejects(
    loadCourseOfferingsCatalog({ request: api.request }),
    error => error.code === 'catalog_page_failed',
  )
})

test('OPSRC-EXAM-11 persisted offerings survive missing inactive and null advisory metadata', () => {
  const offerings = [
    { course_offering_id: 1, course_id: 10, academic_program_id: 7, academic_year_id: 26, semester_id: 2, status: 'open', capacity: 50, available_seats: 11 },
    { course_offering_id: 2, course_id: 11, academic_program_id: 7, academic_year_id: 26, semester_id: 2, status: 'closed', capacity: 30, available_seats: 30 },
    { course_offering_id: 3, course_id: 12, academic_program_id: 7, academic_year_id: 26, semester_id: 2, status: 'open', capacity: 25, available_seats: 5 },
  ]
  const programCourses = [
    { program_course_id: 11, course_id: 11, academic_program_id: 7, academic_level_id: 3, recommended_semester_id: 1, is_active: false },
    { program_course_id: 12, course_id: 12, academic_program_id: 7, academic_level_id: null, recommended_semester_id: null, is_active: true },
  ]
  const rows = actualCourseOfferingRows(offerings, programCourses, [], [])

  assert.deepEqual(rows.map(row => row.offering.course_offering_id), [1, 2, 3])
  assert.deepEqual(rows.map(row => row.advisory.academic_level_name), [null, null, null])
  assert.deepEqual(rows.map(row => row.advisory.recommended_semester_name), [null, null, null])
  assert.deepEqual(rows.map(row => row.offering.status), ['open', 'closed', 'open'])
  assert.deepEqual(rows.map(row => row.offering.capacity), [50, 30, 25])
  assert.deepEqual(rows.map(row => row.offering.available_seats), [11, 30, 5])
})

test('OPSRC-EXAM-01..06/10 page is read-only and distinguishes actual from advisory data', () => {
  const page = readFileSync(new URL('../src/features/exam-board/pages/CourseOfferingsPage.jsx', import.meta.url), 'utf8')
  const catalog = readFileSync(new URL('../src/features/exam-board/lib/courseCatalog.js', import.meta.url), 'utf8')
  const nav = readFileSync(new URL('../src/features/exam-board/nav.js', import.meta.url), 'utf8')
  const home = readFileSync(new URL('../src/features/exam-board/pages/ExamBoardHome.jsx', import.meta.url), 'utf8')

  for (const forbidden of [
    'handleAddCurriculumCourse',
    'handleRemoveCurriculumCourse',
    'handleOpenOffering',
    'handleToggleStatus',
    'InstructorAssignment',
    "method: 'POST'",
    "method: 'PUT'",
    "method: 'PATCH'",
    "method: 'DELETE'",
  ]) assert.equal(page.includes(forbidden), false, forbidden)

  assert.match(page, /loadCourseOfferingsCatalog\(\{ request: apiRequest \}\)/)
  assert.match(page, /row\.offering\.course_offering_id/)
  assert.match(page, /offering\.status/)
  assert.match(page, /offering\.capacity/)
  assert.match(page, /offering\.available_seats/)
  assert.match(page, /المستوى الإرشادي.*غير محدد/)
  assert.match(page, /الفصل الإرشادي.*غير محدد/)
  assert.match(page + nav + home, /الطروحات الأكاديمية/)
  assert.equal(page.includes('فتح المواد الدراسية'), false)
  assert.match(catalog, /return \(offerings \?\? \[\]\)\.map\(offering =>/)
  assert.match(catalog, /row\.is_active === true/)
  assert.doesNotMatch(catalog, /recommended_semester_id[^\n]*(?:===|!==)[^\n]*(?:semesterId|academicYearId)/)
})
