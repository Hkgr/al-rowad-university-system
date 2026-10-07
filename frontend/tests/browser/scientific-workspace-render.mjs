// Real React SSR with synthetic props. NOT a browser/layout/live-Laravel integration test.
import assert from 'node:assert/strict'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createServer } from 'vite'

const server = await createServer({ server: { middlewareMode: true }, appType: 'custom' })
try {
  const { default: Curriculum } = await server.ssrLoadModule('/src/features/scientific-programs/WorkspaceCurriculum.jsx')
  const { default: History } = await server.ssrLoadModule('/src/features/scientific-programs/WorkspaceHistory.jsx')
  const { default: Editor } = await server.ssrLoadModule('/src/features/scientific-programs/WorkspaceCourseEditor.jsx')
  const { default: Requirements } = await server.ssrLoadModule('/src/features/scientific-programs/WorkspaceRequirements.jsx')
  const row = { course_id: 1, label: 'مادة اصطناعية (SYN-1)', academic_level_id: 1, recommended_semester_id: 2, requirement_scope: 'college', course_type: 'elective', is_active: true }
  const markup = renderToStaticMarkup(createElement(Curriculum, { rows: [row], source: [{ course_id: 1, academic_level_id: 1, recommended_semester_id: 2, academic_level: { level_name: 'الأولى' }, recommended_semester: { semester_name: 'الثاني' }, course: { credit_hours: 3 } }], origins: [], canEdit: true }))
  for (const label of ['مادة اصطناعية', 'SYN-1', 'الأولى', 'الثاني', 'اختياري', 'إزالة من البرنامج']) assert.ok(markup.includes(label), label)
  assert.ok(renderToStaticMarkup(createElement(Curriculum, { rows: [], source: [], origins: [] })).includes('لا توجد مواد'))
  const before = { total_credit_hours: null, groups: [], courses: [] }, after = { total_credit_hours: 3, groups: [{ requirement_scope: 'college', requirement_type: 'elective', required_credit_hours: 3 }], courses: [row] }
  const read = { data: { data: [{ id: 'plan:1', actor: 'موظف اصطناعي', created_at: '2026-10-07', action: 'workspace_saved', before, after, details_available: true, course_labels: { 1: { course_name: 'مادة اصطناعية', course_code: 'SYN-1' } }, selected_program_ids: [1], selected_program_labels: { 1: 'برنامج اصطناعي' } },
    { id: 'catalog:1', actor: 'موظف اصطناعي', created_at: '2026-10-07', action: 'scientific_catalog.course.create', before: null, after: { course_name: 'أصل جديد', course_code: 'NEW-1' }, details_available: true },
    { id: 'plan:2', actor: 'موظف اصطناعي', created_at: '2026-10-06', action: 'copied', details_available: false }], meta: { total: 3, current_page: 1, last_page: 1 } } }
  const history = renderToStaticMarkup(createElement(History, { read, page: 1 }))
  for (const label of ['مجموعة مفقودة', 'برنامج اصطناعي', 'أصل جديد', 'تفاصيل القيم السابقة والجديدة غير مسجلة']) assert.ok(history.includes(label), label)
  const editor = renderToStaticMarkup(createElement(Editor, { preparedCourses: [], canCreate: true }))
  for (const label of ['مادة جديدة', 'السنة الدراسية الإرشادية', 'الفصل الإرشادي', 'إضافة إلى الإعداد']) assert.ok(editor.includes(label), label)
  const group = { requirement_scope: 'department', requirement_type: 'elective', required_credit_hours: 0, is_active: false, group_exists: true }
  const requirements = renderToStaticMarkup(createElement(Requirements, { draft: { program: { program_name: 'اصطناعي' }, requirements: { total_credit_hours: 3, groups: [group] } }, editable: true }))
  assert.ok(requirements.includes('<option value="0" selected="">غير فعالة</option>'))
  assert.ok(requirements.includes('تفعيلها اختيار صريح') && requirements.includes('value="0"'))
  const stateHistory = { data: { data: [{ id: 'plan:state', actor: 'اصطناعي', created_at: '2026-10-07', action: 'requirements_saved', details_available: true,
    before: { total_credit_hours: 3, groups: [group] }, after: { total_credit_hours: 3, groups: [{ ...group, is_active: true }] }, selected_program_ids: [1], scope: null }], meta: { total: 1, current_page: 1, last_page: 1 } } }
  const stateMarkup = renderToStaticMarkup(createElement(History, { read: stateHistory, page: 1 }))
  assert.ok(stateMarkup.includes('غير فعالة') && stateMarkup.includes('فعالة') && stateMarkup.includes('الحالة'))
  assert.ok(stateMarkup.includes('حفظ إعداد الخطة لا يعني اعتمادها'))
  assert.ok(!stateMarkup.includes('نطاق التطبيق: الطلاب الجدد فقط.'))
  console.log('PASS React SSR: curriculum/empty state, six-group/history snapshots, catalogue creation history, staged editor. No browser or live integration assertion.')
} finally { await server.close() }
