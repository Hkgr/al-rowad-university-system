import { useCallback, useMemo, useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  FaCheckCircle, FaChevronDown, FaClipboardCheck, FaExclamationCircle,
  FaHourglassHalf, FaRedo, FaUniversity,
} from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'

const SCOPE_ORDER = ['university', 'college', 'department']

const SCOPE_LABELS = {
  university: 'متطلبات الجامعة',
  college: 'متطلبات الكلية',
  department: 'متطلبات القسم',
}

const TYPE_LABELS = {
  mandatory: 'إجباري',
  elective: 'اختياري',
}

const BLOCKER_LABELS = {
  no_academic_program: 'لا يوجد برنامج أكاديمي مرتبط بسجلك.',
  academic_requirements_incomplete: 'ما تزال بعض متطلبات الخطة غير مكتملة.',
  mandatory_requirements_incomplete: 'ما تزال هناك متطلبات إجبارية غير مكتملة.',
  elective_requirements_incomplete: 'ما تزال هناك ساعات اختيارية مطلوبة.',
}

const RESULT_STATUS_LABELS = {
  passed: 'ناجح',
  failed: 'راسب',
  deprived: 'محروم',
  incomplete: 'غير مكتمل',
  withdrawn: 'منسحب',
}

const REGISTRATION_STATUS_LABELS = {
  registered: 'مسجل',
  withdrawn: 'منسحب',
  completed: 'مكتمل',
}

function asNumber(value) {
  const number = Number(value)
  return Number.isFinite(number) ? number : 0
}

function visualPercent(part, whole) {
  if (!whole || whole <= 0) return null
  return Math.min(100, Math.max(0, Math.round((part / whole) * 100)))
}

function courseKey(course, prefix, index) {
  return [
    prefix,
    course.student_course_registration_id,
    course.student_registration_request_item_id,
    course.course_id,
    course.course_offering_id,
    index,
  ].filter(value => value !== undefined && value !== null).join('-')
}

function SkeletonBlock({ className }) {
  return <div className={`animate-pulse rounded-[12px] bg-primary/10 ${className}`} />
}

function RequirementsSkeleton() {
  return (
    <div className="space-y-5" dir="rtl" aria-busy="true" aria-live="polite">
      <div className="grid grid-cols-4 max-[980px]:grid-cols-2 max-[520px]:grid-cols-1 gap-3">
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
        <SkeletonBlock className="h-24" />
      </div>
      <SkeletonBlock className="h-28" />
      <SkeletonBlock className="h-20" />
      <div className="grid grid-cols-2 max-[800px]:grid-cols-1 gap-3">
        <SkeletonBlock className="h-44" />
        <SkeletonBlock className="h-44" />
        <SkeletonBlock className="h-44" />
        <SkeletonBlock className="h-44" />
      </div>
    </div>
  )
}

function ProgressBar({ value, label }) {
  const pct = value == null ? 0 : value
  return (
    <div>
      <div
        className="h-2.5 rounded-full bg-primary/10 overflow-hidden"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={value == null ? 0 : pct}
        aria-label={label}
      >
        <div
          className="h-full rounded-full bg-primary transition-all duration-500"
          style={{ width: `${pct}%` }}
          aria-hidden="true"
        />
      </div>
    </div>
  )
}

function MetricCard({ label, value, hint }) {
  return (
    <article className="bg-white border border-primary/12 rounded-[16px] px-4 py-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
      <p className="text-[12px] font-bold text-text-light mb-2">{label}</p>
      <p className="text-[26px] font-black text-text-dark leading-none tabular-nums">{value}</p>
      {hint ? <p className="mt-2 text-[11.5px] text-text-light leading-6">{hint}</p> : null}
    </article>
  )
}

function StatusChip({ completed }) {
  if (completed) {
    return (
      <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-green-100 text-green-800 border border-green-200 text-[11px] font-bold">
        <FaCheckCircle aria-hidden="true" />
        مكتمل
      </span>
    )
  }
  return (
    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-primary/10 text-primary-dark border border-primary/20 text-[11px] font-bold">
      <FaHourglassHalf aria-hidden="true" />
      غير مكتمل
    </span>
  )
}

function CourseList({ title, courses, prefix }) {
  const [open, setOpen] = useState(false)
  if (!courses?.length) return null

  return (
    <div className="border-t border-primary/10 pt-3 mt-3">
      <button
        type="button"
        onClick={() => setOpen(current => !current)}
        className="w-full flex items-center justify-between gap-2 text-[12.5px] font-bold text-text-dark"
        aria-expanded={open}
      >
        <span>{title} <span className="tabular-nums text-text-light font-semibold">({courses.length})</span></span>
        <FaChevronDown className={`text-[11px] text-text-light transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
      </button>
      {open ? (
        <ul className="mt-2 space-y-1.5">
          {courses.map((course, index) => (
            <li
              key={courseKey(course, prefix, index)}
              className="flex items-start justify-between gap-3 rounded-[10px] bg-[#fafaf8] px-3 py-2"
            >
              <div className="min-w-0">
                <p className="text-[13px] font-bold text-text-dark break-words">{course.course_name || '—'}</p>
                <p className="text-[11px] text-text-light font-mono mt-0.5">{course.course_code || '—'}</p>
              </div>
              <span className="shrink-0 text-[12px] font-black text-text-dark tabular-nums">
                {asNumber(course.credit_hours)} س
              </span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

function GroupCard({ group }) {
  const type = String(group.requirement_type || '').toLowerCase()
  const isMandatory = type === 'mandatory'
  const isElective = type === 'elective'
  const completed = Boolean(group.completed)
  const required = asNumber(group.required_credit_hours)
  const earned = asNumber(group.earned_hours)
  const registered = asNumber(group.registered_in_progress_hours)
  const pending = asNumber(group.pending_request_hours)
  const remaining = asNumber(group.remaining_hours)
  const pool = asNumber(group.pool_credit_hours)
  const courseCount = asNumber(group.course_count)
  const passedCount = Array.isArray(group.passed_courses) ? group.passed_courses.length : 0
  const counted = group.graduation_counted_hours
  const progress = isMandatory && courseCount > 0
    ? visualPercent(passedCount, courseCount)
    : visualPercent(earned, required)

  return (
    <article className="bg-white border border-primary/12 rounded-[16px] p-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
      <div className="flex items-start justify-between gap-3 flex-wrap mb-3">
        <div className="min-w-0">
          {group.group_name ? (
            <h4 className="text-[14.5px] font-black text-text-dark break-words">{group.group_name}</h4>
          ) : null}
          <div className="flex items-center gap-1.5 mt-1.5 flex-wrap">
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-primary/8 text-primary-dark">
              {TYPE_LABELS[type] || group.requirement_type || '—'}
            </span>
            {group.group_code ? (
              <span className="text-[11px] font-mono text-text-light">{group.group_code}</span>
            ) : null}
          </div>
        </div>
        <StatusChip completed={completed} />
      </div>

      {progress == null ? (
        <p className="text-[12px] text-text-light mb-3">لا تتوفر ساعات مطلوبة لعرض نسبة التقدم.</p>
      ) : (
        <div className="mb-3">
          <div className="flex items-baseline justify-between gap-2 mb-1.5">
            <p className="text-[12px] text-text-gray">
              {isMandatory && courseCount > 0
                ? <span className="tabular-nums">{passedCount} من {courseCount} مقرر</span>
                : <span className="tabular-nums">{earned} من {required} ساعة</span>}
            </p>
            <p className="text-[12px] font-black text-primary tabular-nums">{progress}%</p>
          </div>
          <ProgressBar
            value={progress}
            label={isMandatory ? 'تقدم استيفاء المقررات الإجبارية' : 'تقدم الساعات الاختيارية المطلوبة'}
          />
        </div>
      )}

      {isMandatory ? (
        <div className="grid grid-cols-2 gap-2 mb-3">
          <p className="text-[12px] text-text-light">
            المقررات المجتازة
            <span className="block font-black text-text-dark tabular-nums text-[15px] mt-0.5">
              {passedCount} من {courseCount}
            </span>
          </p>
          <p className="text-[12px] text-text-light">
            الساعات المجتازة
            <span className="block font-black text-text-dark tabular-nums text-[15px] mt-0.5">
              {earned} من {required}
            </span>
          </p>
        </div>
      ) : null}

      {isElective ? (
        <div className="rounded-[12px] bg-primary/[0.04] border border-primary/10 px-3 py-2.5 mb-3">
          <p className="text-[12.5px] text-text-dark leading-7">
            المتاح ضمن المجموعة: <span className="font-black tabular-nums">{pool}</span> ساعات
          </p>
          <p className="text-[12.5px] text-text-dark leading-7">
            المطلوب اجتيازه: <span className="font-black tabular-nums">{required}</span> ساعات
          </p>
          {counted != null && counted !== earned ? (
            <p className="text-[12px] text-text-gray leading-6 mt-1">
              الساعات المحتسبة للتخرج من هذه المجموعة: <span className="font-black text-text-dark tabular-nums">{counted}</span>
            </p>
          ) : null}
        </div>
      ) : null}

      <dl className="grid grid-cols-2 max-[420px]:grid-cols-1 gap-x-3 gap-y-2 text-[12.5px]">
        <div className="flex justify-between gap-2">
          <dt className="text-text-light">المطلوب</dt>
          <dd className="font-black text-text-dark tabular-nums">{required}</dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt className="text-text-light">مجتاز ومعتمد</dt>
          <dd className="font-black text-text-dark tabular-nums">{earned}</dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt className="text-text-light">مسجل حالياً</dt>
          <dd className="font-black text-text-dark tabular-nums">{registered}</dd>
        </div>
        <div className="flex justify-between gap-2">
          <dt className="text-text-light">قيد طلب التسجيل</dt>
          <dd className="font-black text-text-dark tabular-nums">{pending}</dd>
        </div>
        <div className="flex justify-between gap-2 col-span-full">
          <dt className="text-text-light">المتبقي</dt>
          <dd className="font-black text-primary tabular-nums">{remaining}</dd>
        </div>
      </dl>

      {isElective && completed ? (
        <p className="mt-3 text-[12.5px] font-bold text-green-800 leading-6">
          تم استيفاء الساعات الاختيارية المطلوبة
        </p>
      ) : null}

      <CourseList title="المقررات المجتازة" courses={group.passed_courses} prefix="passed" />
      <CourseList title="المقررات المسجلة" courses={group.registered_courses} prefix="registered" />
      <CourseList title="المقررات قيد الطلب" courses={group.pending_courses} prefix="pending" />
    </article>
  )
}

function groupedByScope(groups) {
  const buckets = new Map()
  groups.forEach(group => {
    const scope = String(group.requirement_scope || 'other').toLowerCase()
    if (!buckets.has(scope)) buckets.set(scope, [])
    buckets.get(scope).push(group)
  })

  const known = SCOPE_ORDER.filter(scope => buckets.has(scope)).map(scope => [scope, buckets.get(scope)])
  const extra = [...buckets.entries()].filter(([scope]) => !SCOPE_ORDER.includes(scope))
  return [...known, ...extra]
}

function mergeCountedHours(groups, eligibilityGroups) {
  const countedById = new Map(
    (eligibilityGroups ?? []).map(group => [group.requirement_group_id, group.graduation_counted_hours]),
  )
  return groups.map(group => (
    countedById.has(group.requirement_group_id)
      ? { ...group, graduation_counted_hours: countedById.get(group.requirement_group_id) }
      : group
  ))
}

function topLevelBlockers(blockers) {
  return (blockers ?? []).filter(blocker => blocker && !blocker.requirement_group_id)
}

function statusLabel(map, value) {
  if (!value) return null
  return map[String(value).toLowerCase()] || value
}

export default function StudentRequirements() {
  const navigate = useNavigate()
  const [requirements, setRequirements] = useState(null)
  const [eligibility, setEligibility] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [configError, setConfigError] = useState(false)
  const [reloadKey, setReloadKey] = useState(0)

  const loadProgress = useCallback(() => {
    setReloadKey(current => current + 1)
  }, [])

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

  const groups = useMemo(
    () => mergeCountedHours(requirements?.groups ?? [], eligibility?.groups ?? []),
    [requirements, eligibility],
  )
  const outside = requirements?.outside_current_curriculum?.length
    ? requirements.outside_current_curriculum
    : (eligibility?.outside_current_curriculum ?? [])
  const blockers = topLevelBlockers(eligibility?.blockers)
  const noProgram = !requirements?.academic_program_id
    || blockers.some(blocker => blocker.code === 'no_academic_program')
    || eligibility?.blockers?.some(blocker => blocker.code === 'no_academic_program')

  const totalRequired = asNumber(eligibility?.total_required_hours ?? requirements?.total_required_hours)
  const actualEarned = asNumber(
    eligibility?.actual_earned_curriculum_hours ?? requirements?.earned_curriculum_hours,
  )
  const countedHours = asNumber(eligibility?.graduation_counted_hours)
  const remainingHours = asNumber(eligibility?.remaining_graduation_hours)
  const overallPct = visualPercent(countedHours, totalRequired)
  const eligible = eligibility?.eligible === true
  const readableBlockers = blockers
    .map(blocker => BLOCKER_LABELS[blocker.code])
    .filter(Boolean)
    .filter((label, index, list) => list.indexOf(label) === index)

  return (
    <div dir="rtl">
      <div className="flex items-start justify-between gap-3 mb-6 flex-wrap">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الخطة والتقدم الأكاديمي</h2>
          <p className="text-[12.5px] text-text-light leading-7">
            تابع استيفاء متطلبات خطتك الدراسية والساعات المحتسبة للتخرج.
          </p>
        </div>
        <button
          type="button"
          onClick={loadProgress}
          className="inline-flex items-center gap-2 px-3.5 py-2 rounded-[10px] border border-primary/20 text-[12.5px] font-bold text-primary-dark hover:bg-primary/8"
        >
          <FaRedo aria-hidden="true" />
          تحديث البيانات
        </button>
      </div>

      {loading ? <RequirementsSkeleton /> : null}

      {!loading && configError ? (
        <section className="bg-white border border-red-200 rounded-[18px] px-6 py-10 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]" role="alert">
          <FaExclamationCircle className="mx-auto text-[34px] text-red-500 mb-4" aria-hidden="true" />
          <h3 className="text-[16px] font-black text-text-dark mb-2">
            تعذر حساب تقدم الخطة الدراسية حالياً بسبب إعداد أكاديمي يحتاج إلى مراجعة.
          </h3>
          <p className="text-[13.5px] text-text-light leading-7">يرجى مراجعة شؤون الطلاب.</p>
        </section>
      ) : null}

      {!loading && !configError && error ? (
        <section className="bg-white border border-red-200 rounded-[18px] px-5 py-5 text-[13.5px] text-red-700" role="alert">
          <p className="mb-3">{error}</p>
          <button
            type="button"
            onClick={loadProgress}
            className="inline-flex items-center gap-2 px-3.5 py-2 rounded-[10px] border border-red-200 text-[12.5px] font-bold text-red-700 hover:bg-red-50"
          >
            <FaRedo aria-hidden="true" />
            إعادة المحاولة
          </button>
        </section>
      ) : null}

      {!loading && !configError && !error && noProgram ? (
        <section className="bg-white border border-primary/12 rounded-[18px] px-6 py-16 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
          <FaUniversity className="mx-auto text-[40px] text-primary/25 mb-4" aria-hidden="true" />
          <h3 className="text-[17px] font-black text-text-dark mb-2">لا توجد خطة أكاديمية مرتبطة بسجلك حالياً.</h3>
          <p className="text-[13.5px] text-text-light leading-7 max-w-[460px] mx-auto">
            بعد ربط برنامج دراسي بسجلك ستظهر هنا متطلبات الخطة وتقدمك نحو استيفائها.
          </p>
        </section>
      ) : null}

      {!loading && !configError && !error && !noProgram ? (
        <div className="space-y-5">
          <section className="grid grid-cols-4 max-[980px]:grid-cols-2 max-[520px]:grid-cols-1 gap-3">
            <MetricCard label="إجمالي الساعات المطلوبة" value={totalRequired} />
            <MetricCard
              label="الساعات المجتازة فعلياً"
              value={actualEarned}
              hint="ساعات معتمدة داخل الخطة الحالية، وقد تزيد عن الساعات المحتسبة للتخرج."
            />
            <MetricCard
              label="الساعات المحتسبة للتخرج"
              value={countedHours}
              hint="الساعات التي تُحتسب ضمن استيفاء الخطة، دون فائض المقررات الاختيارية."
            />
            <MetricCard label="الساعات المتبقية للتخرج" value={remainingHours} />
          </section>

          <section className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
            <div className="flex items-center justify-between gap-3 flex-wrap mb-3">
              <div className="flex items-center gap-2">
                <FaClipboardCheck className="text-primary" aria-hidden="true" />
                <h3 className="text-[15px] font-black text-text-dark">التقدم نحو التخرج</h3>
              </div>
              {overallPct == null ? (
                <p className="text-[13px] text-text-light">لا تتوفر ساعات مطلوبة لعرض نسبة التقدم.</p>
              ) : (
                <p className="text-[13.5px] font-black text-text-dark tabular-nums">
                  {countedHours} من {totalRequired} ساعة
                  <span className="text-primary mr-2">{overallPct}%</span>
                </p>
              )}
            </div>
            {overallPct == null ? null : (
              <ProgressBar value={overallPct} label="نسبة الساعات المحتسبة للتخرج من إجمالي المطلوب" />
            )}
          </section>

          {eligible ? (
            <section className="bg-green-50 border border-green-200 rounded-[16px] px-5 py-4">
              <p className="text-[14.5px] font-black text-green-900">تم استيفاء المتطلبات الأكاديمية للتخرج</p>
              <p className="mt-1 text-[13px] text-green-800 leading-7">
                استوفيت متطلبات الخطة الأكاديمية. اعتماد حالة التخرج يبقى إجراءً إدارياً منفصلاً.
              </p>
            </section>
          ) : (
            <section className="bg-primary/[0.06] border border-primary/15 rounded-[16px] px-5 py-4">
              <p className="text-[14.5px] font-black text-text-dark">متطلبات التخرج الأكاديمية غير مكتملة بعد</p>
              <p className="mt-1 text-[13px] text-text-gray leading-7">
                يتبقى <span className="font-black text-text-dark tabular-nums">{remainingHours}</span> ساعة محتسبة لاستيفاء الخطة.
              </p>
              {readableBlockers.length > 0 ? (
                <ul className="mt-2 space-y-1">
                  {readableBlockers.map(label => (
                    <li key={label} className="text-[12.5px] text-text-dark">{label}</li>
                  ))}
                </ul>
              ) : null}
            </section>
          )}

          {groupedByScope(groups).map(([scope, scopeGroups]) => (
            <section key={scope}>
              <h3 className="text-[15px] font-black text-text-dark mb-3">
                {SCOPE_LABELS[scope] || scope}
              </h3>
              <div className="grid grid-cols-2 max-[800px]:grid-cols-1 gap-3">
                {scopeGroups.map(group => (
                  <GroupCard
                    key={group.requirement_group_id || `${scope}-${group.group_code}-${group.requirement_type}`}
                    group={group}
                  />
                ))}
              </div>
            </section>
          ))}

          {outside.length > 0 ? (
            <section className="bg-white border border-primary/12 rounded-[18px] overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
              <div className="px-5 py-4 border-b border-primary/10">
                <h3 className="text-[15px] font-black text-text-dark">مقررات تاريخية خارج الخطة الحالية</h3>
                <p className="mt-1 text-[12.5px] text-text-light leading-7">
                  هذه المقررات محفوظة في سجلك الأكاديمي، لكنها لا تُحتسب تلقائياً ضمن متطلبات الخطة الحالية ما لم تعتمد لها معادلة أكاديمية.
                </p>
              </div>
              <ul className="divide-y divide-primary/8">
                {outside.map((course, index) => (
                  <li key={courseKey(course, 'outside', index)} className="px-5 py-3.5">
                    <div className="flex items-start justify-between gap-3 flex-wrap">
                      <div className="min-w-0">
                        <p className="text-[14px] font-bold text-text-dark break-words">{course.course_name || '—'}</p>
                        <p className="text-[11.5px] text-text-light font-mono mt-0.5">{course.course_code || '—'}</p>
                      </div>
                      <span className="text-[13px] font-black text-text-dark tabular-nums">
                        {asNumber(course.credit_hours)} ساعة
                      </span>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-text-gray">
                      {statusLabel(REGISTRATION_STATUS_LABELS, course.registration_status) ? (
                        <span>حالة التسجيل: {statusLabel(REGISTRATION_STATUS_LABELS, course.registration_status)}</span>
                      ) : null}
                      {statusLabel(RESULT_STATUS_LABELS, course.result_status) ? (
                        <span>النتيجة: {statusLabel(RESULT_STATUS_LABELS, course.result_status)}</span>
                      ) : null}
                      {course.final_mark !== null && course.final_mark !== undefined && course.final_mark !== '' ? (
                        <span className="tabular-nums">العلامة النهائية: {course.final_mark}</span>
                      ) : null}
                    </div>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
