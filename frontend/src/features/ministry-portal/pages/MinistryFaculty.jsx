import { Link } from 'react-router-dom'
import { FaChalkboardTeacher } from 'react-icons/fa'
import MinistryListPage from '../components/MinistryListPage'
import { Badge, FilterSelect, Notice } from '../components/MinistryUi'
import { formatNumber } from '../lib/ministryState'

const columns = [
  { key: 'name', header: 'الاسم', render: row => <Link to={`/ministry/faculty/${row.faculty_member_id}`} className="font-bold text-primary-dark hover:underline">{row.full_name}</Link> },
  { key: 'rank', header: 'الرتبة العلمية', render: row => row.academic_rank ?? '—' },
  { key: 'specialization', header: 'الاختصاص', render: row => row.specialization ?? '—' },
  { key: 'colleges', header: 'الكليات', render: row => row.colleges.length ? row.colleges.map(c => c.name).join('، ') : <span className="text-text-light">غير مسجل</span> },
  { key: 'offerings', header: 'طروحات مكلف بها', align: 'center', render: row => formatNumber(row.active_offerings) },
  { key: 'status', header: 'الحالة', render: row => <Badge tone={row.is_active ? 'success' : 'neutral'}>{row.is_active ? 'ملف مفعّل' : 'ملف غير مفعّل'}</Badge> },
]

export default function MinistryFaculty() {
  return (
    <MinistryListPage
      page="faculty"
      title="المدرسون"
      en="Teaching staff"
      subtitle="أعضاء الهيئة التدريسية المسجلون، وانتماؤهم للكليات، وعدد الطروحات المكلفين بها حاليًا."
      notice={<Notice>الانتماء للكلية من الوحدة الأساسية للموظف أو تكليف فعّال بوحدة الكلية، وقد ينتمي المدرس لأكثر من كلية. الانتماء ليس تكليفًا بالتدريس.</Notice>}
      columns={columns}
      rowKey={row => row.faculty_member_id}
      EmptyIcon={FaChalkboardTeacher}
      emptyTitle="لا يوجد مدرسون مسجلون"
      searchPlaceholder="ابحث بالاسم أو الاختصاص أو الرتبة…"
      renderFilters={(f, set, o) => (
        <>
          <FilterSelect label="الكلية" value={f.college_id} onChange={set('college_id')} options={o.colleges.map(c => ({ value: c.id, label: c.name }))} />
          <FilterSelect label="الملف التدريسي" value={f.active} onChange={set('active')} options={[{ value: '1', label: 'مفعّل' }, { value: '0', label: 'غير مفعّل' }]} minWidth={130} />
        </>
      )}
    />
  )
}
