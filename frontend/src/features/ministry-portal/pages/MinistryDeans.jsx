import { Link } from 'react-router-dom'
import { FaUserTie } from 'react-icons/fa'
import MinistryListPage from '../components/MinistryListPage'
import { FilterSelect, Notice } from '../components/MinistryUi'
import { DeanAccountState, DeanOverallState, DeanPositionState } from '../components/DeanStatus'
import { formatDate } from '../lib/ministryState'

const columns = [
  { key: 'name', header: 'العميد', render: row => <Link to={`/ministry/deans/${row.person}`} className="font-bold text-primary-dark hover:underline">{row.full_name}</Link> },
  { key: 'college', header: 'الكلية', render: row => row.college?.id ? <Link to={`/ministry/colleges/${row.college.id}`} className="hover:underline">{row.college.name}</Link> : '—' },
  { key: 'state', header: 'الحالة الإجمالية', render: row => <DeanOverallState row={row} /> },
  { key: 'account', header: 'الدور ونطاق الكلية', render: row => <DeanAccountState row={row} /> },
  { key: 'position', header: 'حالة قيد المنصب', render: row => <DeanPositionState row={row} /> },
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
      notice={<Notice>الحالي: حساب فعّال يجمع دور العميد ونطاق الكلية الفعّالين، أو قيد منصب سارٍ. حالة الدور والنطاق مستقلة عن قيد المنصب، والتاريخان كما سُجلا. التعارض ظاهر ولا يعني أن القيد المنتهي مفتوح.</Notice>}
      columns={columns}
      rowKey={row => `${row.person}-${row.college?.id}`}
      EmptyIcon={FaUserTie}
      emptyTitle="لا يوجد عمداء مسجلون"
      searchPlaceholder="ابحث باسم العميد أو الكلية…"
      renderFilters={(f, set, o) => (
        <>
          <FilterSelect label="الكلية" value={f.college_id} onChange={set('college_id')} options={o.colleges.map(c => ({ value: c.id, label: c.name }))} />
          <FilterSelect label="الحالة الإجمالية" value={f.state} onChange={set('state')} options={[{ value: 'current', label: 'الحاليون' }, { value: 'historical', label: 'السابقون / غير الحاليين' }]} minWidth={130} />
        </>
      )}
    />
  )
}
