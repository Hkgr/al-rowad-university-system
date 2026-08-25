import assert from 'node:assert/strict'
import {
  advisoryLevelLabel,
  advisorySemesterDiffers,
  advisorySemesterLabel,
  catalogCoursesForAdvisoryLevel,
  flattenCatalogCourses,
  matchesCourseSearch,
} from '../src/features/dean-dashboard/utils/deanOfferingPlanner.js'

const levels = [
  {
    academic_level_id: 3,
    level_name: 'السنة الثالثة',
    courses: [{
      program_course_id: 321,
      academic_level_id: 3,
      course: { course_code: 'FMF321', course_name: 'محاسبة متقدمة' },
      advisory_plan: {
        academic_level_id: 3,
        academic_level_name: 'السنة الثالثة',
        recommended_semester_id: 1,
        recommended_semester_name: 'الفصل الأول',
      },
    }],
  },
  {
    academic_level_id: 4,
    level_name: 'السنة الرابعة',
    courses: [{
      program_course_id: 401,
      academic_level_id: 4,
      course: { course_code: 'BA401', course_name: 'الإدارة الاستراتيجية' },
      advisory_plan: {
        academic_level_id: 4,
        academic_level_name: 'السنة الرابعة',
        recommended_semester_id: 2,
        recommended_semester_name: 'الفصل الثاني',
      },
    }],
  },
  {
    academic_level_id: null,
    level_name: 'بدون مستوى دراسي',
    courses: [{
      program_course_id: 500,
      academic_level_id: null,
      course: { course_code: 'GEN500', course_name: 'مقرر مرن' },
      advisory_plan: {
        academic_level_id: null,
        academic_level_name: null,
        recommended_semester_id: null,
        recommended_semester_name: null,
      },
    }],
  },
]

assert.equal(flattenCatalogCourses(levels).length, 3)
assert.equal(catalogCoursesForAdvisoryLevel(levels).length, 3)
assert.deepEqual(catalogCoursesForAdvisoryLevel(levels, 4).map(row => row.program_course_id), [401])
assert.equal(catalogCoursesForAdvisoryLevel(levels, '').find(row => matchesCourseSearch(row, 'FMF321'))?.program_course_id, 321)
assert.equal(advisorySemesterDiffers(levels[0].courses[0], 2), true)
assert.equal(advisorySemesterDiffers({ ...levels[0].courses[0], academic_level_id: 99 }, 1), false)
assert.equal(advisoryLevelLabel(levels[2].courses[0]), 'المستوى الإرشادي غير محدد')
assert.equal(advisorySemesterLabel(levels[2].courses[0], 2), 'الفصل الإرشادي غير محدد')

console.log(JSON.stringify({ ok: true, cases: 7 }))
