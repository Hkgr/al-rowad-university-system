import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import { FaSpinner, FaCheckCircle, FaUserPlus } from 'react-icons/fa'
import DashboardLayout from '../../../components/layout/DashboardLayout'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
    'Content-Type': 'application/json',
  }
}

const INITIAL = {
  student_number: '',
  first_name: '', last_name: '', father_name: '', mother_name: '',
  national_id: '', date_of_birth: '', gender: '', nationality: '', address: '',
  phone_number: '', email: '', emergency_contact: '',
  academic_program_id: '', current_academic_level_id: '', student_status_id: '',
  enrollment_date: '', notes: '',
}

// ── Helpers defined OUTSIDE the component so React never recreates their type ──

function inputCls(err) {
  return `w-full px-4 py-[10px] border-[1.5px] rounded-[11px] bg-white text-[14px] text-text-dark outline-none transition-all duration-[220ms] ${
    err
      ? 'border-red-400 shadow-[0_0_0_3px_rgba(239,68,68,0.08)] focus:border-red-400'
      : 'border-primary/20 focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)]'
  }`
}

function Field({ label, id, req, children, err }) {
  return (
    <div className="flex flex-col gap-1.5" dir="rtl">
      <label htmlFor={id} className="text-[13px] font-semibold text-text-dark">
        {label}
        {req && <span className="text-red-500 mr-1">*</span>}
      </label>
      {children}
      {err && <span className="text-[11.5px] text-red-500 font-medium">{err}</span>}
    </div>
  )
}

function Section({ title }) {
  return (
    <div className="flex items-center gap-3 mb-2 mt-1" dir="rtl">
      <h3 className="text-[14.5px] font-extrabold text-text-dark whitespace-nowrap">{title}</h3>
      <div className="h-px flex-1 bg-primary/15" />
    </div>
  )
}

// ─────────────────────────────────────────────────────────────────────────────

export default function AddStudentPage() {
  const [form, setForm]             = useState(INITIAL)
  const [errors, setErrors]         = useState({})
  const [submitting, setSubmitting] = useState(false)
  const [success, setSuccess]       = useState(null)
  const [programs, setPrograms]     = useState([])
  const [levels, setLevels]         = useState([])
  const [statuses, setStatuses]     = useState([])
  const navigate                    = useNavigate()

  useEffect(() => {
    const h = { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
    Promise.all([
      fetch(`${API}/academic-programs`, { headers: h }),
      fetch(`${API}/academic-levels`,   { headers: h }),
      fetch(`${API}/student-statuses`,  { headers: h }),
    ])
      .then(([r1, r2, r3]) => Promise.all([r1.json(), r2.json(), r3.json()]))
      .then(([p, l, s]) => {
        const toArr = (d) => Array.isArray(d) ? d : (Array.isArray(d?.data) ? d.data : [])
        setPrograms(toArr(p.data))
        setLevels(toArr(l.data))
        setStatuses(toArr(s.data))
      })
      .catch(() => {})
  }, [])

  const set = (field) => (e) => {
    setForm(prev => ({ ...prev, [field]: e.target.value }))
    if (errors[field]) setErrors(prev => ({ ...prev, [field]: '' }))
  }

  const validate = () => {
    const e = {}
    if (!form.student_number.trim())         e.student_number          = 'رقم القيد مطلوب'
    if (!form.first_name.trim())            e.first_name              = 'الاسم الأول مطلوب'
    if (!form.last_name.trim())             e.last_name               = 'الاسم الأخير مطلوب'
    if (!form.father_name.trim())           e.father_name             = 'اسم الأب مطلوب'
    if (!form.national_id.trim())           e.national_id             = 'رقم الهوية مطلوب'
    if (!form.academic_program_id)          e.academic_program_id     = 'اختر البرنامج الأكاديمي'
    if (!form.current_academic_level_id)    e.current_academic_level_id = 'اختر المرحلة الدراسية'
    if (!form.student_status_id)            e.student_status_id       = 'اختر الحالة'
    if (!form.enrollment_date)              e.enrollment_date         = 'تاريخ الالتحاق مطلوب'
    return e
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    const v = validate()
    if (Object.keys(v).length) { setErrors(v); return }
    setSubmitting(true)
    try {
      const body = Object.fromEntries(
        Object.entries(form).filter(([, val]) => val !== '')
      )
      const res  = await fetch(`${API}/students`, {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify(body),
      })
      const json = await res.json()
      if (json.success) {
        setSuccess(json.data)
        setTimeout(() => navigate('/students'), 1800)
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

  // ── Success screen ───────────────────────────────────────────────────────────
  if (success) {
    return (
      <DashboardLayout pageTitle="إضافة طالب · Add Student">
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
            <h2 className="text-[20px] font-black text-text-dark" dir="rtl">تمّت الإضافة بنجاح!</h2>
            <p className="text-[14px] text-text-gray" dir="rtl">
              تمّ تسجيل الطالب <strong>{success.first_name} {success.last_name}</strong>
            </p>
            {success.student_number && (
              <span className="px-4 py-2 bg-primary/8 border border-primary/20 rounded-[10px] text-[15px] font-black text-primary-dark font-mono tracking-wider">
                {success.student_number}
              </span>
            )}
            <p className="text-[12px] text-text-light" dir="rtl">جاري التحويل إلى قائمة الطلاب…</p>
          </motion.div>
        </div>
      </DashboardLayout>
    )
  }

  // ── Main form ────────────────────────────────────────────────────────────────
  return (
    <DashboardLayout pageTitle="إضافة طالب · Add Student">

      <div className="max-w-[860px] mx-auto">

        <div className="flex items-center gap-3 mb-6" dir="rtl">
          <div className="w-11 h-11 rounded-[13px] bg-primary/10 border border-primary/20 flex items-center justify-center text-[19px] text-primary flex-shrink-0">
            <FaUserPlus />
          </div>
          <div>
            <h2 className="text-[19px] font-black text-text-dark">إضافة طالب جديد</h2>
            <p className="text-[12px] text-text-light mt-0.5">Add New Student</p>
          </div>
        </div>

        <AnimatePresence>
          {errors._global && (
            <motion.div
              key="global-error"
              className="flex items-center gap-2.5 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 mb-5 text-[13.5px] text-red-600"
              dir="rtl"
              initial={{ opacity: 0, y: -8 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0 }}
            >
              ⚠ {errors._global}
            </motion.div>
          )}
        </AnimatePresence>

        <form onSubmit={handleSubmit} noValidate>
          <div className="bg-white border border-primary/12 rounded-[18px] px-7 py-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)] mb-5">

            <Section title="المعلومات الأساسية • Required" />
            <div className="grid grid-cols-3 max-[720px]:grid-cols-2 max-[480px]:grid-cols-1 gap-4 mb-5">
              <Field label="رقم القيد" id="student_number" req err={errors.student_number}>
                <input id="student_number" className={inputCls(errors.student_number)} value={form.student_number} onChange={set('student_number')} placeholder="2026-0001" dir="ltr" />
              </Field>
              <Field label="الاسم الأول" id="first_name" req err={errors.first_name}>
                <input id="first_name" className={inputCls(errors.first_name)} value={form.first_name} onChange={set('first_name')} placeholder="الاسم الأول" dir="rtl" />
              </Field>
              <Field label="اسم الأب" id="father_name" req err={errors.father_name}>
                <input id="father_name" className={inputCls(errors.father_name)} value={form.father_name} onChange={set('father_name')} placeholder="اسم الأب" dir="rtl" />
              </Field>
              <Field label="الاسم الأخير" id="last_name" req err={errors.last_name}>
                <input id="last_name" className={inputCls(errors.last_name)} value={form.last_name} onChange={set('last_name')} placeholder="الاسم الأخير" dir="rtl" />
              </Field>
              <Field label="رقم الهوية الوطنية" id="national_id" req err={errors.national_id}>
                <input id="national_id" className={inputCls(errors.national_id)} value={form.national_id} onChange={set('national_id')} placeholder="XXXXXXXXXX" dir="ltr" />
              </Field>
              <Field label="تاريخ الالتحاق" id="enrollment_date" req err={errors.enrollment_date}>
                <input id="enrollment_date" type="date" className={inputCls(errors.enrollment_date)} value={form.enrollment_date} onChange={set('enrollment_date')} dir="ltr" />
              </Field>
              <Field label="الجنس" id="gender" err={errors.gender}>
                <select id="gender" className={inputCls(errors.gender)} value={form.gender} onChange={set('gender')} dir="rtl">
                  <option value="">اختر الجنس</option>
                  <option value="male">ذكر</option>
                  <option value="female">أنثى</option>
                </select>
              </Field>
            </div>

            <Section title="المعلومات الأكاديمية • Academic" />
            <div className="grid grid-cols-3 max-[720px]:grid-cols-2 max-[480px]:grid-cols-1 gap-4 mb-5">
              <Field label="البرنامج الأكاديمي" id="academic_program_id" req err={errors.academic_program_id}>
                <select id="academic_program_id" className={inputCls(errors.academic_program_id)} value={form.academic_program_id} onChange={set('academic_program_id')} dir="rtl">
                  <option value="">اختر البرنامج</option>
                  {programs.map(p => <option key={p.id} value={p.id}>{p.name_ar ?? p.name}</option>)}
                </select>
              </Field>
              <Field label="المرحلة الدراسية" id="current_academic_level_id" req err={errors.current_academic_level_id}>
                <select id="current_academic_level_id" className={inputCls(errors.current_academic_level_id)} value={form.current_academic_level_id} onChange={set('current_academic_level_id')} dir="rtl">
                  <option value="">اختر المرحلة</option>
                  {levels.map(l => <option key={l.id} value={l.id}>{l.name_ar ?? l.name}</option>)}
                </select>
              </Field>
              <Field label="الحالة الدراسية" id="student_status_id" req err={errors.student_status_id}>
                <select id="student_status_id" className={inputCls(errors.student_status_id)} value={form.student_status_id} onChange={set('student_status_id')} dir="rtl">
                  <option value="">اختر الحالة</option>
                  {statuses.map(s => <option key={s.id} value={s.id}>{s.name_ar ?? s.name}</option>)}
                </select>
              </Field>
            </div>

            <Section title="التفاصيل الشخصية • Personal (اختياري)" />
            <div className="grid grid-cols-3 max-[720px]:grid-cols-2 max-[480px]:grid-cols-1 gap-4 mb-5">
              <Field label="اسم الأم" id="mother_name" err={errors.mother_name}>
                <input id="mother_name" className={inputCls(errors.mother_name)} value={form.mother_name} onChange={set('mother_name')} placeholder="اسم الأم" dir="rtl" />
              </Field>
              <Field label="تاريخ الميلاد" id="date_of_birth" err={errors.date_of_birth}>
                <input id="date_of_birth" type="date" className={inputCls(errors.date_of_birth)} value={form.date_of_birth} onChange={set('date_of_birth')} dir="ltr" />
              </Field>
              <Field label="الجنسية" id="nationality" err={errors.nationality}>
                <input id="nationality" className={inputCls(errors.nationality)} value={form.nationality} onChange={set('nationality')} placeholder="سورية" dir="rtl" />
              </Field>
              <Field label="العنوان" id="address" err={errors.address}>
                <input id="address" className={inputCls(errors.address)} value={form.address} onChange={set('address')} placeholder="العنوان الكامل" dir="rtl" />
              </Field>
            </div>

            <Section title="معلومات التواصل • Contact (اختياري)" />
            <div className="grid grid-cols-3 max-[720px]:grid-cols-2 max-[480px]:grid-cols-1 gap-4 mb-5">
              <Field label="رقم الهاتف" id="phone_number" err={errors.phone_number}>
                <input id="phone_number" className={inputCls(errors.phone_number)} value={form.phone_number} onChange={set('phone_number')} placeholder="09XXXXXXXX" dir="ltr" />
              </Field>
              <Field label="البريد الإلكتروني" id="email" err={errors.email}>
                <input id="email" type="email" className={inputCls(errors.email)} value={form.email} onChange={set('email')} placeholder="student@example.com" dir="ltr" />
              </Field>
              <Field label="جهة الاتصال في حالات الطوارئ" id="emergency_contact" err={errors.emergency_contact}>
                <input id="emergency_contact" className={inputCls(errors.emergency_contact)} value={form.emergency_contact} onChange={set('emergency_contact')} placeholder="الاسم ورقم الهاتف" dir="rtl" />
              </Field>
            </div>

            <Field label="ملاحظات" id="notes" err={errors.notes}>
              <textarea
                id="notes"
                className={`${inputCls(errors.notes)} resize-none h-[80px]`}
                value={form.notes}
                onChange={set('notes')}
                placeholder="ملاحظات إضافية…"
                dir="rtl"
              />
            </Field>

          </div>

          <div className="flex items-center justify-end gap-3" dir="rtl">
            <button
              type="button"
              className="px-6 py-[11px] border-[1.5px] border-primary/25 rounded-[12px] bg-white text-primary-dark text-[14px] font-bold cursor-pointer transition-all duration-200 hover:bg-primary/7 hover:border-primary/40"
              onClick={() => navigate('/students')}
            >
              إلغاء
            </button>
            <button
              type="submit"
              disabled={submitting}
              className="flex items-center gap-2.5 px-7 py-[11px] bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold cursor-pointer transition-all duration-[220ms] shadow-[0_4px_16px_rgba(86,153,51,0.35)] disabled:opacity-60 disabled:cursor-not-allowed"
              dir="rtl"
            >
              {submitting ? (
                <>
                  <FaSpinner className="animate-[spin_0.7s_linear_infinite]" />
                  <span>جاري الحفظ…</span>
                </>
              ) : (
                <>
                  <FaUserPlus />
                  <span>إضافة الطالب</span>
                </>
              )}
            </button>
          </div>
        </form>
      </div>

    </DashboardLayout>
  )
}
