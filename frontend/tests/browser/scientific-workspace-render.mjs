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
  console.log('PASS React SSR: curriculum/empty state, six-group/history snapshots, catalogue creation history, staged editor. No browser or live integration assertion.')
} finally { await server.close() }
