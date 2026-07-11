import { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaUserPlus, FaEye, FaEdit, FaTrash,
  FaSpinner, FaUsers, FaTimes, FaSave,
} from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const TYPE_AR   = { academic: 'أكاديمي', administrative: 'إداري', technical: 'تقني', service: 'خدمات', board_member: 'عضو مجلس' }
const STATUS_AR = { active: 'نشط', inactive: 'غير نشط', on_leave: 'في إجازة', terminated: 'منتهي الخدمة' }
const STATUS_COLOR = {
  active:     'bg-green-100 text-green-700',
  inactive:   'bg-slate-100 text-slate-600',
  on_leave:   'bg-amber-100 text-amber-700',
  terminated: 'bg-red-100 text-red-600',
}

const EMPTY_FORM = {
  employee_number: '', first_name: '', last_name: '', father_name: '', mother_name: '',
  phone_number: '', email: '', hire_date: '', employee_type_id: '', employee_status_id: '',
}

function EmployeeModal({ emp, types, statuses, onClose, onSaved }) {
  const [form, setForm]     = useState(emp ? { ...emp, employee_type_id: emp.employee_type_id ?? '', employee_status_id: emp.employee_status_id ?? '' } : { ...EMPTY_FORM })
  const [saving, setSaving] = useState(false)
  const [err, setErr]       = useState('')

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  async function handleSave() {
    setSaving(true); setErr('')
    const url    = emp ? `${API}/employees/${emp.employee_id}` : `${API}/employees`
    const method = emp ? 'PUT' : 'POST'
    try {
      const res  = await fetch(url, {
        method,
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, employee_type_id: Number(form.employee_type_id), employee_status_id: Number(form.employee_status_id) }),
      })
      const json = await res.json()
      if (json.success) { onSaved() }
      else setErr(json.message || 'فشلت العملية')
    } catch { setErr('تعذّر الاتصال بالخادم') }
    finally  { setSaving(false) }
  }

  const Field = ({ label, k, type = 'text', half }) => (
    <div className={half ? 'col-span-1' : 'col-span-2'}>
      <label className="block text-[11.5px] font-bold text-text-dark mb-1" dir="rtl">{label}</label>
      <input
        type={type} value={form[k] ?? ''}
        onChange={e => set(k, e.target.value)}
        className="w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark outline-none focus:border-primary"
        dir="rtl"
      />
    </div>
  )

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <motion.div
        className="bg-white rounded-[18px] shadow-2xl w-full max-w-[620px] max-h-[90vh] overflow-y-auto"
        initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.2 }}
      >
        <div className="flex items-center justify-between px-6 py-4 border-b border-primary/10" dir="rtl">
          <h3 className="text-[16px] font-extrabold text-text-dark">{emp ? 'تعديل موظف' : 'إضافة موظف جديد'}</h3>
          <button onClick={onClose} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-text-light transition-colors"><FaTimes /></button>
        </div>

        <div className="p-6 grid grid-cols-2 gap-4" dir="rtl">
          <Field label="رقم الموظف *"       k="employee_number" half />
          <Field label="تاريخ التعيين"       k="hire_date" type="date" half />
          <Field label="الاسم الأول *"       k="first_name"  half />
          <Field label="اسم العائلة *"       k="last_name"   half />
          <Field label="اسم الأب"            k="father_name" half />
          <Field label="اسم الأم"            k="mother_name" half />
          <Field label="البريد الإلكتروني"   k="email" type="email" half />
          <Field label="رقم الهاتف"          k="phone_number" half />

          <div className="col-span-1">
            <label className="block text-[11.5px] font-bold text-text-dark mb-1" dir="rtl">نوع الموظف *</label>
            <select value={form.employee_type_id} onChange={e => set('employee_type_id', e.target.value)}
              className="w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" dir="rtl">
              <option value="">اختر النوع</option>
              {types.map(t => <option key={t.employee_type_id} value={t.employee_type_id}>{TYPE_AR[t.type_code] ?? t.type_name}</option>)}
            </select>
          </div>

          <div className="col-span-1">
            <label className="block text-[11.5px] font-bold text-text-dark mb-1" dir="rtl">الحالة *</label>
            <select value={form.employee_status_id} onChange={e => set('employee_status_id', e.target.value)}
              className="w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" dir="rtl">
              <option value="">اختر الحالة</option>
              {statuses.map(s => <option key={s.employee_status_id} value={s.employee_status_id}>{STATUS_AR[s.status_code] ?? s.status_name}</option>)}
            </select>
          </div>
        </div>

        {err && <p className="mx-6 mb-3 text-[12px] text-red-600 bg-red-50 border border-red-200 rounded-[8px] px-3 py-2" dir="rtl">⚠ {err}</p>}

        <div className="flex items-center justify-end gap-3 px-6 pb-5 pt-1" dir="rtl">
          <button onClick={onClose} className="px-5 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark hover:bg-gray-50 transition-colors">إلغاء</button>
          <button onClick={handleSave} disabled={saving}
            className="flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-[9px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors">
            {saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaSave className="text-[11px]" />}
            حفظ
          </button>
        </div>
      </motion.div>
    </div>
  )
}

export default function EmployeesPage() {
  const [employees, setEmployees] = useState([])
  const [loading,   setLoading]   = useState(true)
  const [error,     setError]     = useState('')
  const [search,    setSearch]    = useState('')
  const [page,      setPage]      = useState(1)
  const [meta,      setMeta]      = useState({ total: 0, last_page: 1, per_page: 15 })
  const [types,     setTypes]     = useState([])
  const [statuses,  setStatuses]  = useState([])
  const [modal,     setModal]     = useState(null) // null | 'add' | employee obj
  const debounceRef = useRef(null)
  const navigate    = useNavigate()

  useEffect(() => {
    Promise.all([
      fetch(`${API}/employee-types?per_page=50`,    { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/employee-statuses?per_page=50`, { headers: authHeaders() }).then(r => r.json()),
    ]).then(([t, s]) => {
      setTypes(t.success   ? (t.data?.data ?? t.data ?? []) : [])
      setStatuses(s.success ? (s.data?.data ?? s.data ?? []) : [])
    })
  }, [])

  const fetchEmployees = useCallback(async (q, p) => {
    setLoading(true); setError('')
    try {
      const url = `${API}/employees?page=${p}&per_page=15${q ? `&search=${encodeURIComponent(q)}` : ''}`
      const res  = await fetch(url, { headers: authHeaders() })
      if (res.status === 401) { navigate('/login'); return }
      const json = await res.json()
      if (json.success) {
        const d = json.data
        setEmployees(d.data ?? [])
        setMeta({ total: d.meta?.total ?? d.total ?? 0, last_page: d.meta?.last_page ?? d.last_page ?? 1, per_page: d.meta?.per_page ?? 15 })
      } else setError(json.message || 'فشل تحميل البيانات')
    } catch { setError('تعذّر الاتصال بالخادم') }
    finally   { setLoading(false) }
  }, [navigate])

  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => { setPage(1); fetchEmployees(search, 1) }, 380)
    return () => clearTimeout(debounceRef.current)
  }, [search, fetchEmployees])

  useEffect(() => { fetchEmployees(search, page) }, [page]) // eslint-disable-line

  async function handleDelete(id, name) {
    if (!window.confirm(`هل تريد حذف الموظف "${name}"؟\nهذا الإجراء لا يمكن التراجع عنه.`)) return
    try {
      const res  = await fetch(`${API}/employees/${id}`, { method: 'DELETE', headers: authHeaders() })
      const json = await res.json()
      if (json.success) { fetchEmployees(search, page) }
      else alert(json.message || 'فشل الحذف')
    } catch { alert('تعذّر الاتصال بالخادم') }
  }

  const typeCodeMap = useMemo(() => {
    const m = new Map()
    types.forEach(t => m.set(t.employee_type_id, t.type_code))
    return m
  }, [types])

  const statusCodeMap = useMemo(() => {
    const m = new Map()
    statuses.forEach(s => m.set(s.employee_status_id, s.status_code))
    return m
  }, [statuses])

  const getStatusCode = (emp) => emp.employeeStatus?.status_code ?? emp.employee_status?.status_code ?? statusCodeMap.get(emp.employee_status_id) ?? ''
  const getTypeCode   = (emp) => emp.employeeType?.type_code     ?? emp.employee_type?.type_code     ?? typeCodeMap.get(emp.employee_type_id)     ?? ''

  const columns = [
    {
      key: 'idx',
      header: '#',
      dir: 'rtl',
      cellClassName: 'text-[12px] text-text-light font-semibold w-10',
      render: (e, idx) => (page - 1) * meta.per_page + idx + 1,
    },
    {
      key: 'employee_number',
      header: 'رقم الموظف',
      dir: 'rtl',
      render: e => (
        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">
          {e.employee_number}
        </span>
      ),
    },
    {
      key: 'name',
      header: 'الاسم الكامل',
      dir: 'rtl',
      cellClassName: 'font-semibold text-[13.5px] text-text-dark',
      render: e => `${e.first_name} ${e.last_name}`,
    },
    {
      key: 'contact',
      header: 'البريد / الهاتف',
      dir: 'rtl',
      cellClassName: 'text-[12px] text-text-gray',
      render: e => (
        <>
          <div>{e.email || '—'}</div>
          <div className="text-text-light">{e.phone_number || ''}</div>
        </>
      ),
    },
    {
      key: 'type',
      header: 'النوع',
      dir: 'rtl',
      cellClassName: 'text-[12px] text-text-gray',
      render: e => TYPE_AR[getTypeCode(e)] ?? getTypeCode(e) ?? '—',
    },
    {
      key: 'hire_date',
      header: 'تاريخ التعيين',
      dir: 'rtl',
      cellClassName: 'text-[12.5px] text-text-dark',
      render: e => e.hire_date ? new Date(e.hire_date).toLocaleDateString('ar-SY') : '—',
    },
    {
      key: 'status',
      header: 'الحالة',
      dir: 'rtl',
      render: e => {
        const statusCode = getStatusCode(e)
        return (
          <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${STATUS_COLOR[statusCode] ?? 'bg-gray-100 text-gray-600'}`}>
            {STATUS_AR[statusCode] ?? statusCode ?? '—'}
          </span>
        )
      },
    },
    {
      key: 'actions',
      header: 'الإجراءات',
      dir: 'rtl',
      render: e => (
        <div className="flex items-center gap-1.5">
          <button onClick={() => navigate(`/hr/employees/${e.employee_id}`)}
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-blue-500 border-blue-500/20 bg-blue-50 hover:bg-blue-100 transition-colors" title="عرض الملف">
            <FaEye />
          </button>
          <button onClick={() => setModal(e)}
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-amber-500 border-amber-500/20 bg-amber-50 hover:bg-amber-100 transition-colors" title="تعديل">
            <FaEdit />
          </button>
          <button onClick={() => handleDelete(e.employee_id, `${e.first_name} ${e.last_name}`)}
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-red-500 border-red-200 bg-red-50 hover:bg-red-100 transition-colors" title="حذف">
            <FaTrash />
          </button>
        </div>
      ),
    },
  ]

  return (
    <>
      {modal && (
        <EmployeeModal
          emp={modal === 'add' ? null : modal}
          types={types} statuses={statuses}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); fetchEmployees(search, page) }}
        />
      )}

      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">قائمة الموظفين</h2>
          <p className="text-[12.5px] text-text-light">{meta.total > 0 ? `${meta.total} موظف` : 'عرض جميع الموظفين'}</p>
        </div>
        <button
          onClick={() => setModal('add')}
          className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold shadow-[0_4px_16px_rgba(86,153,51,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(86,153,51,0.45)] transition-all duration-[220ms]"
          dir="rtl"
        >
          <FaUserPlus /> إضافة موظف
        </button>
      </div>

      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: 'ابحث بالاسم أو رقم الموظف أو البريد الإلكتروني…' }}
      />

      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">
          <span>⚠ {error}</span>
          <button onClick={() => fetchEmployees(search, page)} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px] hover:bg-red-50 transition-colors">إعادة المحاولة</button>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={employees}
        rowKey={e => e.employee_id}
        loading={loading}
        animationKey={`${search}-${page}`}
        emptyIcon={FaUsers}
        emptyTitle="لا يوجد موظفون"
        hasFilters={!!search}
        onClearFilters={() => setSearch('')}
        page={page}
        totalPages={meta.last_page}
        onPageChange={setPage}
      />
    </>
  )
}
