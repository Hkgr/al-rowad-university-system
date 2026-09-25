import { Link } from 'react-router-dom'
import { FaBook } from 'react-icons/fa'
import MinistryListPage from '../components/MinistryListPage'
import { Badge, FilterSelect, Notice } from '../components/MinistryUi'
import { departmentsOf, formatNumber, programsOf } from '../lib/ministryState'

const columns = [
  { key: 'code', header: 'الرمز', render: row => <span className="font-bold" dir="ltr">{row.course_code}</span> },
  { key: 'name', header: 'المقرر', render: row => <Link to={`/ministry/courses/${row.course_id}`} className="font-bold text-primary-dark hover:underline">{row.course_name}</Link> },
  { key: 'hours', header: 'الساعات المعتمدة', align: 'center', render: row => formatNumber(row.credit_hours) },
  { key: 'departments', header: 'الأقسام المالكة', render: row => row.departments.length ? row.departments.map(d => d.name).join('، ') : '—' },
  { key: 'programs', header: 'برامج تتضمنه في خطتها الفعّالة', align: 'center', render: row => formatNumber(row.programs_in_plan) },
  { key: 'offerings', header: 'الطروحات', align: 'center', render: row => formatNumber(row.offerings) },
  { key: 'active', header: 'الحالة', render: row => <Badge tone={row.is_active ? 'success' : 'neutral'}>{row.is_active ? 'مفعّل' : 'غير مفعّل'}</Badge> },
]

export default function MinistryCourses() {
  return (
    <MinistryListPage
      page="courses"
      title="المواد"
      en="Courses"
      subtitle="تعريفات المقررات، مع عدد البرامج التي تتضمنها في خطتها الفعّالة وعدد طروحاتها."
      notice={<Notice>تعريف المقرر شيء، وإدراجه في خطة برنامج شيء آخر، وطرحه في فصل شيء ثالث. تفاصيل كل مقرر تفصل بين الثلاثة. نسخ الخطط القديمة والمسودات لا تُحتسب في «الخطة الفعّالة».</Notice>}
      columns={columns}
      rowKey={row => row.course_id}
      EmptyIcon={FaBook}
      emptyTitle="لا توجد مقررات مسجلة"
      searchPlaceholder="ابحث برمز المقرر أو اسمه…"
      renderFilters={(f, set, o) => (
        <>
          <FilterSelect label="الكلية" value={f.college_id} onChange={set('college_id')} options={o.colleges.map(c => ({ value: c.id, label: c.name }))} />
          <FilterSelect label="القسم" value={f.department_id} onChange={set('department_id')} options={departmentsOf(o.departments, f.college_id).map(d => ({ value: d.id, label: d.name }))} />
          <FilterSelect label="البرنامج (الخطة الفعّالة)" value={f.program_id} onChange={set('program_id')} options={programsOf(o.programs, f.college_id).map(p => ({ value: p.id, label: p.name }))} minWidth={200} />
          <FilterSelect label="الحالة" value={f.active} onChange={set('active')} options={[{ value: '1', label: 'مفعّل' }, { value: '0', label: 'غير مفعّل' }]} minWidth={120} />
          <FilterSelect label="مطروح في سنة" value={f.offered_year_id} onChange={set('offered_year_id')} options={o.academic_years.map(y => ({ value: y.id, label: y.name }))} minWidth={140} />
          {f.offered_year_id && <FilterSelect label="الفصل" value={f.offered_semester_id} onChange={set('offered_semester_id')} options={o.semesters.map(s => ({ value: s.id, label: s.name }))} minWidth={130} />}
        </>
      )}
    />
  )
}
