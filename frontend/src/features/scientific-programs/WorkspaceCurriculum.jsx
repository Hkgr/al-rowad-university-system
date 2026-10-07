import { useState } from 'react'
import { FaBook } from 'react-icons/fa'
import DataTable from '../../components/table/DataTable'
import { Button, Pager } from '../scientific-courses/CatalogControls'
import { SCOPES, TYPES } from './programs'
import { courseIdentity } from './workspace'

/** Presentation only: persisted advisory identities, never offering/eligibility decisions. */
export default function WorkspaceCurriculum({ rows, canEdit, busy, uncertain, onEdit, onRemove, source, origins }) {
  const [page, setPage] = useState(1)
  const perPage = 25, last = Math.max(1, Math.ceil(rows.length / perPage)), current = Math.min(page, last)
  const pageRows = rows.slice((current - 1) * perPage, current * perPage)
  const groups = new Map()
  for (const row of pageRows) {
    const key = `${row.academic_level_id}:${row.recommended_semester_id}`
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(row)
  }
  const labels = (c, relation, fallback) => {
    const item = source.find(pc => Number(pc[relation === 'academic_level' ? 'academic_level_id' : 'recommended_semester_id']) === Number(c[relation === 'academic_level' ? 'academic_level_id' : 'recommended_semester_id']))
    return item?.[relation]?.[relation === 'academic_level' ? 'level_name' : 'semester_name'] || fallback
  }
  const columns = [{ key: 'course', header: 'المادة — الاسم والرمز', render: c => c.label },
    { key: 'hours', header: 'الساعات', render: c => source.find(pc => Number(pc.course_id) === Number(c.course_id))?.course?.credit_hours ?? origins.find(n => n.key === c.new_course_key)?.credit_hours ?? c.display_credit_hours ?? 'غير محدد' },
    { key: 'type', header: 'المتطلب', render: c => `${SCOPES[c.requirement_scope] || 'غير محدد'} — ${TYPES[c.course_type] || 'غير محدد'}` },
    { key: 'status', header: 'الارتباط', render: c => c.is_active ? 'فعال' : 'غير فعال' },
    { key: 'actions', header: 'الإجراءات', render: c => canEdit ? <div className="flex flex-wrap gap-2"><Button disabled={busy || uncertain} onClick={() => onEdit(c)}>تعديل</Button><Button danger disabled={busy || uncertain} onClick={() => onRemove(c)}>إزالة من البرنامج</Button></div> : 'قراءة فقط' }]
  return <div className="space-y-4">{rows.length === 0 && <DataTable rows={[]} columns={columns} rowKey={courseIdentity} emptyIcon={FaBook} emptyTitle="لا توجد مواد في هذا السياق" />}
    {[...groups].sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true })).map(([key, courses]) => <section key={key} className="space-y-2"><h3 className="text-[14px] font-bold">{labels(courses[0], 'academic_level', courses[0].academic_level_id ? `السنة الدراسية ${courses[0].academic_level_id}` : 'السنة غير محددة')} — {labels(courses[0], 'recommended_semester', courses[0].recommended_semester_id ? `الفصل الإرشادي ${courses[0].recommended_semester_id}` : 'الفصل غير محدد')}</h3><DataTable rows={courses} columns={columns} rowKey={courseIdentity} /></section>)}
    <Pager meta={{ current_page: current, last_page: last, total: rows.length, per_page: perPage }} onPage={setPage} disabled={busy} />
  </div>
}
