import { useState } from 'react'
import {
  FaCheckCircle,
  FaChevronDown,
  FaClipboardCheck,
  FaHourglassHalf,
  FaUniversity,
} from 'react-icons/fa'
import CourseRequirementBadges from './CourseRequirementBadges'
import {
  academicRequirementPresentation,
  asRequirementNumber,
  REQUIREMENT_SCOPE_LABELS,
  requirementGroupPresentation,
} from './requirementProgress'

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

export function AcademicRequirementProgressSkeleton() {
  return (
    <div className="space-y-5" dir="rtl" aria-busy="true" aria-live="polite">
      <div className="grid grid-cols-4 max-[980px]:grid-cols-2 max-[520px]:grid-cols-1 gap-3">
        {[0, 1, 2, 3].map(item => <SkeletonBlock key={item} className="h-24" />)}
      </div>
      <SkeletonBlock className="h-28" />
      <SkeletonBlock className="h-20" />
      <div className="grid grid-cols-2 max-[800px]:grid-cols-1 gap-3">
        {[0, 1, 2, 3].map(item => <SkeletonBlock key={item} className="h-44" />)}
      </div>
    </div>
  )
}

function ProgressBar({ value, label }) {
  const pct = value == null ? 0 : value
  return (
    <div
      className="h-2.5 rounded-full bg-primary/10 overflow-hidden"
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={pct}
      aria-label={label}
    >
      <div
        className="h-full rounded-full bg-primary transition-all duration-500"
        style={{ width: `${pct}%` }}
        aria-hidden="true"
      />
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
  const Icon = completed ? FaCheckCircle : FaHourglassHalf
  return (
    <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border text-[11px] font-bold ${
      completed
        ? 'bg-green-100 text-green-800 border-green-200'
        : 'bg-primary/10 text-primary-dark border-primary/20'
    }`}
    >
      <Icon aria-hidden="true" />
      {completed ? 'مكتمل' : 'غير مكتمل'}
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
            <li key={courseKey(course, prefix, index)} className="flex items-start justify-between gap-3 rounded-[10px] bg-[#fafaf8] px-3 py-2">
              <div className="min-w-0">
                <p className="text-[13px] font-bold text-text-dark break-words">{course.course_name || '—'}</p>
                <p className="text-[11px] text-text-light font-mono mt-0.5">{course.course_code || '—'}</p>
                <div className="mt-1"><CourseRequirementBadges classification={course.requirement_classification} compact /></div>
              </div>
              <span className="shrink-0 text-[12px] font-black text-text-dark tabular-nums">
                {asRequirementNumber(course.credit_hours)} س
              </span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

function GroupCard({ group }) {
  const view = requirementGroupPresentation(group)
  const isMandatory = view.type === 'mandatory'
  const isElective = view.type === 'elective'

  return (
    <article className="bg-white border border-primary/12 rounded-[16px] p-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
      <div className="flex items-start justify-between gap-3 flex-wrap mb-3">
        <div className="min-w-0">
          {group.group_name ? <h4 className="text-[14.5px] font-black text-text-dark break-words">{group.group_name}</h4> : null}
          <div className="flex items-center gap-1.5 mt-1.5 flex-wrap">
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-primary/8 text-primary-dark">{view.typeLabel}</span>
            {group.group_code ? <span className="text-[11px] font-mono text-text-light">{group.group_code}</span> : null}
          </div>
        </div>
        <StatusChip completed={view.completed} />
      </div>

      {view.progress == null ? (
        <p className="text-[12px] text-text-light mb-3">لا تتوفر ساعات مطلوبة لعرض نسبة التقدم.</p>
      ) : (
        <div className="mb-3">
          <div className="flex items-baseline justify-between gap-2 mb-1.5">
            <p className="text-[12px] text-text-gray">
              {isMandatory && view.courseCount > 0
                ? <span className="tabular-nums">{view.passedCount} من {view.courseCount} مقرر</span>
                : <span className="tabular-nums">{view.earned} من {view.required} ساعة</span>}
            </p>
            <p className="text-[12px] font-black text-primary tabular-nums">{view.progress}%</p>
          </div>
          <ProgressBar value={view.progress} label={isMandatory ? 'تقدم استيفاء المقررات الإجبارية' : 'تقدم الساعات الاختيارية المطلوبة'} />
        </div>
      )}

      {isMandatory ? (
        <div className="grid grid-cols-2 gap-2 mb-3">
          <p className="text-[12px] text-text-light">المقررات المجتازة<span className="block font-black text-text-dark tabular-nums text-[15px] mt-0.5">{view.passedCount} من {view.courseCount}</span></p>
          <p className="text-[12px] text-text-light">الساعات المجتازة<span className="block font-black text-text-dark tabular-nums text-[15px] mt-0.5">{view.earned} من {view.required}</span></p>
        </div>
      ) : null}

      {isElective ? (
        <div className="rounded-[12px] bg-primary/[0.04] border border-primary/10 px-3 py-2.5 mb-3">
          <p className="text-[12.5px] text-text-dark leading-7">المتاح ضمن المجموعة: <span className="font-black tabular-nums">{view.pool}</span> ساعات</p>
          <p className="text-[12.5px] text-text-dark leading-7">المطلوب اجتيازه: <span className="font-black tabular-nums">{view.required}</span> ساعات</p>
          {view.counted != null && view.counted !== view.earned ? (
            <p className="text-[12px] text-text-gray leading-6 mt-1">الساعات المحتسبة للتخرج من هذه المجموعة: <span className="font-black text-text-dark tabular-nums">{view.counted}</span></p>
          ) : null}
        </div>
      ) : null}

      <dl className="grid grid-cols-2 max-[420px]:grid-cols-1 gap-x-3 gap-y-2 text-[12.5px]">
        {[
          ['المطلوب', view.required],
          ['مجتاز ومعتمد', view.earned],
          ['مسجل حالياً', view.registered],
          ['قيد طلب التسجيل', view.pending],
        ].map(([label, item]) => (
          <div key={label} className="flex justify-between gap-2"><dt className="text-text-light">{label}</dt><dd className="font-black text-text-dark tabular-nums">{item}</dd></div>
        ))}
        <div className="flex justify-between gap-2 col-span-full"><dt className="text-text-light">المتبقي</dt><dd className="font-black text-primary tabular-nums">{view.remaining}</dd></div>
      </dl>

      {isElective && view.completed ? <p className="mt-3 text-[12.5px] font-bold text-green-800 leading-6">تم استيفاء الساعات الاختيارية المطلوبة</p> : null}
      <CourseList title="المقررات المجتازة" courses={group.passed_courses} prefix="passed" />
      <CourseList title="المقررات المسجلة" courses={group.registered_courses} prefix="registered" />
      <CourseList title="المقررات قيد الطلب" courses={group.pending_courses} prefix="pending" />
    </article>
  )
}

function statusLabel(map, status) {
  if (!status) return null
  return map[String(status).toLowerCase()] || status
}

function OutsideCurriculum({ courses, selfView }) {
  if (!courses.length) return null
  return (
    <section className="bg-white border border-primary/12 rounded-[18px] overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
      <div className="px-5 py-4 border-b border-primary/10">
        <h3 className="text-[15px] font-black text-text-dark">مقررات تاريخية خارج الخطة الحالية</h3>
        <p className="mt-1 text-[12.5px] text-text-light leading-7">{selfView ? 'هذه المقررات محفوظة في سجلك الأكاديمي، لكنها لا تُحتسب تلقائياً ضمن متطلبات الخطة الحالية ما لم تعتمد لها معادلة أكاديمية.' : 'هذه المقررات محفوظة في السجل الأكاديمي، لكنها لا تُحتسب تلقائياً ضمن متطلبات الخطة الحالية ما لم تعتمد لها معادلة أكاديمية.'}</p>
      </div>
      <ul className="divide-y divide-primary/8">
        {courses.map((course, index) => (
          <li key={courseKey(course, 'outside', index)} className="px-5 py-3.5">
            <div className="flex items-start justify-between gap-3 flex-wrap">
              <div className="min-w-0">
                <p className="text-[14px] font-bold text-text-dark break-words">{course.course_name || '—'}</p>
                <p className="text-[11.5px] text-text-light font-mono mt-0.5">{course.course_code || '—'}</p>
                <div className="mt-1.5"><CourseRequirementBadges classification={course.requirement_classification} compact /></div>
              </div>
              <span className="text-[13px] font-black text-text-dark tabular-nums">{asRequirementNumber(course.credit_hours)} ساعة</span>
            </div>
            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-text-gray">
              {statusLabel(REGISTRATION_STATUS_LABELS, course.registration_status) ? <span>حالة التسجيل: {statusLabel(REGISTRATION_STATUS_LABELS, course.registration_status)}</span> : null}
              {statusLabel(RESULT_STATUS_LABELS, course.result_status) ? <span>النتيجة: {statusLabel(RESULT_STATUS_LABELS, course.result_status)}</span> : null}
              {course.final_mark !== null && course.final_mark !== undefined && course.final_mark !== '' ? <span className="tabular-nums">العلامة النهائية: {course.final_mark}</span> : null}
            </div>
          </li>
        ))}
      </ul>
    </section>
  )
}

export default function AcademicRequirementProgress({ progress, eligibility, selfView = false }) {
  const view = academicRequirementPresentation(progress, eligibility)

  if (view.noProgram) {
    return (
      <section className="bg-white border border-primary/12 rounded-[18px] px-6 py-16 text-center shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
        <FaUniversity className="mx-auto text-[40px] text-primary/25 mb-4" aria-hidden="true" />
        <h3 className="text-[17px] font-black text-text-dark mb-2">{selfView ? 'لا توجد خطة أكاديمية مرتبطة بسجلك حالياً.' : 'لا توجد خطة أكاديمية مرتبطة بسجل الطالب حالياً.'}</h3>
        <p className="text-[13.5px] text-text-light leading-7 max-w-[520px] mx-auto">{selfView ? 'بعد ربط برنامج دراسي بسجلك ستظهر هنا متطلبات الخطة وتقدمك نحو استيفائها.' : 'بعد ربط برنامج دراسي بالسجل ستظهر متطلبات الخطة والتقدم نحو استيفائها.'}</p>
      </section>
    )
  }

  return (
    <div className="space-y-5">
      <section className="grid grid-cols-4 max-[980px]:grid-cols-2 max-[520px]:grid-cols-1 gap-3">
        <MetricCard label="إجمالي الساعات المطلوبة" value={view.totalRequired} />
        <MetricCard label="الساعات المجتازة فعلياً" value={view.actualEarned} hint="ساعات معتمدة داخل الخطة الحالية، وقد تزيد عن الساعات المحتسبة للتخرج." />
        <MetricCard label="الساعات المحتسبة للتخرج" value={view.countedHours} hint="تُحتسب دون فائض المقررات الاختيارية." />
        <MetricCard label="الساعات المتبقية للتخرج" value={view.remainingHours} />
      </section>

      <section className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)]">
        <div className="flex items-center justify-between gap-3 flex-wrap mb-3">
          <div className="flex items-center gap-2"><FaClipboardCheck className="text-primary" aria-hidden="true" /><h3 className="text-[15px] font-black text-text-dark">التقدم نحو التخرج</h3></div>
          {view.overallProgress == null ? <p className="text-[13px] text-text-light">لا تتوفر ساعات مطلوبة لعرض نسبة التقدم.</p> : <p className="text-[13.5px] font-black text-text-dark tabular-nums">{view.countedHours} من {view.totalRequired} ساعة <span className="text-primary mr-2">{view.overallProgress}%</span></p>}
        </div>
        {view.overallProgress == null ? null : <ProgressBar value={view.overallProgress} label="نسبة الساعات المحتسبة للتخرج من إجمالي المطلوب" />}
      </section>

      <section className={`${view.eligible ? 'bg-green-50 border-green-200' : 'bg-primary/[0.06] border-primary/15'} border rounded-[16px] px-5 py-4`}>
        <p className={`text-[14.5px] font-black ${view.eligible ? 'text-green-900' : 'text-text-dark'}`}>{view.eligible ? 'تم استيفاء المتطلبات الأكاديمية للتخرج' : 'متطلبات التخرج الأكاديمية غير مكتملة بعد'}</p>
        <p className={`mt-1 text-[13px] leading-7 ${view.eligible ? 'text-green-800' : 'text-text-gray'}`}>
          {view.eligible
            ? (selfView ? 'استوفيت متطلبات الخطة الأكاديمية. اعتماد حالة التخرج يبقى إجراءً إدارياً منفصلاً.' : 'استوفى الطالب متطلبات الخطة الأكاديمية. اعتماد حالة التخرج يبقى إجراءً إدارياً منفصلاً.')
            : <>يتبقى <span className="font-black text-text-dark tabular-nums">{view.remainingHours}</span> ساعة محتسبة لاستيفاء الخطة.</>}
        </p>
        {!view.eligible && view.readableBlockers.length ? <ul className="mt-2 space-y-1">{view.readableBlockers.map(label => <li key={label} className="text-[12.5px] text-text-dark">{label}</li>)}</ul> : null}
      </section>

      {view.groupedScopes.map(([scope, groups]) => (
        <section key={scope}>
          <h3 className="text-[15px] font-black text-text-dark mb-3">{REQUIREMENT_SCOPE_LABELS[scope] || scope}</h3>
          <div className="grid grid-cols-2 max-[800px]:grid-cols-1 gap-3">
            {groups.map(group => <GroupCard key={group.requirement_group_id || `${scope}-${group.group_code}-${group.requirement_type}`} group={group} />)}
          </div>
        </section>
      ))}

      <OutsideCurriculum courses={view.outside} selfView={selfView} />
    </div>
  )
}
