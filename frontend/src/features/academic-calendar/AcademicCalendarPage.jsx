import { useCallback, useEffect, useMemo, useState } from 'react'
import { FaCalendarAlt, FaChevronLeft, FaChevronRight, FaClock, FaHistory, FaList, FaPlus, FaTimes } from 'react-icons/fa'
import FilterBar from '../../components/table/FilterBar'
import { getIdentity, PERMISSIONS, ROLES } from '../auth/auth'
import { calendarApi } from './academicCalendarApi'
import { eventColor, eventsForDay, eventVersion, fromUniversityInput, monthBounds, monthCells, statusBadgeKind, toUniversityInput, UNIVERSITY_TIME_ZONE, universityDateKey, withOptionalChangeReason } from './calendarUtils'

const monthLabel = new Intl.DateTimeFormat('ar-SY', { month: 'long', year: 'numeric', timeZone: UNIVERSITY_TIME_ZONE })
const dateLabel = new Intl.DateTimeFormat('ar-SY', { dateStyle: 'medium', timeZone: UNIVERSITY_TIME_ZONE })
const timeLabel = new Intl.DateTimeFormat('ar-SY', { hour: '2-digit', minute: '2-digit', timeZone: UNIVERSITY_TIME_ZONE })
const weekdays = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة']
const fieldClass = 'mt-1 w-full rounded-[10px] border border-primary/20 bg-white px-3 py-2.5 text-[13px] text-text-dark outline-none focus:border-primary disabled:bg-[#f5f5f2] disabled:text-text-light'
const secondaryButtonClass = 'rounded-[10px] border border-primary/20 bg-white px-4 py-2 text-[13px] font-bold text-text-dark hover:bg-primary/[0.04] disabled:opacity-50'
const primaryButtonClass = 'rounded-[10px] bg-primary px-4 py-2 text-[13px] font-bold text-white hover:bg-primary-dark disabled:opacity-50'

function Modal({ title, children, onClose, wide = false }) {
  return <div className="fixed inset-0 z-50 flex items-end justify-center bg-black/35 p-0 sm:items-center sm:p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className={`max-h-[92vh] w-full overflow-y-auto rounded-t-[18px] bg-white shadow-2xl sm:rounded-[18px] ${wide ? 'sm:max-w-3xl' : 'sm:max-w-xl'}`}>
      <header className="sticky top-0 z-10 flex items-center justify-between border-b border-primary/10 bg-white px-5 py-4"><h2 className="text-[16px] font-black text-text-dark">{title}</h2><button type="button" onClick={onClose} className="rounded-[9px] p-2 text-text-light hover:bg-primary/[0.06] hover:text-text-dark" aria-label="إغلاق"><FaTimes /></button></header>
      <div className="p-4 sm:p-5">{children}</div>
    </div>
  </div>
}

function Badge({ status, cancelled, canManage = true }) {
  const kind = statusBadgeKind(status, cancelled, canManage)
  if (!kind) return null
  if (kind === 'cancelled') return <span className="rounded-full bg-red-100 px-2.5 py-1 text-[11.5px] font-bold text-red-700">ملغى</span>
  const labels = { draft: 'مسودة', published: 'منشور', superseded: 'مستبدل', active: 'نشطة', closed: 'مغلقة' }
  const styles = { draft: 'bg-amber-100 text-amber-800', published: 'bg-emerald-100 text-emerald-800', superseded: 'bg-slate-100 text-slate-600', active: 'bg-emerald-100 text-emerald-800', closed: 'bg-slate-200 text-slate-700' }
  return <span className={`rounded-full px-2.5 py-1 text-[11.5px] font-bold ${styles[kind] || 'bg-blue-100 text-blue-800'}`}>{labels[kind] || kind}</span>
}

function EventForm({ mode, event, catalog, defaultYearId, busy, onClose, onSubmit }) {
  const version = event ? eventVersion(event) : null
  const replacement = mode === 'replacement' || Boolean(version?.replaces_version_id)
  const showReason = replacement || mode === 'edit'
  const reasonRequired = replacement || Boolean(version?.starts_at && new Date(version.starts_at) <= new Date())
  const initialEventTypeId = event?.event_type?.academic_calendar_event_type_id || catalog.event_types.find(t => t.is_active)?.academic_calendar_event_type_id || ''
  const [form, setForm] = useState({
    academic_year_id: event?.academic_year?.academic_year_id || defaultYearId || catalog.academic_years.find(y => y.is_current)?.academic_year_id || '',
    semester_id: event?.semester?.semester_id || '',
    academic_calendar_event_type_id: initialEventTypeId,
    title: version?.title || '', public_notes: version?.public_notes || '', starts_at: toUniversityInput(version?.starts_at), ends_at: toUniversityInput(version?.ends_at),
    student_registration_ends_at: toUniversityInput(version?.student_registration_ends_at || version?.ends_at),
    advisor_approval_ends_at: toUniversityInput(version?.advisor_approval_ends_at || version?.ends_at),
    is_enforcement: Boolean(version?.is_enforcement), change_reason: '',
  })
  const selectedEventType = event?.event_type || catalog.event_types.find(type => String(type.academic_calendar_event_type_id) === String(form.academic_calendar_event_type_id))
  const isCourseRegistration = selectedEventType?.event_type_code === 'course_registration'
  const set = (key, value) => setForm(current => ({ ...current, [key]: value }))
  const submit = () => {
    const payload = withOptionalChangeReason({
      ...form,
      semester_id: form.semester_id || null,
      starts_at: fromUniversityInput(form.starts_at),
      ends_at: fromUniversityInput(isCourseRegistration ? form.advisor_approval_ends_at : form.ends_at),
      is_enforcement: isCourseRegistration ? true : form.is_enforcement,
    }, reasonRequired)
    if (isCourseRegistration) {
      payload.student_registration_ends_at = fromUniversityInput(form.student_registration_ends_at)
      payload.advisor_approval_ends_at = fromUniversityInput(form.advisor_approval_ends_at)
    } else {
      delete payload.student_registration_ends_at
      delete payload.advisor_approval_ends_at
    }
    if (replacement && mode !== 'replacement') {
      delete payload.academic_year_id
      delete payload.semester_id
      delete payload.academic_calendar_event_type_id
    }
    onSubmit(payload)
  }
  return <Modal title={mode === 'create' ? 'إنشاء مسودة حدث' : replacement ? 'مسودة بديلة للحدث المنشور' : 'تعديل المسودة'} onClose={onClose} wide>
    {replacement && <p className="mb-4 rounded-[10px] border border-blue-200 bg-blue-50 p-3 text-[12.5px] text-blue-800">يبقى الإصدار المنشور ظاهراً حتى نشر البديل. تغيير السنة أو الفصل أو النوع يتطلب إلغاء الحدث وإنشاء حدث جديد.</p>}
    <form onSubmit={e => { e.preventDefault(); submit() }} className="grid gap-4 md:grid-cols-2">
      <label className="text-[13px] font-bold text-text-dark">السنة<select disabled={replacement} required value={form.academic_year_id} onChange={e => set('academic_year_id', e.target.value)} className={fieldClass}>{catalog.academic_years.map(y => <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>)}</select></label>
      <label className="text-[13px] font-bold text-text-dark">الفصل<select disabled={replacement} required={isCourseRegistration} value={form.semester_id} onChange={e => set('semester_id', e.target.value)} className={fieldClass}><option value="">على مستوى السنة</option>{catalog.semesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}</select></label>
      <label className="text-[13px] font-bold text-text-dark md:col-span-2">نوع الحدث<select disabled={replacement} required value={form.academic_calendar_event_type_id} onChange={e => set('academic_calendar_event_type_id', e.target.value)} className={fieldClass}>{catalog.event_types.filter(t => t.is_active).map(t => <option key={t.academic_calendar_event_type_id} value={t.academic_calendar_event_type_id}>{t.name_ar}</option>)}</select></label>
      <label className="text-[13px] font-bold text-text-dark md:col-span-2">العنوان<input required maxLength={255} value={form.title} onChange={e => set('title', e.target.value)} className={fieldClass} /></label>
      <label className="text-[13px] font-bold text-text-dark">{isCourseRegistration ? 'بداية تسجيل الطلاب' : 'البداية'} <span className="font-normal text-text-light">(بتوقيت الجامعة)</span><input required type="datetime-local" value={form.starts_at} onChange={e => set('starts_at', e.target.value)} className={fieldClass} /></label>
      {isCourseRegistration ? <>
        <label className="text-[13px] font-bold text-text-dark">نهاية تسجيل الطلاب <span className="font-normal text-text-light">(بتوقيت الجامعة)</span><input required type="datetime-local" min={form.starts_at} value={form.student_registration_ends_at} onChange={e => set('student_registration_ends_at', e.target.value)} className={fieldClass} /></label>
        <label className="text-[13px] font-bold text-text-dark md:col-span-2">نهاية اعتماد المرشد الأكاديمي <span className="font-normal text-text-light">(بتوقيت الجامعة)</span><input required type="datetime-local" min={form.student_registration_ends_at} value={form.advisor_approval_ends_at} onChange={e => set('advisor_approval_ends_at', e.target.value)} className={fieldClass} /></label>
        <p className="rounded-[10px] border border-emerald-200 bg-emerald-50 p-3 text-[12.5px] leading-6 text-emerald-900 md:col-span-2">الطلاب يمكنهم الإضافة والتعديل حتى نهاية تسجيل الطلاب. طلبات الطلاب المرسلة تبقى متاحة للمرشد حتى نهاية مهلة الاعتماد.</p>
      </> : <label className="text-[13px] font-bold text-text-dark">النهاية <span className="font-normal text-text-light">(بتوقيت الجامعة)</span><input required type="datetime-local" value={form.ends_at} onChange={e => set('ends_at', e.target.value)} className={fieldClass} /></label>}
      <label className="text-[13px] font-bold text-text-dark md:col-span-2">ملاحظات عامة<textarea rows={3} value={form.public_notes} onChange={e => set('public_notes', e.target.value)} className={fieldClass} /></label>
      {showReason && <label className="text-[13px] font-bold text-text-dark md:col-span-2">سبب التغيير {reasonRequired ? '(مطلوب)' : '(اختياري)'}<textarea required={reasonRequired} rows={2} value={form.change_reason} onChange={e => set('change_reason', e.target.value)} className={fieldClass} /></label>}
      <label className="flex items-center gap-2 text-[13px] font-bold text-text-dark md:col-span-2"><input type="checkbox" disabled={isCourseRegistration} checked={isCourseRegistration || form.is_enforcement} onChange={e => set('is_enforcement', e.target.checked)} /> نافذة تنفيذية</label>
      <div className="flex justify-end gap-2 border-t border-primary/10 pt-4 md:col-span-2"><button type="button" onClick={onClose} className={secondaryButtonClass}>إلغاء</button><button disabled={busy} className={primaryButtonClass}>حفظ المسودة</button></div>
    </form>
  </Modal>
}

function ReasonModal({ action, busy, onClose, onSubmit }) {
  const [reason, setReason] = useState('')
  return <Modal title={action.title} onClose={onClose}><form onSubmit={e => { e.preventDefault(); onSubmit(reason) }}><label className="text-[13px] font-bold text-text-dark">{action.type === 'cancel' ? 'سبب إلغاء الحدث' : 'سبب تغيير دورة حياة السنة'}<textarea autoFocus required rows={4} value={reason} onChange={e => setReason(e.target.value)} className={fieldClass} /></label><div className="mt-4 flex justify-end gap-2 border-t border-primary/10 pt-4"><button type="button" onClick={onClose} className={secondaryButtonClass}>رجوع</button><button disabled={busy} className={primaryButtonClass}>تأكيد</button></div></form></Modal>
}

export default function AcademicCalendarPage() {
  const identity = getIdentity()
  const canManage = identity?.roles?.includes(ROLES.vicePresidentScientific) && identity?.permissions?.includes(PERMISSIONS.academicCalendarManage)
  const [catalog, setCatalog] = useState({ academic_years: [], semesters: [], event_types: [] })
  const [events, setEvents] = useState([])
  const [cursor, setCursor] = useState(() => {
    const [year, month] = universityDateKey().split('-').map(Number)
    return new Date(Date.UTC(year, month - 1, 1))
  })
  const [filters, setFilters] = useState({ academic_year_id: '', semester_id: '', academic_calendar_event_type_id: '' })
  const [view, setView] = useState('month')
  const [selected, setSelected] = useState(null)
  const [formMode, setFormMode] = useState(null)
  const [reasonAction, setReasonAction] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [warnings, setWarnings] = useState([])

  const load = useCallback(async () => {
    setError('')
    try {
      const catalogResponse = await calendarApi.catalog(canManage)
      const nextCatalog = catalogResponse.data
      setCatalog(nextCatalog)
      const yearId = filters.academic_year_id || nextCatalog.academic_years.find(y => y.is_current)?.academic_year_id || ''
      if (!filters.academic_year_id && yearId) setFilters(current => ({ ...current, academic_year_id: String(yearId) }))
      const response = await calendarApi.events(canManage, { ...filters, academic_year_id: yearId, ...(canManage ? {} : monthBounds(cursor)) })
      setEvents(response.data || [])
    } catch (err) { setError(err.message) }
  }, [canManage, cursor, filters])

  useEffect(() => { load() }, [load])
  const cells = useMemo(() => monthCells(cursor), [cursor])
  const todayKey = universityDateKey()
  const selectedYear = catalog.academic_years.find(y => String(y.academic_year_id) === String(filters.academic_year_id))
  const filterOptions = useMemo(() => [
    { key: 'year', value: filters.academic_year_id, onChange: value => setFilters(current => ({ ...current, academic_year_id: value })), placeholder: 'العام الدراسي', minWidth: 150, options: catalog.academic_years.map(year => ({ value: String(year.academic_year_id), label: `${year.year_name}${year.is_current ? ' — الحالية' : ''}` })) },
    { key: 'semester', value: filters.semester_id, onChange: value => setFilters(current => ({ ...current, semester_id: value })), placeholder: 'كل الفصول', minWidth: 140, options: catalog.semesters.map(semester => ({ value: String(semester.semester_id), label: semester.semester_name })) },
    { key: 'event-type', value: filters.academic_calendar_event_type_id, onChange: value => setFilters(current => ({ ...current, academic_calendar_event_type_id: value })), placeholder: 'كل أنواع الأحداث', minWidth: 170, options: catalog.event_types.map(type => ({ value: String(type.academic_calendar_event_type_id), label: type.name_ar })) },
  ], [catalog, filters])
  const displayEvents = useMemo(() => canManage ? events.flatMap(event => {
    const visibleVersions = event.versions.filter(version => ['draft', 'published'].includes(version.publication_status))
    const hasPendingDraft = visibleVersions.some(version => version.publication_status === 'draft')
    return visibleVersions.map(version => ({ ...event, versions: [version], _calendarKey: `${event.academic_calendar_event_id}-${version.academic_calendar_event_version_id}`, _hasPendingDraft: hasPendingDraft }))
  }) : events, [canManage, events])

  const mutate = async operation => {
    setBusy(true); setError(''); setWarnings([])
    try {
      const response = await operation()
      setWarnings(response?.data?.warnings || [])
      setFormMode(null); setReasonAction(null); setSelected(null)
      await load()
    } catch (err) { setError(err.message) } finally { setBusy(false) }
  }
  const submitForm = payload => {
    if (formMode === 'create') return mutate(() => calendarApi.create(payload))
    const version = eventVersion(selected)
    return formMode === 'replacement'
      ? mutate(() => calendarApi.replacement(selected.academic_calendar_event_id, payload))
      : mutate(() => calendarApi.editDraft(selected.academic_calendar_event_id, version.academic_calendar_event_version_id, payload))
  }

  return <section dir="rtl" className="pb-10">
    <div className="flex items-start sm:items-center justify-between mb-5 gap-4 flex-wrap">
      <div className="min-w-0">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">التقويم الأكاديمي</h2>
        <p className="text-[12.5px] text-text-light">المواعيد والفترات الأكاديمية المنشورة بتوقيت الجامعة</p>
      </div>
      {canManage && <button disabled={selectedYear?.calendar_lifecycle_status === 'closed'} title={selectedYear?.calendar_lifecycle_status === 'closed' ? 'أعد فتح السنة للتصحيح أولاً' : ''} onClick={() => setFormMode('create')} className="inline-flex items-center gap-2 bg-primary hover:bg-primary-dark rounded-[10px] px-4 py-2.5 text-[13px] font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"><FaPlus /> حدث جديد</button>}
    </div>

    {error && <div className="mb-4 rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700">{error}</div>}
    {warnings.length > 0 && <div className="mb-4 rounded-[12px] border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">{warnings.map((warning, index) => <p key={index}>{warning.message}</p>)}</div>}

    <div className="mb-5 rounded-[14px] border border-primary/12 bg-white px-4 pt-4 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <FilterBar
        filters={filterOptions}
        hasActiveFilters={Boolean(filters.semester_id || filters.academic_calendar_event_type_id)}
        onClear={() => setFilters(current => ({ ...current, semester_id: '', academic_calendar_event_type_id: '' }))}
        disabled={busy}
      />
      {canManage && selectedYear && <div className="mb-4 flex flex-wrap items-center gap-2 border-t border-primary/10 pt-4 text-[13px] text-text-dark">
        <strong>حالة السنة:</strong><Badge status={selectedYear.calendar_lifecycle_status} />
        {selectedYear.calendar_lifecycle_status === 'draft' && <><button onClick={() => setReasonAction({ type: 'activate', title: 'تفعيل السنة الأكاديمية' })} className={primaryButtonClass}>تفعيل</button><button onClick={() => setReasonAction({ type: 'close', title: 'إغلاق السنة المسودة' })} className={secondaryButtonClass}>إغلاق</button></>}
        {selectedYear.calendar_lifecycle_status === 'closed' && <button onClick={() => setReasonAction({ type: 'reopen', title: 'إعادة فتح السنة للتصحيح' })} className={secondaryButtonClass}>إعادة فتح للتصحيح</button>}
      </div>}
    </div>

    <div className="overflow-hidden rounded-[16px] border border-primary/12 bg-white shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-primary/10 px-4 py-3">
        <div className="flex items-center gap-2">
          <button onClick={() => setCursor(current => new Date(Date.UTC(current.getUTCFullYear(), current.getUTCMonth() + 1, 1)))} className="rounded-[9px] border border-primary/20 p-2 text-primary hover:bg-primary/[0.06]" aria-label="الشهر التالي"><FaChevronRight /></button>
          <h3 className="min-w-40 text-center text-[16px] font-black text-text-dark">{monthLabel.format(cursor)}</h3>
          <button onClick={() => setCursor(current => new Date(Date.UTC(current.getUTCFullYear(), current.getUTCMonth() - 1, 1)))} className="rounded-[9px] border border-primary/20 p-2 text-primary hover:bg-primary/[0.06]" aria-label="الشهر السابق"><FaChevronLeft /></button>
        </div>
        <div className="flex rounded-[10px] bg-primary/[0.06] p-1">
          <button onClick={() => setView('month')} className={`flex items-center gap-2 rounded-[8px] px-3 py-2 text-[12.5px] text-text-dark ${view === 'month' ? 'bg-white font-bold shadow-sm' : ''}`}><FaCalendarAlt /> شهر</button>
          <button onClick={() => setView('agenda')} className={`flex items-center gap-2 rounded-[8px] px-3 py-2 text-[12.5px] text-text-dark ${view === 'agenda' ? 'bg-white font-bold shadow-sm' : ''}`}><FaList /> قائمة</button>
        </div>
      </div>

      {view === 'month' ? <div className="overflow-x-auto"><div className="min-w-[760px]">
        <div className="grid grid-cols-7 border-b border-primary/10 bg-[#fafaf8]">{weekdays.map(day => <div key={day} className="p-3 text-center text-[12px] font-bold text-text-light">{day}</div>)}</div>
        <div className="grid grid-cols-7">{cells.map(cell => {
          const items = eventsForDay(displayEvents, cell.key)
          const today = cell.key === todayKey
          return <div key={cell.key} className={`min-h-28 border-b border-l border-primary/8 p-2 ${cell.inMonth ? 'bg-white' : 'bg-[#fafaf8] text-text-light'}`}>
            <span className={`inline-flex h-7 w-7 items-center justify-center rounded-full text-[12.5px] ${today ? 'bg-primary font-bold text-white' : 'text-text-dark'}`}>{cell.date.getUTCDate()}</span>
            <div className="mt-1 space-y-1">{items.slice(0, 3).map(event => { const version = eventVersion(event); return <button key={event._calendarKey || event.academic_calendar_event_id} onClick={() => setSelected(event)} className={`block w-full truncate rounded-[7px] border px-2 py-1 text-right text-[11.5px] font-bold ${event.cancelled ? 'opacity-55 line-through' : ''} ${eventColor(event.event_type.event_type_code, event.event_type.event_type_kind)}`}>{version.title}</button> })}{items.length > 3 && <button onClick={() => setView('agenda')} className="text-[11.5px] font-bold text-primary">+ {items.length - 3} أحداث</button>}</div>
          </div>
        })}</div>
      </div></div> : <div className="divide-y divide-primary/8">{[...displayEvents].sort((a, b) => eventVersion(a).starts_at.localeCompare(eventVersion(b).starts_at)).map(event => {
        const version = eventVersion(event)
        return <button key={event._calendarKey || event.academic_calendar_event_id} onClick={() => setSelected(event)} className="flex w-full items-start gap-4 p-4 text-right hover:bg-primary/[0.03]">
          <span className={`mt-1 h-3 w-3 rounded-full ${eventColor(event.event_type.event_type_code).split(' ')[0]}`} />
          <div className="flex-1"><div className="flex flex-wrap items-center gap-2"><strong className="text-[13px] text-text-dark">{version.title}</strong><Badge status={version.publication_status} cancelled={event.cancelled} canManage={canManage} /></div><p className="mt-1 text-[12px] text-text-light">{dateLabel.format(new Date(version.starts_at))}، {timeLabel.format(new Date(version.starts_at))} — {event.semester?.semester_name || 'على مستوى السنة'} · بتوقيت الجامعة</p></div>
        </button>
      })}{displayEvents.length === 0 && <p className="p-12 text-center text-[13px] text-text-light">لا توجد أحداث ضمن المرشحات الحالية.</p>}</div>}
    </div>

    <div className="mt-4 flex flex-wrap gap-2">{catalog.event_types.map(type => <span key={type.academic_calendar_event_type_id} className={`rounded-full border px-3 py-1.5 text-[11.5px] font-bold ${eventColor(type.event_type_code, type.event_type_kind)}`}>{type.name_ar}</span>)}</div>

    {selected && !formMode && <Modal title={eventVersion(selected).title} onClose={() => setSelected(null)} wide><div className="space-y-4 text-[13px] text-text-dark">
      <div className="flex flex-wrap gap-2"><Badge status={eventVersion(selected).publication_status} cancelled={selected.cancelled} canManage={canManage} /><span className={`rounded-full border px-2.5 py-1 text-[11.5px] font-bold ${eventColor(selected.event_type.event_type_code, selected.event_type.event_type_kind)}`}>{selected.event_type.name_ar}</span></div>
      <p className="flex items-center gap-2 text-text-light"><FaClock /> {dateLabel.format(new Date(eventVersion(selected).starts_at))} {timeLabel.format(new Date(eventVersion(selected).starts_at))} — {dateLabel.format(new Date(eventVersion(selected).ends_at))} {timeLabel.format(new Date(eventVersion(selected).ends_at))} · بتوقيت الجامعة</p>
      {selected.event_type.event_type_code === 'course_registration' && <div className="grid gap-2 rounded-[10px] border border-emerald-200 bg-emerald-50 p-4 text-emerald-950 sm:grid-cols-2"><p><strong>نهاية تسجيل الطلاب:</strong> {dateLabel.format(new Date(eventVersion(selected).student_registration_ends_at || eventVersion(selected).ends_at))} {timeLabel.format(new Date(eventVersion(selected).student_registration_ends_at || eventVersion(selected).ends_at))}</p><p><strong>نهاية اعتماد المرشد:</strong> {dateLabel.format(new Date(eventVersion(selected).advisor_approval_ends_at || eventVersion(selected).ends_at))} {timeLabel.format(new Date(eventVersion(selected).advisor_approval_ends_at || eventVersion(selected).ends_at))}</p></div>}
      <p><strong>السنة:</strong> {selected.academic_year.year_name} · <strong>الفصل:</strong> {selected.semester?.semester_name || 'على مستوى السنة'}</p>
      {eventVersion(selected).public_notes && <p className="whitespace-pre-wrap rounded-[10px] bg-[#fafaf8] p-4 leading-7">{eventVersion(selected).public_notes}</p>}
      {selected.cancelled && <p className="rounded-[10px] border border-red-200 bg-red-50 p-4 text-red-800">{canManage ? <><strong>سبب الإلغاء:</strong> {selected.cancellation_reason}</> : 'هذا الحدث ملغى'}</p>}
      {selected._hasPendingDraft && eventVersion(selected).publication_status === 'published' && <p className="rounded-[10px] border border-amber-200 bg-amber-50 p-3 text-amber-800">توجد مسودة بديلة معلقة؛ افتحها لتعديلها أو نشرها أو حذفها قبل الإلغاء.</p>}
      {canManage && <div className="flex flex-wrap gap-2 border-t border-primary/10 pt-4">{eventVersion(selected).publication_status === 'draft' && <><button onClick={() => setFormMode('edit')} className={secondaryButtonClass}>تعديل المسودة</button><button onClick={() => mutate(() => calendarApi.publish(selected.academic_calendar_event_id, eventVersion(selected).academic_calendar_event_version_id))} className={primaryButtonClass}>نشر</button><button onClick={() => window.confirm('حذف هذه المسودة؟') && mutate(() => calendarApi.deleteDraft(selected.academic_calendar_event_id, eventVersion(selected).academic_calendar_event_version_id))} className="rounded-[10px] border border-red-300 px-4 py-2 text-[13px] font-bold text-red-700">حذف المسودة</button></>}{eventVersion(selected).publication_status === 'published' && !selected.cancelled && !selected._hasPendingDraft && <><button onClick={() => setFormMode('replacement')} className={secondaryButtonClass}>إنشاء تعديل بديل</button><button onClick={() => setReasonAction({ type: 'cancel', title: 'إلغاء الحدث المنشور' })} className="rounded-[10px] border border-red-300 px-4 py-2 text-[13px] font-bold text-red-700">إلغاء الحدث</button></>}<button onClick={async () => { const response = await calendarApi.history(selected.academic_calendar_event_id); setSelected(response.data) }} className={`${secondaryButtonClass} inline-flex items-center gap-2`}><FaHistory /> السجل</button></div>}
      {canManage && selected.versions?.length > 1 && <div className="border-t border-primary/10 pt-4"><h3 className="mb-3 text-[14px] font-black">سجل الإصدارات</h3><div className="space-y-2">{selected.versions.map(version => <div key={version.academic_calendar_event_version_id} className="rounded-[10px] border border-primary/12 p-3"><div className="flex items-center justify-between gap-3"><strong>الإصدار {version.version_number}: {version.title}</strong><Badge status={version.publication_status} /></div><p className="mt-2 text-[12px] text-text-light">{version.change_reason}</p></div>)}</div></div>}
    </div></Modal>}
    {formMode && <EventForm mode={formMode} event={selected} catalog={catalog} defaultYearId={filters.academic_year_id} busy={busy} onClose={() => setFormMode(null)} onSubmit={submitForm} />}
    {reasonAction && <ReasonModal action={reasonAction} busy={busy} onClose={() => setReasonAction(null)} onSubmit={reason => reasonAction.type === 'cancel' ? mutate(() => calendarApi.cancel(selected.academic_calendar_event_id, reason)) : mutate(() => calendarApi.yearAction(selectedYear.academic_year_id, reasonAction.type, reason))} />}
  </section>
}
