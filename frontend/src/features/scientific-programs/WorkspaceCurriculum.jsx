import { useState } from 'react'
import { Link } from 'react-router-dom'
import { FaBook } from 'react-icons/fa'
import DataTable from '../../components/table/DataTable'
import { Pager } from '../scientific-courses/CatalogControls'
import { canViewCatalog } from '../scientific-courses/catalog'
import { getIdentity } from '../auth/auth'
import { EntityRowActions } from './AcademicEntityNavigation'
import { advisoryYears, entityLink } from './entities'
import { SCOPES, TYPES } from './programs'
import { courseIdentity } from './workspace'

/** Presentation only: persisted advisory identities, never offering/eligibility decisions. */
export default function WorkspaceCurriculum({ rows, canEdit, busy, uncertain, onEdit, onRemove, source = [], origins = [], returnTo }) {
  const [page, setPage] = useState(1)
  const perPage = 25, last = Math.max(1, Math.ceil(rows.length / perPage)), current = Math.min(page, last)
  const pageRows = rows.slice((current - 1) * perPage, current * perPage)
  const years = advisoryYears(pageRows, source)
  const columns = [{ key: 'course', header: 'المادة — الاسم والرمز', render: c => c.course_id && canViewCatalog(getIdentity()) ? <Link className="font-semibold text-primary text-[12.5px]" to={entityLink('courses', c.course_id, { returnTo })}>{c.label}</Link> : <span className="text-[12.5px]">{c.label}{!c.course_id && <small className="block text-text-light">مادة جديدة ضمن الإعداد — لم تحفظ بعد</small>}</span> },
    { key: 'hours', header: 'الساعات', render: c => source.find(pc => Number(pc.course_id) === Number(c.course_id))?.course?.credit_hours ?? origins.find(n => n.key === c.new_course_key)?.credit_hours ?? c.display_credit_hours ?? 'غير محدد' },
    { key: 'type', header: 'المتطلب', render: c => `${SCOPES[c.requirement_scope] || 'غير محدد'} — ${TYPES[c.course_type] || 'غير محدد'}` },
    { key: 'status', header: 'الارتباط', render: c => c.is_active ? 'فعال' : 'غير فعال' },
    { key: 'actions', header: 'الإجراءات', render: c => canEdit ? <EntityRowActions label={c.label} actions={[{ label: 'تعديل التوزيع', disabled: busy || uncertain, onClick: () => onEdit(c) }, { label: 'إزالة من البرنامج', danger: true, disabled: busy || uncertain, onClick: () => onRemove(c) }]} /> : <span className="text-[12px] text-text-light">قراءة فقط</span> }]
  return <div className="space-y-4">{rows.length === 0 && <DataTable rows={[]} columns={columns} rowKey={courseIdentity} emptyIcon={FaBook} emptyTitle="لا توجد مواد في هذا السياق" />}
    {years.map(year => <section key={year.key} className="space-y-3"><h3 className="border-b border-primary/12 pb-2 text-[16px] font-black text-text-dark">{year.label}</h3>{year.terms.map(term => <section key={term.key} className="space-y-2"><h4 className="text-[13px] font-bold text-text-gray">{term.label}</h4><DataTable rows={term.rows} columns={columns} rowKey={courseIdentity} /></section>)}</section>)}
    <Pager meta={{ current_page: current, last_page: last, total: rows.length, per_page: perPage }} onPage={setPage} disabled={busy} />
  </div>
}
