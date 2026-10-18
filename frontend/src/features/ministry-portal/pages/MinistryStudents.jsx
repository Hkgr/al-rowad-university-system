import { Link } from 'react-router-dom'
import { FaUserGraduate } from 'react-icons/fa'
import MinistryListPage from '../components/MinistryListPage'
import { Badge, FilterSelect } from '../components/MinistryUi'
import { formatDate, programsOf } from '../lib/ministryState'

const STATUS_TONE = { active: 'success', graduated: 'info', frozen: 'warning', withdrawn: 'neutral', dismissed: 'danger', suspended: 'danger' }

const columns = [
  { key: 'number', header: 'الرقم الجامعي', render: row => <span className="font-bold text-text-dark" dir="ltr">{row.student_number}</span> },
  { key: 'name', header: 'الاسم', render: row => <Link to={`/ministry/students/${row.student_id}`} className="font-bold text-primary-dark hover:underline">{row.full_name}</Link> },
  { key: 'college', header: 'الكلية', render: row => row.college ? <Link to={`/ministry/colleges/${row.college.id}`} className="hover:underline">{row.college.name}</Link> : '—' },
  { key: 'program', header: 'البرنامج', render: row => row.program?.name ?? '—' },
  { key: 'level', header: 'السنة الدراسية', render: row => row.level },
  { key: 'status', header: 'الحالة', render: row => <Badge tone={STATUS_TONE[row.status.code] ?? 'neutral'}>{row.status.label}</Badge> },
  { key: 'enrolled', header: 'تاريخ الالتحاق', render: row => <span className="whitespace-nowrap">{formatDate(row.enrollment_date)}</span> },
]

export default function MinistryStudents() {
  return (
    <MinistryListPage
      page="students"
      title="الطلاب"
      en="Students"
      subtitle="الطلاب غير المحذوفين بكل الحالات. لا تُعرض بيانات الاتصال أو البيانات الشخصية غير اللازمة."
      columns={columns}
      rowKey={row => row.student_id}
      EmptyIcon={FaUserGraduate}
      emptyTitle="لا يوجد طلاب مسجلون"
      searchPlaceholder="ابحث بالاسم أو الرقم الجامعي…"
      renderFilters={(f, set, o) => (
        <>
          <FilterSelect label="الكلية" value={f.college_id} onChange={set('college_id')} options={o.colleges.map(c => ({ value: c.id, label: c.name }))} />
          <FilterSelect label="البرنامج" value={f.program_id} onChange={set('program_id')} options={programsOf(o.programs, f.college_id).map(p => ({ value: p.id, label: p.name }))} minWidth={200} />
          <FilterSelect label="الحالة" value={f.status} onChange={set('status')} options={o.student_statuses.map(s => ({ value: s.code, label: s.name }))} minWidth={130} />
          <FilterSelect label="السنة الدراسية" value={f.level_id} onChange={set('level_id')} options={o.levels.map(l => ({ value: l.id, label: l.name }))} minWidth={140} />
          <FilterSelect label="سنة الالتحاق" value={f.enrollment_year_id} onChange={set('enrollment_year_id')} options={o.academic_years.map(y => ({ value: y.id, label: y.name }))} minWidth={140} />
        </>
      )}
    />
  )
}
