import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaExclamationCircle, FaRedo } from 'react-icons/fa'
import AcademicRequirementProgress, {
  AcademicRequirementProgressSkeleton,
} from '../../../components/academic/AcademicRequirementProgress'
import { apiRequest } from '../../../services/apiClient'

export default function StudentRequirements() {
  const navigate = useNavigate()
  const [requirements, setRequirements] = useState(null)
  const [eligibility, setEligibility] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [configError, setConfigError] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)
  const loadProgress = useCallback(() => setReloadKey(current => current + 1), [])

  useEffect(() => {
    let active = true
    ;(async () => {
      setLoading(true)
      setError('')
      setConfigError(false)
      try {
        const [requirementsResponse, eligibilityResponse] = await Promise.all([
          apiRequest('/v1/student/requirements'),
          apiRequest('/v1/student/graduation-eligibility'),
        ])
        if (!active) return
        setRequirements(requirementsResponse?.data ?? null)
        setEligibility(eligibilityResponse?.data ?? null)
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        if (requestError.errorCode === 'academic_requirement_configuration_invalid') {
          setConfigError(true)
          setError('')
          return
        }
        setError(
          requestError.status === 403
            ? 'ليس لديك صلاحية لعرض تقدم الخطة الدراسية.'
            : 'تعذّر تحميل تقدم الخطة الدراسية. يرجى المحاولة مرة أخرى.',
        )
      } finally {
        if (active) setLoading(false)
      }
    })()
    return () => { active = false }
  }, [navigate, reloadKey])

  return (
    <div dir="rtl">
      <div className="flex items-start justify-between gap-3 mb-6 flex-wrap">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الخطة والتقدم الأكاديمي</h2>
          <p className="text-[12.5px] text-text-light leading-7">تابع استيفاء متطلبات خطتك الدراسية والساعات المحتسبة للتخرج.</p>
        </div>
        <button type="button" onClick={loadProgress} className="inline-flex items-center gap-2 px-3.5 py-2 rounded-[10px] border border-primary/20 text-[12.5px] font-bold text-primary-dark hover:bg-primary/8">
          <FaRedo aria-hidden="true" />
          تحديث البيانات
        </button>
      </div>

      {loading ? <AcademicRequirementProgressSkeleton /> : null}

      {!loading && configError ? (
        <section className="bg-white border border-red-200 rounded-[18px] px-6 py-10 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]" role="alert">
          <FaExclamationCircle className="mx-auto text-[34px] text-red-500 mb-4" aria-hidden="true" />
          <h3 className="text-[16px] font-black text-text-dark mb-2">تعذر حساب تقدم الخطة الدراسية حالياً بسبب إعداد أكاديمي يحتاج إلى مراجعة.</h3>
          <p className="text-[13.5px] text-text-light leading-7">يرجى مراجعة شؤون الطلاب.</p>
        </section>
      ) : null}

      {!loading && !configError && error ? (
        <section className="bg-white border border-red-200 rounded-[18px] px-5 py-5 text-[13.5px] text-red-700" role="alert">
          <p className="mb-3">{error}</p>
          <button type="button" onClick={loadProgress} className="inline-flex items-center gap-2 px-3.5 py-2 rounded-[10px] border border-red-200 text-[12.5px] font-bold text-red-700 hover:bg-red-50">
            <FaRedo aria-hidden="true" />
            إعادة المحاولة
          </button>
        </section>
      ) : null}

      {!loading && !configError && !error ? (
        <AcademicRequirementProgress progress={requirements} eligibility={eligibility} selfView />
      ) : null}
    </div>
  )
}
