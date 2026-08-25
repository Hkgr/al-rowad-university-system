export const CATALOG_PAGE_SIZE = 100
export const DEFAULT_MAX_CATALOG_PAGES = 100
export const DEFAULT_MAX_CATALOG_ITEMS = 10000

export class CatalogPaginationError extends Error {
  constructor(message, code, cause) {
    super(message, cause ? { cause } : undefined)
    this.name = 'CatalogPaginationError'
    this.code = code
  }
}

function paginatedPath(path, page) {
  const [base, query = ''] = path.split('?', 2)
  const params = new URLSearchParams(query)
  params.set('per_page', String(CATALOG_PAGE_SIZE))
  params.set('page', String(page))

  return `${base}?${params.toString()}`
}

function safeUpstreamMessage(error) {
  const message = typeof error?.message === 'string'
    ? error.message.replace(/\s+/g, ' ').trim()
    : ''
  if (message === '' || message.length > 200 || message.includes('/') || message.includes('\\')) return null
  if (/bearer\s+|\btoken\b|password|stack\s*trace|sqlstate|mysql:|pgsql:|\bselect\b.+\bfrom\b/i.test(message)) {
    return null
  }

  return message
}

function catalogPageFailure(path, page, error) {
  const endpoint = String(path).split('?', 1)[0]
  const collection = endpoint.split('/').filter(Boolean).at(-1) || endpoint || 'catalog'
  const details = [`تعذر تحميل ${collection} (${endpoint})`, `الصفحة ${page}`]
  const status = Number(error?.status)
  if (Number.isInteger(status) && status >= 100 && status <= 599) details.push(`HTTP ${status}`)

  const message = safeUpstreamMessage(error)
  if (message !== null) details.push(message)
  const errorCode = typeof error?.errorCode === 'string' && /^[a-z0-9_.-]{1,80}$/i.test(error.errorCode)
    ? error.errorCode
    : null
  if (errorCode !== null) details.push(errorCode)

  return new CatalogPaginationError(details.join(' — '), 'catalog_page_failed', error)
}

function pagePayload(response, requestedPage) {
  if (response?.success !== true) {
    throw new CatalogPaginationError('The catalog endpoint did not report success.', 'catalog_page_unsuccessful')
  }

  const payload = response?.data
  const rows = payload?.data
  const meta = payload?.meta
  if (!Array.isArray(rows) || !meta || typeof meta !== 'object') {
    throw new CatalogPaginationError('The catalog endpoint returned invalid pagination data.', 'catalog_pagination_invalid')
  }

  const currentPage = Number(meta.current_page)
  const perPage = Number(meta.per_page)
  const total = Number(meta.total)
  const lastPage = Number(meta.last_page)
  if (!Number.isInteger(currentPage)
    || currentPage !== requestedPage
    || !Number.isInteger(perPage)
    || perPage < 1
    || perPage > CATALOG_PAGE_SIZE
    || !Number.isInteger(total)
    || total < 0
    || !Number.isInteger(lastPage)
    || lastPage < 1
    || lastPage !== Math.max(1, Math.ceil(total / perPage))
    || rows.length > perPage) {
    throw new CatalogPaginationError('The catalog endpoint returned inconsistent pagination metadata.', 'catalog_pagination_inconsistent')
  }

  return { rows, currentPage, perPage, total, lastPage }
}

export async function fetchAllPaginated(path, {
  request,
  primaryKey,
  maxPages = DEFAULT_MAX_CATALOG_PAGES,
  maxItems = DEFAULT_MAX_CATALOG_ITEMS,
} = {}) {
  if (typeof request !== 'function' || typeof primaryKey !== 'string' || primaryKey.trim() === '') {
    throw new CatalogPaginationError('A request function and primary key are required.', 'catalog_loader_invalid_options')
  }
  if (!Number.isInteger(maxPages) || maxPages < 1 || !Number.isInteger(maxItems) || maxItems < 1) {
    throw new CatalogPaginationError('Catalog safety limits must be positive integers.', 'catalog_loader_invalid_limits')
  }

  let firstResponse
  try {
    firstResponse = await request(paginatedPath(path, 1))
  } catch (error) {
    throw catalogPageFailure(path, 1, error)
  }
  const first = pagePayload(firstResponse, 1)
  if (first.lastPage > maxPages || first.total > maxItems) {
    throw new CatalogPaginationError('The catalog exceeds its bounded loading limits.', 'catalog_safety_limit_exceeded')
  }

  const remainingPages = Array.from({ length: first.lastPage - 1 }, (_, index) => index + 2)
  const remainingResponses = await Promise.all(remainingPages.map(async page => {
    try {
      return await request(paginatedPath(path, page))
    } catch (error) {
      throw catalogPageFailure(path, page, error)
    }
  }))

  const pages = [first, ...remainingResponses.map((response, index) => pagePayload(response, index + 2))]
  const rowsById = new Map()
  for (const page of pages) {
    if (page.total !== first.total || page.lastPage !== first.lastPage || page.perPage !== first.perPage) {
      throw new CatalogPaginationError('Catalog pagination changed while loading.', 'catalog_snapshot_changed')
    }
    for (const row of page.rows) {
      const id = row?.[primaryKey]
      if (id === null || id === undefined || id === '') {
        throw new CatalogPaginationError(`Catalog row is missing ${primaryKey}.`, 'catalog_primary_key_missing')
      }
      rowsById.set(String(id), row)
    }
  }

  const rows = [...rowsById.values()]
  if (rows.length !== first.total) {
    throw new CatalogPaginationError('Loaded catalog count does not match the server total.', 'catalog_total_mismatch')
  }

  return rows
}

export async function loadCourseTableCatalog({ request, canViewHr = false } = {}) {
  const required = [
    ['academicYears', '/v1/academic-years', 'academic_year_id'],
    ['semesters', '/v1/semesters', 'semester_id'],
    ['colleges', '/v1/colleges', 'college_id'],
    ['departments', '/v1/departments', 'department_id'],
    ['programs', '/v1/academic-programs', 'academic_program_id'],
    ['courses', '/v1/courses', 'course_id'],
    ['levels', '/v1/academic-levels', 'academic_level_id'],
    ['programCourses', '/v1/program-courses', 'program_course_id'],
    ['offerings', '/v1/course-offerings', 'course_offering_id'],
  ]
  const hr = canViewHr
    ? [
      ['facultyMembers', '/v1/faculty-members', 'faculty_member_id'],
      ['employees', '/v1/employees', 'employee_id'],
    ]
    : []
  const definitions = [...required, ...hr]
  const collections = await Promise.all(definitions.map(([, path, primaryKey]) => (
    fetchAllPaginated(path, { request, primaryKey })
  )))

  const snapshot = Object.fromEntries(definitions.map(([name], index) => [name, collections[index]]))
  if (!canViewHr) {
    snapshot.facultyMembers = []
    snapshot.employees = []
  }

  return snapshot
}

export async function loadCourseOfferingsCatalog({ request } = {}) {
  const definitions = [
    ['academicYears', '/v1/academic-years', 'academic_year_id'],
    ['semesters', '/v1/semesters', 'semester_id'],
    ['colleges', '/v1/colleges', 'college_id'],
    ['departments', '/v1/departments', 'department_id'],
    ['programs', '/v1/academic-programs', 'academic_program_id'],
    ['levels', '/v1/academic-levels', 'academic_level_id'],
    ['programCourses', '/v1/program-courses', 'program_course_id'],
    ['offerings', '/v1/course-offerings', 'course_offering_id'],
  ]
  const collections = await Promise.all(definitions.map(([, path, primaryKey]) => (
    fetchAllPaginated(path, { request, primaryKey })
  )))

  return Object.fromEntries(definitions.map(([name], index) => [name, collections[index]]))
}

function programCourseKey(programId, courseId) {
  return `${String(programId)}:${String(courseId)}`
}

export function actualCourseOfferingRows(offerings, programCourses, levels, semesters) {
  const levelNames = new Map((levels ?? []).map(level => [
    String(level.academic_level_id),
    level.level_name,
  ]))
  const semesterNames = new Map((semesters ?? []).map(semester => [
    String(semester.semester_id),
    semester.semester_name,
  ]))
  const advisoryByCourse = new Map()

  ;[...(programCourses ?? [])]
    .filter(row => row.is_active === true || row.is_active === 1 || row.is_active === '1')
    .sort((left, right) => Number(left.program_course_id) - Number(right.program_course_id))
    .forEach(row => {
      const key = programCourseKey(row.academic_program_id, row.course_id)
      if (!advisoryByCourse.has(key)) advisoryByCourse.set(key, row)
    })

  return (offerings ?? []).map(offering => {
    const programCourse = advisoryByCourse.get(programCourseKey(
      offering.academic_program_id,
      offering.course_id,
    )) ?? null
    const academicLevelId = programCourse?.academic_level_id ?? null
    const recommendedSemesterId = programCourse?.recommended_semester_id ?? null

    return {
      offering,
      advisory: {
        program_course_id: programCourse?.program_course_id ?? null,
        academic_level_id: academicLevelId,
        academic_level_name: academicLevelId == null
          ? null
          : (levelNames.get(String(academicLevelId)) ?? null),
        recommended_semester_id: recommendedSemesterId,
        recommended_semester_name: recommendedSemesterId == null
          ? null
          : (semesterNames.get(String(recommendedSemesterId)) ?? null),
        requirement_classification: programCourse?.requirement_classification ?? null,
      },
    }
  })
}

export function filterActualCourseOfferings(rows, {
  courseId = '',
  academicYearId,
  semesterId,
  collegeId = '',
  departmentId = '',
  academicProgramId = '',
} = {}) {
  return (rows ?? []).filter(row => {
    const offering = row.offering
    if (courseId !== '' && String(offering.course_id) !== String(courseId)) return false
    if (academicYearId !== '' && String(offering.academic_year_id) !== String(academicYearId)) return false
    if (semesterId !== '' && String(offering.semester_id) !== String(semesterId)) return false
    if (academicProgramId !== '' && String(offering.academic_program_id) !== String(academicProgramId)) return false
    if (departmentId !== '' && String(offering.department_id ?? offering.academic_program?.department_id ?? '') !== String(departmentId)) return false
    if (collegeId !== '' && String(offering.college?.college_id ?? offering.academic_program?.department?.college_id ?? '') !== String(collegeId)) return false
    return true
  })
}

export function findActualCourseOffering(offerings, {
  courseId,
  academicProgramId,
  academicYearId,
  semesterId,
}) {
  return (offerings ?? []).find(offering => (
    String(offering.course_id) === String(courseId)
    && String(offering.academic_program_id) === String(academicProgramId)
    && String(offering.academic_year_id) === String(academicYearId)
    && String(offering.semester_id) === String(semesterId)
  ))
}

export function buildCourseCollegeMap(courses, departments, assignments) {
  const courseIds = new Set(courses.map(course => String(course.course_id)))
  const departmentColleges = new Map(departments.map(department => [
    String(department.department_id),
    department.college_id,
  ]))
  const result = new Map()

  for (const assignment of assignments) {
    const courseId = String(assignment.course_id)
    const departmentId = String(assignment.department_id)
    if (!courseIds.has(courseId) || !departmentColleges.has(departmentId)) {
      throw new Error('تعذر تصنيف المواد لأن روابط الأقسام لا تطابق بيانات الكتالوج المحملة.')
    }
    const collegeId = departmentColleges.get(departmentId)
    if (collegeId === null || collegeId === undefined || collegeId === '') {
      throw new Error('تعذر تصنيف مادة بسبب قسم دون كلية معروفة.')
    }
    if (!result.has(courseId)) result.set(courseId, new Set())
    result.get(courseId).add(String(collegeId))
  }

  return result
}

export function filterCoursesByAffiliation(courses, courseCollegeMap, activeTab) {
  if (activeTab === 'all') return courses
  if (activeTab === 'unlinked') {
    return courses.filter(course => !courseCollegeMap.has(String(course.course_id)))
  }

  return courses.filter(course => courseCollegeMap.get(String(course.course_id))?.has(String(activeTab)))
}

function isPrimary(assignment) {
  return assignment.is_primary === true || assignment.is_primary === 1 || assignment.is_primary === '1'
}

export function planDepartmentLinkSync(currentAssignments, desiredDepartmentIds) {
  const currentByDepartment = new Map()
  for (const assignment of currentAssignments) {
    const departmentId = String(assignment.department_id)
    if (currentByDepartment.has(departmentId)) {
      throw new Error('يوجد رابط قسم مكرر لهذه المادة.')
    }
    currentByDepartment.set(departmentId, assignment)
  }

  const desired = [...new Set(desiredDepartmentIds.map(String))]
  const desiredSet = new Set(desired)
  const retainedPrimary = currentAssignments.filter(assignment => (
    desiredSet.has(String(assignment.department_id)) && isPrimary(assignment)
  ))
  if (retainedPrimary.length > 1) {
    throw new Error('يوجد أكثر من قسم رئيسي للمادة ولا يمكن تعديل الروابط بأمان.')
  }

  return {
    toAdd: desired.filter(departmentId => !currentByDepartment.has(departmentId)),
    toRemove: currentAssignments.filter(assignment => !desiredSet.has(String(assignment.department_id))),
    promoteDepartmentId: retainedPrimary.length === 0 && desired.length === 1 ? desired[0] : null,
  }
}
