import { Link } from 'react-router-dom'
import { FaUserTie } from 'react-icons/fa'
import MinistryListPage from '../components/MinistryListPage'
import { Badge, FilterSelect, Notice } from '../components/MinistryUi'
import { formatDate } from '../lib/ministryState'

const columns = [
  { key: 'name', header: 'العميد', render: row => <Link to={`/ministry/deans/${row.person}`} className="font-bold text-primary-dark hover:underline">{row.full_name}</Link> },
  { key: 'college', header: 'الكلية', render: row => row.college?.id ? <Link to={`/ministry/colleges/${row.college.id}`} className="hover:underline">{row.college.name}</Link> : '—' },
  { key: 'state', header: 'التكليف', render: row => <Badge tone={row.state === 'current' ? 'success' : 'neutral'}>{row.state_label}</Badge> },
  { key: 'start', header: 'بداية التكليف', render: row => row.start_date ? formatDate(row.start_date) : <span className="text-text-light">غير مسجل</span> },
  { key: 'end', header: 'نهاية التكليف', render: row => row.end_date ? formatDate(row.end_date) : '—' },
  { key: 'notes', header: 'ملاحظات السجل', render: row => row.notes.length ? <span className="text-[11.5px] text-amber-800">{row.notes.join(' ')}</span> : '—' },
]

export default function MinistryDeans() {
  return (
    <MinistryListPage
      page="deans"
      title="العمداء"
      en="Deans"
      subtitle="عمداء الكليات الحاليون والسابقون كما يسجلهم النظام."
      notice={<Notice>العمادة الحالية تثبت بدور العميد ونطاق الكلية الفعّالين أو بقيد منصب عميد مفتوح؛ وتواريخ التكليف من قيود المناصب. إذا نقص أحدهما تظهر ملاحظة بدل تاريخ مُفترض.</Notice>}
      columns={columns}
      rowKey={row => `${row.person}-${row.college?.id}`}
      EmptyIcon={FaUserTie}
      emptyTitle="لا يوجد عمداء مسجلون"
      searchPlaceholder="ابحث باسم العميد أو الكلية…"
      renderFilters={(f, set, o) => (
        <>
          <FilterSelect label="الكلية" value={f.college_id} onChange={set('college_id')} options={o.colleges.map(c => ({ value: c.id, label: c.name }))} />
          <FilterSelect label="التكليف" value={f.state} onChange={set('state')} options={[{ value: 'current', label: 'الحاليون' }, { value: 'historical', label: 'السابقون' }]} minWidth={130} />
        </>
      )}
    />
  )
}
