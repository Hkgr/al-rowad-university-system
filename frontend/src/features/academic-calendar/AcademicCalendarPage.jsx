import { useCallback, useEffect, useMemo, useState } from 'react'
import { FaCalendarAlt, FaChevronLeft, FaChevronRight, FaClock, FaHistory, FaList, FaPlus, FaTimes } from 'react-icons/fa'
import { getIdentity, PERMISSIONS, ROLES } from '../auth/auth'
import { calendarApi } from './academicCalendarApi'
import { eventColor, eventsForDay, eventVersion, fromUtcInput, monthBounds, monthCells, toUtcInput } from './calendarUtils'

const monthLabel = new Intl.DateTimeFormat('ar-SY', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const dateLabel = new Intl.DateTimeFormat('ar-SY', { dateStyle: 'medium', timeZone: 'UTC' })
const timeLabel = new Intl.DateTimeFormat('ar-SY', { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })
const weekdays = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة']

function Modal({ title, children, onClose, wide = false }) {
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className={`max-h-[92vh] w-full overflow-y-auto rounded-2xl bg-white shadow-2xl ${wide ? 'max-w-3xl' : 'max-w-xl'}`}>
      <header className="sticky top-0 z-10 flex items-center justify-between border-b bg-white px-5 py-4"><h2 className="text-lg font-black">{title}</h2><button type="button" onClick={onClose} className="rounded-lg p-2 hover:bg-slate-100" aria-label="إغلاق"><FaTimes /></button></header>
      <div className="p-5">{children}</div>
    </div>
  </div>
}

function Badge({ status, cancelled }) {
  if (cancelled) return <span className="rounded-full bg-red-100 px-2.5 py-1 text-xs font-bold text-red-700">ملغى</span>
  const labels = { draft: 'مسودة', published: 'منشور', superseded: 'مستبدل', active: 'نشطة', closed: 'مغلقة' }
  const styles = { draft: 'bg-amber-100 text-amber-800', published: 'bg-emerald-100 text-emerald-800', superseded: 'bg-slate-100 text-slate-600', active: 'bg-emerald-100 text-emerald-800', closed: 'bg-slate-200 text-slate-700' }
  return <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${styles[status] || 'bg-blue-100 text-blue-800'}`}>{labels[status] || status}</span>
}

function EventForm({ mode, event, catalog, defaultYearId, busy, onClose, onSubmit }) {
  const version = event ? eventVersion(event) : null
  const replacement = mode === 'replacement' || Boolean(version?.replaces_version_id)
  const showReason = replacement || mode === 'edit'
  const reasonRequired = replacement || Boolean(version?.starts_at && new Date(version.starts_at) <= new Date())
  const [form, setForm] = useState({
    academic_year_id: event?.academic_year?.academic_year_id || defaultYearId || catalog.academic_years.find(y => y.is_current)?.academic_year_id || '',
    semester_id: event?.semester?.semester_id || '',
    academic_calendar_event_type_id: event?.event_type?.academic_calendar_event_type_id || catalog.event_types.find(t => t.is_active)?.academic_calendar_event_type_id || '',
    title: version?.title || '', public_notes: version?.public_notes || '', starts_at: toUtcInput(version?.starts_at), ends_at: toUtcInput(version?.ends_at),
    is_enforcement: Boolean(version?.is_enforcement), change_reason: '',
  })
  const set = (key, value) => setForm(current => ({ ...current, [key]: value }))
  const submit = () => {
    const payload = { ...form, semester_id: form.semester_id || null, starts_at: fromUtcInput(form.starts_at), ends_at: fromUtcInput(form.ends_at) }
    if (replacement && mode !== 'replacement') {
      delete payload.academic_year_id
      delete payload.semester_id
      delete payload.academic_calendar_event_type_id
    }
    onSubmit(payload)
  }
  return <Modal title={mode === 'create' ? 'إنشاء مسودة حدث' : replacement ? 'مسودة بديلة للحدث المنشور' : 'تعديل المسودة'} onClose={onClose} wide>
    {replacement && <p className="mb-4 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">يبقى الإصدار المنشور ظاهراً حتى نشر البديل. تغيير السنة أو الفصل أو النوع يتطلب إلغاء الحدث وإنشاء حدث جديد.</p>}
    <form onSubmit={e => { e.preventDefault(); submit() }} className="grid gap-4 md:grid-cols-2">
      <label className="text-sm font-bold">السنة<select disabled={replacement} required value={form.academic_year_id} onChange={e => set('academic_year_id', e.target.value)} className="mt-1 w-full rounded-xl border p-3 disabled:bg-slate-100">{catalog.academic_years.map(y => <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>)}</select></label>
      <label className="text-sm font-bold">الفصل<select disabled={replacement} value={form.semester_id} onChange={e => set('semester_id', e.target.value)} className="mt-1 w-full rounded-xl border p-3 disabled:bg-slate-100"><option value="">على مستوى السنة</option>{catalog.semesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}</select></label>
      <label className="text-sm font-bold md:col-span-2">نوع الحدث<select disabled={replacement} required value={form.academic_calendar_event_type_id} onChange={e => set('academic_calendar_event_type_id', e.target.value)} className="mt-1 w-full rounded-xl border p-3 disabled:bg-slate-100">{catalog.event_types.filter(t => t.is_active).map(t => <option key={t.academic_calendar_event_type_id} value={t.academic_calendar_event_type_id}>{t.name_ar}</option>)}</select></label>
      <label className="text-sm font-bold md:col-span-2">العنوان<input required maxLength={255} value={form.title} onChange={e => set('title', e.target.value)} className="mt-1 w-full rounded-xl border p-3" /></label>
      <label className="text-sm font-bold">البداية (UTC)<input required type="datetime-local" value={form.starts_at} onChange={e => set('starts_at', e.target.value)} className="mt-1 w-full rounded-xl border p-3" /></label>
      <label className="text-sm font-bold">النهاية (UTC)<input required type="datetime-local" value={form.ends_at} onChange={e => set('ends_at', e.target.value)} className="mt-1 w-full rounded-xl border p-3" /></label>
      <label className="text-sm font-bold md:col-span-2">ملاحظات عامة<textarea rows={3} value={form.public_notes} onChange={e => set('public_notes', e.target.value)} className="mt-1 w-full rounded-xl border p-3" /></label>
      {showReason && <label className="text-sm font-bold md:col-span-2">سبب التغيير {reasonRequired ? '(مطلوب)' : '(اختياري)'}<textarea required={reasonRequired} rows={2} value={form.change_reason} onChange={e => set('change_reason', e.target.value)} className="mt-1 w-full rounded-xl border p-3" /></label>}
      <label className="flex items-center gap-2 text-sm font-bold md:col-span-2"><input type="checkbox" checked={form.is_enforcement} onChange={e => set('is_enforcement', e.target.checked)} /> نافذة تنفيذية (وصف فقط في المرحلة الثانية)</label>
      <div className="flex justify-end gap-2 md:col-span-2"><button type="button" onClick={onClose} className="rounded-xl border px-5 py-2.5">إلغاء</button><button disabled={busy} className="rounded-xl bg-primary px-5 py-2.5 font-bold text-white disabled:opacity-50">حفظ المسودة</button></div>
    </form>
  </Modal>
}

function ReasonModal({ action, busy, onClose, onSubmit }) {
  const [reason, setReason] = useState('')
  return <Modal title={action.title} onClose={onClose}><form onSubmit={e => { e.preventDefault(); onSubmit(reason) }}><label className="text-sm font-bold">{action.type === 'cancel' ? 'سبب إلغاء الحدث' : 'سبب تغيير دورة حياة السنة'}<textarea autoFocus required rows={4} value={reason} onChange={e => setReason(e.target.value)} className="mt-2 w-full rounded-xl border p-3" /></label><div className="mt-4 flex justify-end gap-2"><button type="button" onClick={onClose} className="rounded-xl border px-4 py-2">رجوع</button><button disabled={busy} className="rounded-xl bg-primary px-4 py-2 font-bold text-white">تأكيد</button></div></form></Modal>
}

export default function AcademicCalendarPage() {
  const identity = getIdentity()
  const canManage = identity?.roles?.includes(ROLES.vicePresidentScientific) && identity?.permissions?.includes(PERMISSIONS.academicCalendarManage)
  const [catalog, setCatalog] = useState({ academic_years: [], semesters: [], event_types: [] })
  const [events, setEvents] = useState([])
  const [cursor, setCursor] = useState(() => new Date(Date.UTC(new Date().getUTCFullYear(), new Date().getUTCMonth(), 1)))
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
  const selectedYear = catalog.academic_years.find(y => String(y.academic_year_id) === String(filters.academic_year_id))
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

  return <section dir="rtl" className="space-y-5 pb-10">
    <header className="overflow-hidden rounded-2xl bg-gradient-to-l from-emerald-800 via-primary to-lime-600 p-6 text-white shadow-lg"><div className="flex flex-wrap items-center justify-between gap-4"><div><div className="mb-2 flex items-center gap-2 text-emerald-100"><FaCalendarAlt /> التقويم الجامعي الرسمي</div><h1 className="text-2xl font-black">التقويم الأكاديمي</h1><p className="mt-2 text-sm text-emerald-50">المواعيد والفترات المنشورة بتوقيت الجامعة الموحد UTC</p></div>{canManage && <button disabled={selectedYear?.calendar_lifecycle_status === 'closed'} title={selectedYear?.calendar_lifecycle_status === 'closed' ? 'أعد فتح السنة للتصحيح أولاً' : ''} onClick={() => setFormMode('create')} className="flex items-center gap-2 rounded-xl bg-white px-4 py-3 font-bold text-emerald-800 shadow disabled:cursor-not-allowed disabled:opacity-50"><FaPlus /> حدث جديد</button>}</div></header>
    {error && <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800">{error}</div>}
    {warnings.length > 0 && <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900">{warnings.map((warning, index) => <p key={index}>{warning.message}</p>)}</div>}

    <div className="rounded-2xl border bg-white p-4 shadow-sm"><div className="grid gap-3 md:grid-cols-3">
      <select value={filters.academic_year_id} onChange={e => setFilters(f => ({ ...f, academic_year_id: e.target.value }))} className="rounded-xl border p-3">{catalog.academic_years.map(y => <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name} {y.is_current ? '— الحالية' : ''}</option>)}</select>
      <select value={filters.semester_id} onChange={e => setFilters(f => ({ ...f, semester_id: e.target.value }))} className="rounded-xl border p-3"><option value="">كل الفصول</option>{catalog.semesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}</select>
      <select value={filters.academic_calendar_event_type_id} onChange={e => setFilters(f => ({ ...f, academic_calendar_event_type_id: e.target.value }))} className="rounded-xl border p-3"><option value="">كل أنواع الأحداث</option>{catalog.event_types.map(t => <option key={t.academic_calendar_event_type_id} value={t.academic_calendar_event_type_id}>{t.name_ar}</option>)}</select>
    </div>{canManage && selectedYear && <div className="mt-4 flex flex-wrap items-center gap-2 border-t pt-4"><strong>حالة السنة:</strong><Badge status={selectedYear.calendar_lifecycle_status} />{selectedYear.calendar_lifecycle_status === 'draft' && <><button onClick={() => setReasonAction({ type: 'activate', title: 'تفعيل السنة الأكاديمية' })} className="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-bold text-white">تفعيل</button><button onClick={() => setReasonAction({ type: 'close', title: 'إغلاق السنة المسودة' })} className="rounded-lg border px-3 py-2 text-sm font-bold">إغلاق</button></>}{selectedYear.calendar_lifecycle_status === 'closed' && <button onClick={() => setReasonAction({ type: 'reopen', title: 'إعادة فتح السنة للتصحيح' })} className="rounded-lg border px-3 py-2 text-sm font-bold">إعادة فتح للتصحيح</button>}</div>}</div>

    <div className="rounded-2xl border bg-white shadow-sm"><div className="flex flex-wrap items-center justify-between gap-3 border-b p-4"><div className="flex items-center gap-2"><button onClick={() => setCursor(c => new Date(Date.UTC(c.getUTCFullYear(), c.getUTCMonth() + 1, 1)))} className="rounded-lg border p-2"><FaChevronRight /></button><h2 className="min-w-40 text-center text-lg font-black">{monthLabel.format(cursor)}</h2><button onClick={() => setCursor(c => new Date(Date.UTC(c.getUTCFullYear(), c.getUTCMonth() - 1, 1)))} className="rounded-lg border p-2"><FaChevronLeft /></button></div><div className="flex rounded-xl bg-slate-100 p-1"><button onClick={() => setView('month')} className={`flex items-center gap-2 rounded-lg px-3 py-2 text-sm ${view === 'month' ? 'bg-white font-bold shadow' : ''}`}><FaCalendarAlt /> شهر</button><button onClick={() => setView('agenda')} className={`flex items-center gap-2 rounded-lg px-3 py-2 text-sm ${view === 'agenda' ? 'bg-white font-bold shadow' : ''}`}><FaList /> قائمة</button></div></div>
      {view === 'month' ? <div className="overflow-x-auto"><div className="min-w-[760px]"><div className="grid grid-cols-7 border-b bg-slate-50">{weekdays.map(day => <div key={day} className="p-3 text-center text-sm font-bold text-slate-600">{day}</div>)}</div><div className="grid grid-cols-7">{cells.map(cell => { const items = eventsForDay(displayEvents, cell.key); const today = cell.key === new Date().toISOString().slice(0, 10); return <div key={cell.key} className={`min-h-28 border-b border-l p-2 ${cell.inMonth ? 'bg-white' : 'bg-slate-50 text-slate-400'}`}><span className={`inline-flex h-7 w-7 items-center justify-center rounded-full text-sm ${today ? 'bg-primary font-bold text-white' : ''}`}>{cell.date.getUTCDate()}</span><div className="mt-1 space-y-1">{items.slice(0, 3).map(event => { const version = eventVersion(event); return <button key={event._calendarKey || event.academic_calendar_event_id} onClick={() => setSelected(event)} className={`block w-full truncate rounded-md border px-2 py-1 text-right text-xs font-bold ${event.cancelled ? 'opacity-55 line-through' : ''} ${eventColor(event.event_type.event_type_code, event.event_type.event_type_kind)}`}>{version.title}</button> })}{items.length > 3 && <button onClick={() => setView('agenda')} className="text-xs font-bold text-primary">+ {items.length - 3} أحداث</button>}</div></div>})}</div></div></div>
      : <div className="divide-y">{[...displayEvents].sort((a, b) => eventVersion(a).starts_at.localeCompare(eventVersion(b).starts_at)).map(event => { const version = eventVersion(event); return <button key={event._calendarKey || event.academic_calendar_event_id} onClick={() => setSelected(event)} className="flex w-full items-start gap-4 p-4 text-right hover:bg-slate-50"><span className={`mt-1 h-3 w-3 rounded-full ${eventColor(event.event_type.event_type_code).split(' ')[0]}`} /><div className="flex-1"><div className="flex flex-wrap items-center gap-2"><strong>{version.title}</strong><Badge status={version.publication_status} cancelled={event.cancelled} /></div><p className="mt-1 text-sm text-slate-500">{dateLabel.format(new Date(version.starts_at))}، {timeLabel.format(new Date(version.starts_at))} — {event.semester?.semester_name || 'على مستوى السنة'}</p></div></button>})}{displayEvents.length === 0 && <p className="p-12 text-center text-slate-500">لا توجد أحداث ضمن المرشحات الحالية.</p>}</div>}
    </div>
    <div className="flex flex-wrap gap-2">{catalog.event_types.map(type => <span key={type.academic_calendar_event_type_id} className={`rounded-full border px-3 py-1.5 text-xs font-bold ${eventColor(type.event_type_code, type.event_type_kind)}`}>{type.name_ar}</span>)}</div>

    {selected && !formMode && <Modal title={eventVersion(selected).title} onClose={() => setSelected(null)} wide><div className="space-y-4"><div className="flex flex-wrap gap-2"><Badge status={eventVersion(selected).publication_status} cancelled={selected.cancelled} /><span className={`rounded-full border px-2.5 py-1 text-xs font-bold ${eventColor(selected.event_type.event_type_code, selected.event_type.event_type_kind)}`}>{selected.event_type.name_ar}</span></div><p className="flex items-center gap-2 text-slate-600"><FaClock /> {dateLabel.format(new Date(eventVersion(selected).starts_at))} {timeLabel.format(new Date(eventVersion(selected).starts_at))} — {dateLabel.format(new Date(eventVersion(selected).ends_at))} {timeLabel.format(new Date(eventVersion(selected).ends_at))}</p><p><strong>السنة:</strong> {selected.academic_year.year_name} · <strong>الفصل:</strong> {selected.semester?.semester_name || 'على مستوى السنة'}</p>{eventVersion(selected).public_notes && <p className="whitespace-pre-wrap rounded-xl bg-slate-50 p-4 leading-7">{eventVersion(selected).public_notes}</p>}{selected.cancelled && <p className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><strong>سبب الإلغاء:</strong> {selected.cancellation_reason}</p>}{selected._hasPendingDraft && eventVersion(selected).publication_status === 'published' && <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-800">توجد مسودة بديلة معلقة؛ افتحها لتعديلها أو نشرها أو حذفها قبل الإلغاء.</p>}{canManage && <div className="flex flex-wrap gap-2 border-t pt-4">{eventVersion(selected).publication_status === 'draft' && <><button onClick={() => setFormMode('edit')} className="rounded-lg border px-3 py-2 font-bold">تعديل المسودة</button><button onClick={() => mutate(() => calendarApi.publish(selected.academic_calendar_event_id, eventVersion(selected).academic_calendar_event_version_id))} className="rounded-lg bg-emerald-600 px-3 py-2 font-bold text-white">نشر</button><button onClick={() => window.confirm('حذف هذه المسودة؟') && mutate(() => calendarApi.deleteDraft(selected.academic_calendar_event_id, eventVersion(selected).academic_calendar_event_version_id))} className="rounded-lg border border-red-300 px-3 py-2 font-bold text-red-700">حذف المسودة</button></>}{eventVersion(selected).publication_status === 'published' && !selected.cancelled && !selected._hasPendingDraft && <><button onClick={() => setFormMode('replacement')} className="rounded-lg border px-3 py-2 font-bold">إنشاء تعديل بديل</button><button onClick={() => setReasonAction({ type: 'cancel', title: 'إلغاء الحدث المنشور' })} className="rounded-lg border border-red-300 px-3 py-2 font-bold text-red-700">إلغاء الحدث</button></>}<button onClick={async () => { const response = await calendarApi.history(selected.academic_calendar_event_id); setSelected(response.data) }} className="flex items-center gap-2 rounded-lg border px-3 py-2 font-bold"><FaHistory /> السجل</button></div>}{canManage && selected.versions?.length > 1 && <div className="border-t pt-4"><h3 className="mb-3 font-black">سجل الإصدارات</h3><div className="space-y-2">{selected.versions.map(version => <div key={version.academic_calendar_event_version_id} className="rounded-xl border p-3"><div className="flex items-center justify-between"><strong>الإصدار {version.version_number}: {version.title}</strong><Badge status={version.publication_status} /></div><p className="mt-2 text-sm text-slate-600">{version.change_reason}</p></div>)}</div></div>}</div></Modal>}
    {formMode && <EventForm mode={formMode} event={selected} catalog={catalog} defaultYearId={filters.academic_year_id} busy={busy} onClose={() => setFormMode(null)} onSubmit={submitForm} />}
    {reasonAction && <ReasonModal action={reasonAction} busy={busy} onClose={() => setReasonAction(null)} onSubmit={reason => reasonAction.type === 'cancel' ? mutate(() => calendarApi.cancel(selected.academic_calendar_event_id, reason)) : mutate(() => calendarApi.yearAction(selectedYear.academic_year_id, reasonAction.type, reason))} />}
  </section>
}
