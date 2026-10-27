import { useEffect, useRef, useState } from 'react'
import { useBlocker } from 'react-router-dom'
import { Field, inputClass, Notice, primaryButton, secondaryButton } from '../../vice-presidency/components/GovernanceUi'
import { authorities, disciplineError, disciplineRead, disciplineWrite, isUncertainDisciplineWrite } from '../lib/discipline'

export default function DisciplinaryEditor({ mode, studentId, caseId, appealId, onSaved, onClose, onDenied, closeRef }) {
  const [form, setForm] = useState({ student_id: studentId || '', violation_type_id: '', penalty_type_id: '', violation_description: '', violation_date: '',
    decision_number: '', decision_date: '', decided_by_authority: '', penalty_start_date: '', penalty_end_date: '', trigger_course_offering_id: '',
    is_in_absentia: false, appeal_reason: '', status_code: '', notes: '' })
  const [options, setOptions] = useState(null)
  const [search, setSearch] = useState('')
  const [offeringSearch, setOfferingSearch] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [uncertain, setUncertain] = useState(false)
  const [reviewed, setReviewed] = useState(false)
  const [reading, setReading] = useState(false)
  const pending = useRef(false)
  const attempt = useRef(null)
  const dirty = Object.entries(form).some(([key, value]) => key !== 'student_id' && value !== '' && value !== false)
  const blocker = useBlocker(({ currentLocation, nextLocation }) => (dirty || pending.current || busy || uncertain)
    && (currentLocation.pathname !== nextLocation.pathname || currentLocation.search !== nextLocation.search))
  useEffect(() => {
    if (!dirty && !busy && !uncertain) return
    const leave = e => { e.preventDefault(); e.returnValue = '' }
    window.addEventListener('beforeunload', leave)
    return () => window.removeEventListener('beforeunload', leave)
  }, [dirty, busy, uncertain])
  useEffect(() => {
    if (mode !== 'create') return
    let active = true
    const abort = new AbortController()
    const query = new URLSearchParams({ ...(form.student_id ? { student_id: form.student_id } : {}), ...(search.trim() ? { q: search.trim() } : {}), ...(offeringSearch.trim() ? { offering_q: offeringSearch.trim() } : {}) })
    const timer = setTimeout(() => {
      disciplineRead('disciplinary-cases/options?' + query, abort.signal).then(data => {
        if (![data.students, data.offerings, data.penalty_types, data.violation_types].every(Array.isArray) || typeof data.capabilities?.create !== 'boolean') throw new Error('تعذرت قراءة خيارات النموذج كاملة. أغلق النموذج وأعد فتحه.')
        if (active) setOptions({ data, key: String(form.student_id) + ':' + search + ':' + offeringSearch })
      })
        .catch(e => { if (active && e.name !== 'AbortError') { setError(disciplineError(e)); if ([401, 403].includes(e.status)) onDenied() } })
    }, 200)
    return () => { active = false; clearTimeout(timer); abort.abort() }
  }, [mode, form.student_id, search, offeringSearch, onDenied])
  const currentOptions = options?.key === String(form.student_id) + ':' + search + ':' + offeringSearch ? options.data : null
  const locked = busy || uncertain
  const set = (key, value) => { if (!locked) setForm(current => ({ ...current, [key]: value })) }
  const penalty = options?.data.penalty_types.find(p => String(p.penalty_type_id) === String(form.penalty_type_id))
  const close = () => {
    if (pending.current || busy || reading || (uncertain && !reviewed)) return
    if (!dirty || window.confirm('تجاهل المدخلات غير المحفوظة وإغلاق النموذج؟')) onClose()
  }
  useEffect(() => { closeRef.current = close; return () => { closeRef.current = null } })
  async function submit(e) {
    e.preventDefault()
    if (pending.current || uncertain) return
    const body = mode === 'create' ? Object.fromEntries(Object.entries(form).filter(([key]) => !['appeal_reason', 'status_code', 'notes'].includes(key)).map(([key, value]) => [key, value === '' ? null : value]))
      : mode === 'appeal' ? { case_id: caseId, appeal_reason: form.appeal_reason.trim() } : { status_code: form.status_code, notes: form.notes.trim() || null }
    if (mode === 'create' && (!currentOptions?.capabilities.create || (penalty?.cascades_to_subsequent_courses && !body.trigger_course_offering_id))) return
    attempt.current = Object.freeze({ path: mode === 'create' ? 'disciplinary-cases' : mode === 'appeal' ? 'disciplinary-case-appeals' : `disciplinary-case-appeals/${appealId}/decide`, body })
    pending.current = true; setBusy(true); setError('')
    try {
      await disciplineWrite(attempt.current.path, attempt.current.body, mode === 'create' ? { studentId: form.student_id } : { caseId, appeal: true, ...(mode === 'decision' ? { appealId } : {}) })
      onSaved()
    } catch (e) {
      if ([401, 403].includes(e.status)) { onDenied(); return }
      const unknown = isUncertainDisciplineWrite(e)
      setUncertain(unknown); setError(unknown ? 'تعذر التأكد من نتيجة الحفظ. لم يُرسل طلب آخر. احتفظ بالنموذج وراجع السجل الحالي قبل إغلاقه.' : disciplineError(e))
    } finally { pending.current = false; setBusy(false) }
  }
  async function review() {
    setReading(true)
    try {
      await disciplineRead(caseId ? `disciplinary-cases/${caseId}` : `disciplinary-cases?student_id=${form.student_id}`)
      setReviewed(true); setError('أصبحت قراءة السجل متاحة. أغلق النموذج وافحص آخر القرارات والطعون قبل إعداد أي طلب جديد؛ القراءة وحدها لا تؤكد حفظ طلبك.')
    } catch (e) { setError(disciplineError(e)); if ([401, 403].includes(e.status)) onDenied() }
    finally { setReading(false) }
  }
  const select = (key, label, rows, id, name, required = true) => <Field label={label}><select required={required} className={inputClass} value={form[key]} disabled={locked || !currentOptions} onChange={e => set(key, e.target.value)}><option value="">اختر</option>{rows?.map(row => <option key={row[id]} value={row[id]}>{row[name]}</option>)}</select></Field>
  const input = (key, label, type = 'text', required = true) => <Field label={label}><input type={type} required={required} maxLength={type === 'text' ? 80 : undefined} className={inputClass} value={form[key]} disabled={locked} onChange={e => set(key, e.target.value)} /></Field>
  return <form onSubmit={submit} className="space-y-4 p-5" dir="rtl">
    <Notice>هذه العملية تسجّل قرارًا أو طعنًا عبر الخدمة الحالية، ولا تمنح صاحب الحساب صفة الجهة التي أصدرت القرار.</Notice>
    {error && <Notice tone={uncertain ? 'warning' : 'error'}>{error}</Notice>}
    {mode === 'create' ? <>
      {!studentId && <Field label="ابحث عن الطالب بالاسم أو الرقم"><input className={inputClass} value={search} disabled={locked} onChange={e => { setSearch(e.target.value); setForm(f => ({ ...f, student_id: '', trigger_course_offering_id: '' })) }} /></Field>}
      {studentId ? <p className="font-bold text-[13px]">{currentOptions?.student?.full_name || 'تحميل الطالب…'} — {currentOptions?.student?.student_number}</p>
        : <Field label="الطالب"><select required className={inputClass} value={form.student_id} disabled={locked || !currentOptions} onChange={e => { set('student_id', e.target.value); set('trigger_course_offering_id', '') }}><option value="">اختر الطالب من نتائج البحث</option>{currentOptions?.students.map(s => <option key={s.student_id} value={s.student_id}>{s.full_name} — {s.student_number}</option>)}</select></Field>}
      <div className="grid grid-cols-2 gap-4 max-[560px]:grid-cols-1">
        {select('violation_type_id', 'نوع المخالفة', currentOptions?.violation_types, 'violation_type_id', 'violation_name_ar')}
        {input('violation_date', 'تاريخ المخالفة', 'date')}
        {select('penalty_type_id', 'العقوبة الصادرة', currentOptions?.penalty_types, 'penalty_type_id', 'penalty_name_ar')}
        <Field label="الجهة التي أصدرت القرار"><select required value={form.decided_by_authority} disabled={locked} className={inputClass} onChange={e => set('decided_by_authority', e.target.value)}><option value="">اختر الجهة المثبتة في القرار</option>{Object.entries(authorities).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Field>
        {input('decision_number', 'رقم القرار (إن وجد)', 'text', false)}{input('decision_date', 'تاريخ القرار', 'date')}
        {input('penalty_start_date', 'بداية العقوبة (إن وجدت)', 'date', false)}{input('penalty_end_date', 'نهاية العقوبة (إن وجدت)', 'date', false)}
      </div>
      <Field label="وصف المخالفة"><textarea required maxLength={10000} className={inputClass} value={form.violation_description} disabled={locked} onChange={e => set('violation_description', e.target.value)} /></Field>
      {penalty?.cascades_to_subsequent_courses && <>
        <Notice tone="warning">هذه العقوبة تؤثر في علامات المقرر والمقررات اللاحقة وفق تواريخ الامتحانات وقواعد الخدمة القائمة. تحقق من القرار والمقرر قبل الحفظ.</Notice>
        <Field label="ابحث عن مقرر الواقعة بالاسم أو الرمز"><input maxLength={120} className={inputClass} value={offeringSearch} disabled={locked} onChange={e => { setOfferingSearch(e.target.value); set('trigger_course_offering_id', '') }} /></Field>
        <Field label="مقرر الواقعة من تسجيلات الطالب (أحدث 100 نتيجة مطابقة)"><select required className={inputClass} value={form.trigger_course_offering_id} disabled={locked || !currentOptions} onChange={e => set('trigger_course_offering_id', e.target.value)}><option value="">اختر المقرر والطرح الصحيحين</option>{currentOptions?.offerings.map(o => <option key={o.course_offering_id} value={o.course_offering_id}>{o.course_name} — {o.course_code} — {o.year_name} / {o.semester_name} — طرح {o.course_offering_id}</option>)}</select></Field>
      </>}
      <label className="flex items-center gap-2 text-[13px]"><input type="checkbox" checked={form.is_in_absentia} disabled={locked} onChange={e => set('is_in_absentia', e.target.checked)} />صدر القرار غيابيًا</label>
      {currentOptions && !currentOptions.capabilities.create && <Notice tone="warning">تسجيل القرارات غير متاح ضمن صلاحياتك أو نطاق هذا الطالب.</Notice>}
    </> : mode === 'appeal' ? <Field label="سبب الطعن"><textarea required maxLength={10000} className={inputClass} value={form.appeal_reason} disabled={locked} onChange={e => set('appeal_reason', e.target.value)} /></Field> : <>
      <Field label="نتيجة الطعن الصادرة"><select required className={inputClass} value={form.status_code} disabled={locked} onChange={e => set('status_code', e.target.value)}><option value="">اختر النتيجة المثبتة</option><option value="accepted">قبول الطعن</option><option value="rejected">رفض الطعن</option></select></Field>
      <Field label="ملاحظات القرار"><textarea maxLength={10000} className={inputClass} value={form.notes} disabled={locked} onChange={e => set('notes', e.target.value)} /></Field>
      <Notice tone="warning">قبول الطعن يستدعي مسار إلغاء العقوبة واستعادة أثرها المحفوظ وفق حراس الخدمة الحالية.</Notice>
    </>}
    <div className="flex flex-wrap gap-3">
      <button className={primaryButton} disabled={locked || reading || (mode === 'create' && (!currentOptions?.capabilities.create || !form.student_id))}>حفظ التسجيل</button>
      {uncertain && <button type="button" className={secondaryButton} disabled={reading} onClick={review}>التحقق بقراءة السجل</button>}
      <button type="button" className={secondaryButton} disabled={busy || reading || (uncertain && !reviewed)} onClick={close}>إغلاق النموذج</button>
    </div>
    {blocker.state === 'blocked' && <Notice tone="warning">هناك نموذج لم ينتهِ. <button type="button" onClick={() => blocker.reset()}>البقاء</button>{!locked && <button type="button" onClick={() => blocker.proceed()}>تجاهل المدخلات والمتابعة</button>}</Notice>}
  </form>
}
