import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaArrowRight, FaSpinner, FaUser } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import StudentStatusBadge from '../components/StudentStatusBadge'
import {
  academicLevelLabel,
  formatDate,
  fullStudentName,
  genderLabel,
  studentStatusLabel,
} from '../utils/studentDisplay'

function InfoField({ label, value }) {
  const display = value === null || value === undefined || value === '' ? '—' : value

  return (
    <div className="flex flex-col gap-1 min-w-0">
      <span className="text-[11px] font-bold text-text-light uppercase tracking-wide">{label}</span>
      <span className="text-[14px] font-semibold text-text-dark break-words">{display}</span>
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
        <FaSpinner className="text-[30px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[14px] font-medium">جاري تحميل ملف الطالب…</span>
      </div>
    )
  }

  if (error || !profile) {
    return <ErrorState message={error || 'لم يتم العثور على الطالب.'} onBack={goBack} />
  }

  const levelLabel = academicLevelLabel(profile.academic_level)
  const programName = profile.program?.program_name
  const name = fullStudentName(profile)
  const longDateOptions = { year: 'numeric', month: 'long', day: 'numeric' }

  const identityFields = [
    { label: 'رقم القيد', value: profile.student_number },
    { label: 'الاسم الكامل', value: name },
    { label: 'اسم الأب', value: profile.father_name },
    { label: 'اسم الأم', value: profile.mother_name },
    { label: 'الجنس', value: genderLabel(profile.gender) },
    { label: 'تاريخ الميلاد', value: formatDate(profile.date_of_birth, longDateOptions) },
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
    { label: 'تاريخ القبول', value: formatDate(profile.enrollment_date, longDateOptions) },
    { label: 'الحالة', value: studentStatusLabel(profile) },
  ]

  return (
    <div dir="rtl">
      <div className="flex items-center justify-between mb-5 gap-3 flex-wrap">
        <button
          type="button"
          className="flex items-center gap-2 text-[13.5px] font-semibold text-text-gray hover:text-primary transition-colors"
          onClick={goBack}
        >
          <FaArrowRight aria-hidden="true" />
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
            <FaUser aria-hidden="true" />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap mb-1">
              <h2 className="text-[20px] font-black text-text-dark break-words">{name}</h2>
              <StudentStatusBadge status={profile.student_status} />
            </div>
            <div className="flex items-center gap-2.5 flex-wrap text-[12.5px] text-text-gray">
              <span className="font-mono bg-primary/7 border border-primary/15 px-2 py-0.5 rounded-[6px] text-primary-dark font-bold text-[12px]">
                {profile.student_number || '—'}
              </span>
              {programName && (
                <>
                  <span className="text-primary/30">•</span>
                  <span className="break-words">{programName}</span>
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
