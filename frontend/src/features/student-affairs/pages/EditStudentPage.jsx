import { useState, useEffect } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaSpinner, FaCheckCircle, FaEdit } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }
}

// Convert ISO date string → YYYY-MM-DD for <input type="date">
const toDateInput = (iso) => (iso ? iso.slice(0, 10) : '')

// ── Sub-components (outside function to avoid React 19 crash) ─────────────────

const inputCls = (err) => [
  'w-full py-[11px] px-3.5 border-[1.5px] rounded-[11px] text-[13.5px] font-medium bg-white text-text-dark outline-none transition-all duration-[220ms]',
  err
    ? 'border-red-400 bg-red-500/[0.03] focus:border-red-500 focus:shadow-[0_0_0_3px_rgba(239,68,68,0.1)]'
    : 'border-primary/20 focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)]',
].join(' ')

function Field({ label, id, req, err, children }) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-[12.5px] font-bold text-text-dark" dir="rtl">
        {label} {req && <span className="text-red-500">*</span>}
      </label>
      {children}
      {err && <span className="text-[11.5px] text-red-500 font-medium" dir="rtl">{err}</span>}
    </div>
  )
}

function Section({ title }) {
  return (
    <div className="flex items-center gap-3 mb-4 mt-6 first:mt-0">
      <div className="h-px flex-1 bg-primary/12" />
      <span className="text-[11px] font-bold text-text-light uppercase tracking-wider whitespace-nowrap">{title}</span>
      <div className="h-px flex-1 bg-primary/12" />
    </div>
  )
}

// ── EditStudentPage ───────────────────────────────────────────────────────────

const EMPTY = {
  student_number: '', first_name: '', last_name: '', father_name: '', mother_name: '',
  date_of_birth: '', gender: '', address: '', phone_number: '', email: '',
  emergency_contact: '', nationality: '', notes: '',
  academic_program_id: '', current_academic_level_id: '', student_status_id: '',
  enrollment_date: '',
}

export default function EditStudentPage() {
  const { id }                          = useParams()
  const navigate                        = useNavigate()
  const [form, setForm]                 = useState(EMPTY)
  const [errors, setErrors]             = useState({})
  const [submitting, setSubmitting]     = useState(false)
  const [loadingStudent, setLoadingStudent] = useState(true)
  const [success, setSuccess]           = useState(null)
  const [programs, setPrograms]         = useState([])
  const [levels, setLevels]             = useState([])
  const [statuses, setStatuses]         = useState([])
  const [studentName, setStudentName]   = useState('')

  useEffect(() => {
    const h = { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }

    Promise.all([
      fetch(`${API}/students/${id}`,     { headers: h }),
      fetch(`${API}/academic-programs`,  { headers: h }),
      fetch(`${API}/academic-levels`,    { headers: h }),
      fetch(`${API}/student-statuses`,   { headers: h }),
    ])
      .then(([r1, r2, r3, r4]) => {
        if ([r1, r2, r3, r4].some(r => r.status === 401)) {
          navigate('/login')
          return Promise.reject('401')
        }
        return Promise.all([r1.json(), r2.json(), r3.json(), r4.json()])
      })
      .then(([student, prog, lvl, stat]) => {
        if (!student.success) {
          setErrors({ _global: 'الطالب غير موجود.' })
          setLoadingStudent(false)
          return
        }
        const s = student.data
        setStudentName(`${s.first_name} ${s.last_name}`)
        setForm({
          student_number:           s.student_number          ?? '',
          first_name:               s.first_name              ?? '',
          last_name:                s.last_name               ?? '',
          father_name:              s.father_name             ?? '',
          mother_name:              s.mother_name             ?? '',
          date_of_birth:            toDateInput(s.date_of_birth),
          gender:                   s.gender                  ?? '',
          address:                  s.address                 ?? '',
          phone_number:             s.phone_number            ?? '',
          email:                    s.email                   ?? '',
          emergency_contact:        s.emergency_contact       ?? '',
          nationality:              s.nationality             ?? '',
          notes:                    s.notes                   ?? '',
          academic_program_id:      s.academic_program_id     ?? '',
          current_academic_level_id:s.current_academic_level_id ?? '',
          student_status_id:        s.student_status_id       ?? '',
          enrollment_date:          toDateInput(s.enrollment_date),
        })

        const toArr = (d) => Array.isArray(d) ? d : (Array.isArray(d?.data) ? d.data : [])
        setPrograms(toArr(prog.data))
        setLevels(toArr(lvl.data))
        setStatuses(toArr(stat.data))
        setLoadingStudent(false)
      })
      .catch((err) => {
        if (err !== '401') {
          setErrors({ _global: 'تعذّر تحميل البيانات. تأكد أن php artisan serve يعمل.' })
          setLoadingStudent(false)
        }
      })
  }, [id, navigate])

  const set = (field) => (e) => {
    setForm(prev => ({ ...prev, [field]: e.target.value }))
    if (errors[field]) setErrors(prev => ({ ...prev, [field]: '' }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSubmitting(true)
    try {
      const body = Object.fromEntries(
        Object.entries(form).filter(([, val]) => val !== '')
      )
      const res  = await fetch(`${API}/students/${id}`, {
        method: 'PUT',
        headers: authHeaders(),
        body: JSON.stringify(body),
      })
      const json = await res.json()
      if (json.success) {
        setSuccess(json.data)
        setTimeout(() => navigate('/student-affairs/students'), 1800)
      } else {
        if (json.errors) setErrors(json.errors)
        else setErrors({ _global: json.message || 'حدث خطأ غير متوقع' })
      }
    } catch {
      setErrors({ _global: 'تعذّر الاتصال بالخادم' })
    } finally {
      setSubmitting(false)
    }
  }

  // ── Loading ────────────────────────────────────────────────────────────────
  if (loadingStudent) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center gap-3 text-primary-light text-[15px] font-medium">
        <FaSpinner className="text-[24px] animate-[spin_0.7s_linear_infinite]" />
        <span dir="rtl">جاري تحميل بيانات الطالب…</span>
      </div>
    )
  }

  // ── Success ────────────────────────────────────────────────────────────────
  if (success) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <motion.div
          className="flex flex-col items-center gap-4 text-center px-8 py-12 bg-white rounded-[20px] border border-primary/15 shadow-[0_8px_40px_rgba(86,153,51,0.12)] max-w-md"
          initial={{ scale: 0.85, opacity: 0 }}
          animate={{ scale: 1, opacity: 1 }}
          transition={{ type: 'spring', stiffness: 240, damping: 22 }}
        >
          <div className="w-[72px] h-[72px] rounded-full bg-primary/10 flex items-center justify-center text-[32px] text-primary shadow-[0_4px_20px_rgba(86,153,51,0.25)]">
            <FaCheckCircle />
          </div>
          <h2 className="text-[20px] font-black text-text-dark" dir="rtl">تمّ التعديل بنجاح!</h2>
          <p className="text-[14px] text-text-gray" dir="rtl">
            تمّ تحديث بيانات الطالب <strong>{success.first_name} {success.last_name}</strong>
          </p>
          <p className="text-[12px] text-text-light" dir="rtl">جاري التحويل إلى قائمة الطلاب…</p>
        </motion.div>
      </div>
    )
  }

  // ── Form ───────────────────────────────────────────────────────────────────
  return (
    <div className="max-w-[860px] mx-auto">

      {errors._global && (
        <div className="flex items-center gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-5 py-3.5 mb-5 text-[13.5px] text-red-600" dir="rtl">
          ⚠ {errors._global}
        </div>
      )}

      {/* Header */}
      <div className="flex items-center gap-3 mb-6" dir="rtl">
        <div className="w-11 h-11 rounded-[13px] bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-[19px] text-amber-500 flex-shrink-0">
          <FaEdit />
        </div>
        <div>
          <h2 className="text-[19px] font-black text-text-dark leading-tight">تعديل بيانات الطالب</h2>
          <p className="text-[12px] text-text-light mt-0.5">{studentName} · Edit Student</p>
        </div>
      </div>

      <form onSubmit={handleSubmit} noValidate>
        <div className="bg-white border border-primary/12 rounded-[18px] p-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">

          {/* Required */}
          <Section title="المعلومات الأساسية • Required" />
          <div className="grid grid-cols-3 max-[720px]:grid-cols-2 max-[480px]:grid-cols-1 gap-4 mb-5">
            <Field label="رقم القيد" id="student_number" req err={errors.student_number}>
              <input id="student_number" className={inputCls(errors.student_number)} value={form.student_number} onChange={set('student_number')} dir="rtl" />
            </Field>
            <Field label="الاسم الأول" id="first_name" req err={errors.first_name}>
              <input id="first_name" className={inputCls(errors.first_name)} value={form.first_name} onChange={set('first_name')} dir="rtl" placeholder="الاسم الأول" />
            </Field>
            <Field label="اسم الأب" id="father_name" req err={errors.father_name}>
              <input id="father_name" className={inputCls(errors.father_name)} value={form.father_name} onChange={set('father_name')} dir="rtl" placeholder="اسم الأب" />
            </Field>
            <Field label="الاسم الأخير" id="last_name" req err={errors.last_name}>
              <input id="last_name" className={inputCls(errors.last_name)} value={form.last_name} onChange={set('last_name')} dir="rtl" placeholder="الاسم الأخير" />
            </Field>
            <Field label="تاريخ الالتحاق" id="enrollment_date" req err={errors.enrollment_date}>
              <input id="enrollment_date" type="date" className={inputCls(errors.enrollment_date)} value={form.enrollment_date} onChange={set('enrollment_date')} />
            </Field>
          </div>

          {/* Academic */}
          <Section title="المعلومات الأكاديمية • Academic" />
          <div className="grid grid-cols-3 max-[720px]:grid-cols-2 max-[480px]:grid-cols-1 gap-4 mb-5">
            <Field label="البرنامج الأكاديمي" id="academic_program_id" req err={errors.academic_program_id}>
              <select id="academic_program_id" className={inputCls(errors.academic_program_id)} value={form.academic_program_id} onChange={set('academic_program_id')} dir="rtl">
                <option value="">اختر البرنامج</option>
                {programs.map(p => <option key={p.academic_program_id} value={p.academic_program_id}>{p.program_name}</option>)}
              </select>
            </Field>
            <Field label="المرحلة الدراسية" id="current_academic_level_id" req err={errors.current_academic_level_id}>
              <select id="current_academic_level_id" className={inputCls(errors.current_academic_level_id)} value={form.current_academic_level_id} onChange={set('current_academic_level_id')} dir="rtl">
                <option value="">اختر المرحلة</option>
                {levels.map(l => <option key={l.academic_level_id} value={l.academic_level_id}>{l.level_name}</option>)}
              </select>
            </Field>
            <Field label="الحالة الدراسية" id="student_status_id" req err={errors.student_status_id}>
              <select id="student_status_id" className={inputCls(errors.student_status_id)} value={form.student_status_id} onChange={set('student_status_id')} dir="rtl">
                <option value="">اختر الحالة</option>
                {statuses.map(s => <option key={s.student_status_id} value={s.student_status_id}>{s.status_name}</option>)}
              </select>
            </Field>
          </div>

          {/* Personal (optional) */}
          <Section title="التفاصيل الشخصية • Personal (اختياري)" />
          <div className="grid grid-cols-3 max-[720px]:grid-cols-2 max-[480px]:grid-cols-1 gap-4 mb-5">
            <Field label="اسم الأم" id="mother_name" err={errors.mother_name}>
              <input id="mother_name" className={inputCls(errors.mother_name)} value={form.mother_name} onChange={set('mother_name')} dir="rtl" placeholder="اسم الأم" />
            </Field>
            <Field label="تاريخ الميلاد" id="date_of_birth" err={errors.date_of_birth}>
              <input id="date_of_birth" type="date" className={inputCls(errors.date_of_birth)} value={form.date_of_birth} onChange={set('date_of_birth')} />
            </Field>
            <Field label="الجنسية" id="nationality" err={errors.nationality}>
              <input id="nationality" className={inputCls(errors.nationality)} value={form.nationality} onChange={set('nationality')} dir="rtl" placeholder="سورية" />
            </Field>
            <Field label="الجنس" id="gender" err={errors.gender}>
              <select id="gender" className={inputCls(errors.gender)} value={form.gender} onChange={set('gender')} dir="rtl">
                <option value="">اختر الجنس</option>
                <option value="male">ذكر · Male</option>
                <option value="female">أنثى · Female</option>
              </select>
            </Field>
            <Field label="رقم الهاتف" id="phone_number" err={errors.phone_number}>
              <input id="phone_number" className={inputCls(errors.phone_number)} value={form.phone_number} onChange={set('phone_number')} dir="rtl" placeholder="+963..." />
            </Field>
            <Field label="البريد الإلكتروني" id="email" err={errors.email}>
              <input id="email" type="email" className={inputCls(errors.email)} value={form.email} onChange={set('email')} placeholder="example@email.com" />
            </Field>
            <Field label="العنوان" id="address" err={errors.address}>
              <input id="address" className={inputCls(errors.address)} value={form.address} onChange={set('address')} dir="rtl" placeholder="المدينة، الحي…" />
            </Field>
          </div>

        </div>

        {/* Actions */}
        <div className="flex items-center gap-3 mt-5 justify-end" dir="rtl">
          <button
            type="button"
            onClick={() => navigate('/student-affairs/students')}
            className="px-6 py-[11px] border-[1.5px] border-primary/25 rounded-[12px] text-[14px] font-bold text-text-gray bg-white cursor-pointer transition-all duration-[220ms] hover:border-primary/50 hover:text-text-dark"
          >
            إلغاء
          </button>
          <button
            type="submit"
            disabled={submitting}
            className="flex items-center gap-2 px-7 py-[11px] bg-gradient-to-br from-amber-500 to-amber-600 text-white rounded-[12px] text-[14px] font-bold cursor-pointer shadow-[0_4px_16px_rgba(245,158,11,0.35)] transition-all duration-[220ms] disabled:opacity-60 disabled:cursor-not-allowed hover:not-disabled:-translate-y-0.5 hover:not-disabled:shadow-[0_8px_24px_rgba(245,158,11,0.45)]"
            dir="rtl"
          >
            {submitting ? (
              <>
                <FaSpinner className="animate-[spin_0.7s_linear_infinite]" />
                <span>جاري الحفظ…</span>
              </>
            ) : (
              <>
                <FaEdit />
                <span>حفظ التعديلات</span>
              </>
            )}
          </button>
        </div>
      </form>
    </div>
  )
}
