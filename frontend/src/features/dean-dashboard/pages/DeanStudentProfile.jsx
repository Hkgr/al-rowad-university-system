import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaArrowRight, FaSpinner, FaUser } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'

const YEAR_ORDINALS_AR = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة', 'السادسة', 'السابعة']

const STATUS_MAP = {
  1: { ar: 'يدرس حاليًا', color: '#22c55e', bg: 'rgba(34,197,94,0.1)' },
  2: { ar: 'منقطع', color: '#3b82f6', bg: 'rgba(59,130,246,0.1)' },
  3: { ar: 'خريج', color: '#8b5cf6', bg: 'rgba(139,92,246,0.1)' },
  4: { ar: 'مسحوب', color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
  5: { ar: 'مفصول', color: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  6: { ar: 'موقوف', color: '#f97316', bg: 'rgba(249,115,22,0.1)' },
}

function arabicYearLabel(order) {
  if (!order || order < 1) return null
  const word = YEAR_ORDINALS_AR[order - 1]
  return word ? `السنة ${word}` : `السنة ${order}`
}

function formatDate(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('ar-SY', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

function genderLabel(gender) {
  if (gender === 'male') return 'ذكر'
  if (gender === 'female') return 'أنثى'
  return gender || '—'
}

function academicLevelLabel(level) {
  if (!level) return null
  return arabicYearLabel(level.level_order) ?? level.level_name ?? null
}

function statusPresentation(status) {
  const mapped = STATUS_MAP[status?.student_status_id]
  if (mapped) return mapped
  if (status?.status_name) {
    return { ar: status.status_name, color: '#64748b', bg: 'rgba(100,116,139,0.1)' }
  }
  return null
}

function InfoField({ label, value }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-[11px] font-bold text-text-light uppercase tracking-wide">{label}</span>
      <span className="text-[14px] font-semibold text-text-dark">{value || '—'}</span>
    </div>
  )
}

function SectionTitle({ title }) {
  return (
    <div className="mb-4 pb-2.5 border-b border-primary/12">
      <h3 className="text-[15px] font-extrabold text-text-dark">{title}</h3>
    </div>
  )
}

function StatusBadge({ status }) {
  const presentation = statusPresentation(status)
  if (!presentation) return <span className="text-[12px] text-text-light">—</span>

  return (
    <span
      className="inline-flex items-center px-3 py-0.5 rounded-full text-[12px] font-bold whitespace-nowrap"
      style={{ color: presentation.color, background: presentation.bg }}
    >
      {presentation.ar}
    </span>
  )
}

function ErrorState({ message, onBack }) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 py-24" dir="rtl">
      <p className="text-[15px] text-red-600 font-bold text-center px-4">⚠ {message}</p>
      <button
        type="button"
        className="px-5 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
        onClick={onBack}
      >
        العودة إلى طلاب الكلية
      </button>
    </div>
  )
}

export default function DeanStudentProfile() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [profile, setProfile] = useState(null)

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')
      setProfile(null)

      try {
        const response = await apiRequest(`/v1/students/${id}/profile`)
        if (!active) return
        setProfile(response?.data ?? null)
      } catch (requestError) {
        if (!active) return

        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }

        if (requestError.status === 403) {
          setError('لا تملك صلاحية لعرض ملف هذا الطالب.')
          return
        }

        if (requestError.status === 404) {
          setError('لم يتم العثور على الطالب.')
          return
        }

        setError('تعذّر تحميل ملف الطالب. يرجى المحاولة مرة أخرى.')
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [id, navigate])

  const goBack = () => navigate('/dean/students')

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-primary-light" dir="rtl">
        <FaSpinner className="text-[30px] animate-[spin_0.7s_linear_infinite]" />
        <span className="text-[14px] font-medium">جاري تحميل ملف الطالب…</span>
      </div>
    )
  }

  if (error || !profile) {
    return <ErrorState message={error || 'لم يتم العثور على الطالب.'} onBack={goBack} />
  }

  const levelLabel = academicLevelLabel(profile.academic_level)
  const programName = profile.program?.program_name
  const fullName = profile.full_name
    || `${profile.first_name ?? ''} ${profile.last_name ?? ''}`.trim()
    || '—'

  const identityFields = [
    { label: 'رقم القيد', value: profile.student_number },
    { label: 'الاسم الكامل', value: fullName },
    { label: 'اسم الأب', value: profile.father_name },
    { label: 'اسم الأم', value: profile.mother_name },
    { label: 'الجنس', value: genderLabel(profile.gender) },
    { label: 'تاريخ الميلاد', value: formatDate(profile.date_of_birth) },
    { label: 'الجنسية', value: profile.nationality },
  ]

  const contactFields = [
    { label: 'رقم الهاتف', value: profile.phone_number },
    { label: 'البريد الإلكتروني', value: profile.email },
    { label: 'العنوان', value: profile.address },
  ]

  const academicFields = [
    { label: 'التخصص', value: programName },
    { label: 'القسم', value: profile.department?.department_name },
    { label: 'الكلية', value: profile.college?.college_name },
    { label: 'السنة الدراسية', value: levelLabel },
    { label: 'تاريخ القبول', value: formatDate(profile.enrollment_date) },
    {
      label: 'الحالة',
      value: statusPresentation(profile.student_status)?.ar
        ?? profile.student_status?.status_name,
    },
  ]

  return (
    <div dir="rtl">
      <div className="flex items-center justify-between mb-5 gap-3 flex-wrap">
        <button
          type="button"
          className="flex items-center gap-2 text-[13.5px] font-semibold text-text-gray hover:text-primary transition-colors"
          onClick={goBack}
        >
          <FaArrowRight />
          <span>العودة إلى طلاب الكلية</span>
        </button>
      </div>

      <motion.div
        className="bg-white border border-primary/12 rounded-[18px] px-6 py-5 mb-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)]"
        initial={{ opacity: 0, y: -12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        <div className="flex items-center gap-5 flex-wrap">
          <div className="w-[68px] h-[68px] flex-shrink-0 rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-[28px] text-primary">
            <FaUser />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap mb-1">
              <h2 className="text-[20px] font-black text-text-dark">{fullName}</h2>
              <StatusBadge status={profile.student_status} />
            </div>
            <div className="flex items-center gap-2.5 flex-wrap text-[12.5px] text-text-gray">
              <span className="font-mono bg-primary/7 border border-primary/15 px-2 py-0.5 rounded-[6px] text-primary-dark font-bold text-[12px]">
                {profile.student_number || '—'}
              </span>
              {programName && (
                <>
                  <span className="text-primary/30">•</span>
                  <span>{programName}</span>
                </>
              )}
              {levelLabel && (
                <>
                  <span className="text-primary/30">•</span>
                  <span>{levelLabel}</span>
                </>
              )}
            </div>
          </div>
        </div>
      </motion.div>

      <div className="bg-white border border-primary/12 rounded-[18px] shadow-[0_2px_16px_rgba(26,46,16,0.06)] overflow-hidden">
        <div className="p-6 space-y-7">
          <section>
            <SectionTitle title="بيانات الهوية" />
            <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
              {identityFields.map(field => (
                <InfoField key={field.label} {...field} />
              ))}
            </div>
          </section>

          <section>
            <SectionTitle title="معلومات التواصل" />
            <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
              {contactFields.map(field => (
                <InfoField key={field.label} {...field} />
              ))}
            </div>
          </section>

          <section>
            <SectionTitle title="البيانات الأكاديمية" />
            <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
              {academicFields.map(field => (
                <InfoField key={field.label} {...field} />
              ))}
            </div>
          </section>
        </div>
      </div>
    </div>
  )
}
