import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaSpinner, FaSave, FaArrowRight } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const TYPE_AR   = { academic: 'أكاديمي', administrative: 'إداري', technical: 'تقني', service: 'خدمات', board_member: 'عضو مجلس' }
const STATUS_AR = { active: 'نشط', inactive: 'غير نشط', on_leave: 'في إجازة', terminated: 'منتهي الخدمة' }

export default function AddEmployeePage() {
  const navigate = useNavigate()
  const [types,    setTypes]   = useState([])
  const [statuses, setStatuses]= useState([])
  const [saving,   setSaving]  = useState(false)
  const [err,      setErr]     = useState('')
  const [fieldErr, setFieldErr]= useState({})
  const [form, setForm] = useState({
    employee_number: '', first_name: '', last_name: '', father_name: '', mother_name: '',
    phone_number: '', email: '', hire_date: '', employee_type_id: '', employee_status_id: '',
  })

  useEffect(() => {
    Promise.all([
      fetch(`${API}/employee-types?per_page=50`,    { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/employee-statuses?per_page=50`, { headers: authHeaders() }).then(r => r.json()),
    ]).then(([t, s]) => {
      setTypes(t.success   ? (t.data?.data ?? t.data ?? []) : [])
      setStatuses(s.success ? (s.data?.data ?? s.data ?? []) : [])
    })
  }, [])

  const set = (k, v) => { setForm(f => ({ ...f, [k]: v })); setFieldErr(e => ({ ...e, [k]: '' })) }

  async function handleSave() {
    setSaving(true); setErr(''); setFieldErr({})
    try {
      const res  = await fetch(`${API}/employees`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, employee_type_id: Number(form.employee_type_id), employee_status_id: Number(form.employee_status_id) }),
      })
      const json = await res.json()
      if (json.success) navigate('/hr/employees')
      else {
        setErr(json.message || 'فشلت العملية')
        if (json.errors) setFieldErr(json.errors)
      }
    } catch { setErr('تعذّر الاتصال بالخادم') }
    finally   { setSaving(false) }
  }

  const Field = ({ label, k, type = 'text', required }) => (
    <div>
      <label className="block text-[11.5px] font-bold text-text-dark mb-1.5" dir="rtl">{label}{required && <span className="text-red-500 mr-0.5">*</span>}</label>
      <input type={type} value={form[k] ?? ''} onChange={e => set(k, e.target.value)}
        className={`w-full px-3 py-2.5 border rounded-[9px] text-[13.5px] text-text-dark outline-none transition-colors focus:border-primary ${fieldErr[k] ? 'border-red-400 bg-red-50' : 'border-primary/20'}`}
        dir="rtl" />
      {fieldErr[k] && <p className="text-[11px] text-red-500 mt-1" dir="rtl">{fieldErr[k][0]}</p>}
    </div>
  )

  return (
    <>
      <div className="flex items-center gap-3 mb-6" dir="rtl">
        <button onClick={() => navigate('/hr/employees')}
          className="w-9 h-9 flex items-center justify-center rounded-[10px] border border-primary/20 text-primary hover:bg-primary/8 transition-colors">
          <FaArrowRight />
        </button>
        <div>
          <h2 className="text-[20px] font-black text-text-dark">إضافة موظف جديد</h2>
          <p className="text-[12px] text-text-light">Add New Employee</p>
        </div>
      </div>

      <div className="bg-white border border-primary/12 rounded-[18px] p-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)] max-w-[700px]">
        <div className="grid grid-cols-2 max-[560px]:grid-cols-1 gap-5" dir="rtl">
          <Field label="رقم الموظف"      k="employee_number" required />
          <Field label="تاريخ التعيين"   k="hire_date" type="date" />
          <Field label="الاسم الأول"     k="first_name"  required />
          <Field label="اسم العائلة"     k="last_name"   required />
          <Field label="اسم الأب"        k="father_name" />
          <Field label="اسم الأم"        k="mother_name" />
          <Field label="البريد الإلكتروني" k="email" type="email" />
          <Field label="رقم الهاتف"      k="phone_number" />

          <div>
            <label className="block text-[11.5px] font-bold text-text-dark mb-1.5" dir="rtl">نوع الموظف <span className="text-red-500">*</span></label>
            <select value={form.employee_type_id} onChange={e => set('employee_type_id', e.target.value)}
              className={`w-full px-3 py-2.5 border rounded-[9px] text-[13.5px] outline-none focus:border-primary transition-colors ${fieldErr.employee_type_id ? 'border-red-400 bg-red-50' : 'border-primary/20'}`} dir="rtl">
              <option value="">اختر النوع</option>
              {types.map(t => <option key={t.employee_type_id} value={t.employee_type_id}>{TYPE_AR[t.type_code] ?? t.type_name}</option>)}
            </select>
            {fieldErr.employee_type_id && <p className="text-[11px] text-red-500 mt-1" dir="rtl">{fieldErr.employee_type_id[0]}</p>}
          </div>

          <div>
            <label className="block text-[11.5px] font-bold text-text-dark mb-1.5" dir="rtl">الحالة <span className="text-red-500">*</span></label>
            <select value={form.employee_status_id} onChange={e => set('employee_status_id', e.target.value)}
              className={`w-full px-3 py-2.5 border rounded-[9px] text-[13.5px] outline-none focus:border-primary transition-colors ${fieldErr.employee_status_id ? 'border-red-400 bg-red-50' : 'border-primary/20'}`} dir="rtl">
              <option value="">اختر الحالة</option>
              {statuses.map(s => <option key={s.employee_status_id} value={s.employee_status_id}>{STATUS_AR[s.status_code] ?? s.status_name}</option>)}
            </select>
            {fieldErr.employee_status_id && <p className="text-[11px] text-red-500 mt-1" dir="rtl">{fieldErr.employee_status_id[0]}</p>}
          </div>
        </div>

        {err && <p className="mt-5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[9px] px-4 py-2.5" dir="rtl">⚠ {err}</p>}

        <div className="flex items-center gap-3 mt-6 pt-5 border-t border-primary/10" dir="rtl">
          <button onClick={handleSave} disabled={saving}
            className="flex items-center gap-2 px-6 py-2.5 bg-primary text-white rounded-[10px] text-[14px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors shadow-[0_4px_14px_rgba(86,153,51,0.3)]">
            {saving ? <FaSpinner className="animate-spin" /> : <FaSave />} حفظ الموظف
          </button>
          <button onClick={() => navigate('/hr/employees')}
            className="px-5 py-2.5 border border-primary/20 rounded-[10px] text-[14px] text-text-dark hover:bg-gray-50 transition-colors">
            إلغاء
          </button>
        </div>
      </div>
    </>
  )
}
