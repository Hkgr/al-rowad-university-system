import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaEye, FaInfoCircle, FaSave, FaSearch, FaSpinner, FaUserPlus, FaUserTie } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { clearIdentity } from '../../auth/auth'
import { changeAffiliation, createFaculty, fetchFaculty, fetchFacultyMember, lookupEmployee, updateFaculty } from '../lib/administrativeApi'
import { canUseAdministrative } from '../utils/administrativeAccess'
import { affiliationSourceLabel, affiliationText, facultyQuery, governanceError, newTeacherPayload } from '../utils/administrativeGovernance'
import { Dialog, Field, Notice, dangerButton, headerButton, inputClass, primaryButton, secondaryButton } from '../components/GovernanceUi'

const PER_PAGE = 15
const EMPTY_FORM = { mode: 'new', employee_number: '', first_name: '', last_name: '', father_name: '', phone_number: '', email: '', hire_date: '', academic_rank: '', specialization: '', office_location: '', college_id: '', start_date: '', employee_id: '' }

function CreateTeacherModal({ colleges, onClose, onCreated }) {
  const [form, setForm] = useState(EMPTY_FORM)
  const [lookup, setLookup] = useState(null)
  const [lookupState, setLookupState] = useState('idle')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const set = (key, value) => setForm(current => ({ ...current, [key]: value }))

  async function findEmployee() {
    if (!form.employee_number.trim()) return
    setLookupState('loading'); setLookup(null); setError('')
    try {
      const json = await lookupEmployee(form.employee_number.trim())
      setLookup(json.data)
      setLookupState(json.data ? 'found' : 'missing')
      if (json.data) set('employee_id', String(json.data.employee_id))
    } catch (err) {
      setLookupState('idle')
      setError(governanceError(err).message)
    }
  }

  async function submit(event) {
    event.preventDefault()
    setSaving(true); setError(''); setFieldErrors({})
    try {
      const json = await createFaculty(newTeacherPayload(form))
      onCreated(json.data, json.message)
    } catch (err) {
      const mapped = governanceError(err)
      setError(mapped.message); setFieldErrors(mapped.fieldErrors)
    } finally {
      setSaving(false)
    }
  }

  const linkBlocked = form.mode === 'link' && (lookupState !== 'found' || lookup?.faculty_member_id || lookup?.employee_status !== 'active')

  return (
    <Dialog
      as="form" onSubmit={submit} title="إضافة مدرس" onClose={onClose}
      footer={<>
        <button type="button" onClick={onClose} className={secondaryButton}>إلغاء</button>
        <button type="submit" disabled={saving || linkBlocked} className={primaryButton}>{saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaSave className="text-[11px]" />} حفظ الملف التدريسي</button>
      </>}
    >
      <div className="p-6 space-y-4">
        <div className="flex gap-2 flex-wrap" role="radiogroup" aria-label="طريقة الإضافة">
          {[['new', 'موظف جديد + ملف تدريسي'], ['link', 'ربط موظف قائم']].map(([value, label]) => (
            <label key={value} className={`flex items-center gap-2 px-3 py-2 border rounded-[10px] cursor-pointer text-[12.5px] font-bold ${form.mode === value ? 'border-primary bg-primary/5 text-primary-dark' : 'border-primary/15 text-text-dark'}`}>
              <input type="radio" name="mode" className="accent-[#569933]" checked={form.mode === value} onChange={() => { set('mode', value); setLookup(null); setLookupState('idle') }} />
              {label}
            </label>
          ))}
        </div>
        <Notice>لا يُنشأ تكليف بمادة ولا حساب دخول هنا. التكليف يمر بدورة اعتماد العميد والنائبين.</Notice>

        <div className="grid grid-cols-2 gap-4 max-[560px]:grid-cols-1">
          <Field label="رقم الموظف *" error={fieldErrors.employee_number}>
            <div className="flex gap-2 mt-1">
              <input className={inputClass} dir="ltr" value={form.employee_number} onChange={e => { set('employee_number', e.target.value); setLookupState('idle'); setLookup(null) }} required />
              {form.mode === 'link' && <button type="button" onClick={findEmployee} className="px-3 border border-primary/20 rounded-[9px] text-primary" aria-label="بحث عن الموظف"><FaSearch /></button>}
            </div>
          </Field>
          <Field label={form.mode === 'link' ? 'الكنية (للتحقق) *' : 'الكنية *'} error={fieldErrors.last_name}>
            <input className={`${inputClass} mt-1`} value={form.last_name} onChange={e => set('last_name', e.target.value)} required />
          </Field>

          {form.mode === 'link' && (
            <div className="col-span-2 max-[560px]:col-span-1 text-[12.5px]">
              {lookupState === 'loading' && <p className="text-text-light">جاري البحث…</p>}
              {lookupState === 'missing' && <p className="text-red-600">لا يوجد موظف بهذا الرقم.</p>}
              {lookupState === 'found' && lookup && (
                <div className="border border-primary/15 rounded-[10px] px-3 py-2 space-y-0.5">
                  <p className="font-bold text-text-dark">{lookup.full_name}</p>
                  <p className="text-text-gray">الحالة: {lookup.employee_status === 'active' ? 'على رأس عمله' : (lookup.employee_status || '—')} • {lookup.has_account ? 'له حساب دخول' : 'بلا حساب دخول'}</p>
                  {lookup.faculty_member_id && <p className="text-red-600">لهذا الموظف ملف تدريسي بالفعل (#{lookup.faculty_member_id}).</p>}
                  {lookup.employee_status !== 'active' && <p className="text-red-600">لا يُنشأ ملف تدريسي لموظف غير نشط.</p>}
                  <p className="text-text-light">يُتحقق على الخادم من تطابق رقم الموظف والكنية.</p>
                </div>
              )}
              {lookupState === 'idle' && <p className="text-text-light">أدخل رقم الموظف ثم اضغط زر البحث.</p>}
            </div>
          )}

          {form.mode === 'new' && (
            <>
              <Field label="الاسم *" error={fieldErrors.first_name}><input className={`${inputClass} mt-1`} value={form.first_name} onChange={e => set('first_name', e.target.value)} required /></Field>
              <Field label="اسم الأب" error={fieldErrors.father_name}><input className={`${inputClass} mt-1`} value={form.father_name} onChange={e => set('father_name', e.target.value)} /></Field>
              <Field label="الهاتف" error={fieldErrors.phone_number}><input className={`${inputClass} mt-1`} dir="ltr" value={form.phone_number} onChange={e => set('phone_number', e.target.value)} /></Field>
              <Field label="البريد" error={fieldErrors.email}><input className={`${inputClass} mt-1`} dir="ltr" type="email" value={form.email} onChange={e => set('email', e.target.value)} /></Field>
              <Field label="تاريخ التعيين" error={fieldErrors.hire_date}><input className={`${inputClass} mt-1`} type="date" value={form.hire_date} onChange={e => set('hire_date', e.target.value)} /></Field>
            </>
          )}

          <Field label="الرتبة العلمية" error={fieldErrors.academic_rank}><input className={`${inputClass} mt-1`} value={form.academic_rank} onChange={e => set('academic_rank', e.target.value)} /></Field>
          <Field label="الاختصاص" error={fieldErrors.specialization}><input className={`${inputClass} mt-1`} value={form.specialization} onChange={e => set('specialization', e.target.value)} /></Field>
          <Field label="المكتب" error={fieldErrors.office_location}><input className={`${inputClass} mt-1`} value={form.office_location} onChange={e => set('office_location', e.target.value)} /></Field>
          <Field label="الانتماء إلى كلية (اختياري)" error={fieldErrors.college_id} hint="يظهر المدرس للعميد في قائمة مدرسي كليته. ليس تكليفًا بمادة.">
            <select className={`${inputClass} mt-1`} value={form.college_id} onChange={e => set('college_id', e.target.value)}>
              <option value="">بلا انتماء الآن</option>
              {colleges.map(college => <option key={college.id} value={college.id}>{college.label}</option>)}
            </select>
          </Field>
          {form.college_id && <Field label="تاريخ بدء الانتماء" error={fieldErrors.start_date}><input className={`${inputClass} mt-1`} type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)} /></Field>}
        </div>
        {error && <p className="text-[12px] text-red-600 bg-red-50 border border-red-200 rounded-[8px] px-3 py-2" role="alert">⚠ {error}</p>}
      </div>
    </Dialog>
  )
}

function FacultyDetailPanel({ facultyMemberId, colleges, canManage, onClose, onChanged }) {
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [edit, setEdit] = useState(null)
  const [move, setMove] = useState({ mode: 'assign', from: '', to: '', start_date: '' })

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const json = await fetchFacultyMember(facultyMemberId)
      setDetail(json.data)
      setEdit({ academic_rank: json.data.academic_rank ?? '', specialization: json.data.specialization ?? '', office_location: json.data.office_location ?? '', phone_number: json.data.phone_number ?? '', email: json.data.email ?? '', is_active: Boolean(json.data.is_active) })
    } catch (err) {
      setError(governanceError(err).message)
    } finally {
      setLoading(false)
    }
  }, [facultyMemberId])

  useEffect(() => {
    const timer = setTimeout(load, 0)
    return () => clearTimeout(timer)
  }, [load])

  async function run(action, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return
    setBusy(true); setError(''); setNotice(''); setFieldErrors({})
    try {
      const json = await action()
      setDetail(json.data)
      setNotice(json.message)
      setMove({ mode: 'assign', from: '', to: '', start_date: '' })
      await onChanged()
    } catch (err) {
      const mapped = governanceError(err)
      setError(mapped.message); setFieldErrors(mapped.fieldErrors)
      if (mapped.reload) await load()
    } finally {
      setBusy(false)
    }
  }

  const current = detail?.colleges ?? []
  const source = current.find(college => String(college.college_id) === String(move.from))
  const targetName = colleges.find(college => String(college.id) === String(move.to))?.label
  function submitAffiliation() {
    const payload = { mode: move.mode, start_date: move.start_date || undefined }
    if (move.mode !== 'assign') {
      payload.from_college_id = Number(move.from)
      payload.expected_assignment_id = source?.assignment_id ?? null
    }
    if (move.mode !== 'end') payload.college_id = Number(move.to)
    const text = move.mode === 'assign'
      ? `إسناد انتماء المدرس إلى «${targetName}»؟ سيظهر لعميدها في قائمة المدرسين.`
      : move.mode === 'transfer'
        ? `نقل الانتماء من «${source?.college_name}» إلى «${targetName}»؟ يُغلق الانتماء السابق ويبقى في السجل، ولا يظهر المدرس بعدها لعميد الكلية السابقة. التكليفات النافذة لا تتغير.`
        : `إنهاء انتماء المدرس إلى «${source?.college_name}»؟ يبقى السجل، ولا تتغير التكليفات النافذة.`
    run(() => changeAffiliation(facultyMemberId, payload), text)
  }
  const affiliationReady = move.mode === 'assign' ? Boolean(move.to) : move.mode === 'transfer' ? Boolean(move.from && move.to) : Boolean(move.from)

  return (
    <Dialog title="الملف التدريسي" onClose={onClose} maxWidth="max-w-[780px]">
      {loading && !detail ? (
        <div className="flex flex-col items-center justify-center gap-3.5 py-[60px] text-primary-light text-[14px] font-medium"><FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" /><span>جاري التحميل…</span></div>
      ) : !detail ? (
        <p className="m-6 text-[13px] text-red-600 bg-red-50 border border-red-200 rounded-[10px] px-4 py-3" role="alert">⚠ {error || 'تعذّر تحميل الملف.'}</p>
      ) : (
        <div className="p-6 space-y-5">
          <div>
            <p className="text-[17px] font-black text-text-dark">{detail.full_name || '—'}</p>
            <p className="text-[12.5px] text-text-gray">رقم الموظف {detail.employee_number || '—'} • {detail.employee_status?.name || detail.employee_status?.code || '—'} • الملف {detail.is_active ? 'مفعّل' : 'غير مفعّل'}</p>
            <p className="text-[12.5px] text-text-gray">الوحدة الأساسية: {detail.home_unit?.unit_name || '—'}</p>
          </div>
          {notice && <Notice tone="success">✓ {notice}</Notice>}
          {error && <Notice tone="error">⚠ {error}</Notice>}

          <section>
            <h4 className="text-[14px] font-extrabold text-text-dark mb-2">الانتماء الحالي إلى الكليات</h4>
            {current.length === 0 ? <p className="text-[12.5px] text-text-light">بلا انتماء لكلية؛ لا يظهر في قوائم مدرسي الكليات لدى العمداء.</p> : (
              <ul className="flex flex-wrap gap-2">
                {current.map(college => (
                  <li key={college.college_id} className="px-3 py-1.5 bg-primary/8 border border-primary/15 rounded-[9px] text-[12px] font-bold text-primary-dark">
                    {college.college_name} <span className="font-normal text-text-light">({affiliationSourceLabel(college.source)}{college.start_date ? ` منذ ${String(college.start_date).slice(0, 10)}` : ''})</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          {canManage && (
            <section className="border border-primary/12 rounded-[12px] p-4 space-y-3">
              <h4 className="text-[14px] font-extrabold text-text-dark">تغيير الانتماء</h4>
              <div className="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1">
                <Field label="العملية">
                  <select className={`${inputClass} mt-1`} value={move.mode} onChange={e => setMove({ mode: e.target.value, from: '', to: '', start_date: move.start_date })}>
                    <option value="assign">إسناد إلى كلية إضافية</option>
                    <option value="transfer" disabled={current.length === 0}>نقل من كلية إلى أخرى</option>
                    <option value="end" disabled={current.length === 0}>إنهاء انتماء</option>
                  </select>
                </Field>
                {move.mode !== 'assign' && (
                  <Field label="من الكلية" error={fieldErrors.from_college_id}>
                    <select className={`${inputClass} mt-1`} value={move.from} onChange={e => setMove(m => ({ ...m, from: e.target.value }))}>
                      <option value="">اختر</option>
                      {current.map(college => <option key={college.college_id} value={college.college_id}>{college.college_name}</option>)}
                    </select>
                  </Field>
                )}
                {move.mode !== 'end' && (
                  <Field label="إلى الكلية" error={fieldErrors.college_id}>
                    <select className={`${inputClass} mt-1`} value={move.to} onChange={e => setMove(m => ({ ...m, to: e.target.value }))}>
                      <option value="">اختر</option>
                      {colleges.filter(college => !current.some(item => String(item.college_id) === String(college.id))).map(college => <option key={college.id} value={college.id}>{college.label}</option>)}
                    </select>
                  </Field>
                )}
                <Field label={move.mode === 'end' ? 'تاريخ الإنهاء (يُغلق في اليوم السابق)' : 'تاريخ البدء'} error={fieldErrors.start_date}>
                  <input className={`${inputClass} mt-1`} type="date" value={move.start_date} onChange={e => setMove(m => ({ ...m, start_date: e.target.value }))} />
                </Field>
              </div>
              <button type="button" disabled={busy || !affiliationReady} onClick={submitAffiliation} className={move.mode === 'end' ? dangerButton : primaryButton}>
                {busy && <FaSpinner className="animate-spin text-[11px]" />}
                {move.mode === 'assign' ? 'إسناد الانتماء' : move.mode === 'transfer' ? 'نقل الانتماء' : 'إنهاء الانتماء'}
              </button>
            </section>
          )}

          <section>
            <h4 className="text-[14px] font-extrabold text-text-dark mb-2">سجل الانتماء</h4>
            {(detail.affiliation_history ?? []).length === 0 ? <p className="text-[12.5px] text-text-light">لا توجد إسنادات وحدات لكليات.</p> : (
              <ul className="space-y-1.5 text-[12.5px]">
                {detail.affiliation_history.map(row => (
                  <li key={row.assignment_id} className="flex flex-wrap gap-x-2">
                    <span className="font-bold text-text-dark">{row.college_name}</span>
                    <span className="text-text-gray">{row.start_date} ← {row.end_date || 'مستمر'}</span>
                    <span className={row.is_active && !row.end_date ? 'text-green-700' : 'text-text-light'}>{row.is_active && !row.end_date ? 'نافذ' : 'منتهٍ'}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section>
            <h4 className="text-[14px] font-extrabold text-text-dark mb-1">التكليفات النافذة ({detail.effective_assignments_count})</h4>
            <p className="text-[11.5px] text-text-light mb-2">للاطلاع فقط؛ التكليف يُدار من العميد ويُعتمد من النائبين، ولا يتغير بتغيير الانتماء.</p>
            {(detail.effective_assignments ?? []).length === 0 ? <p className="text-[12.5px] text-text-light">لا توجد تكليفات نافذة.</p> : (
              <ul className="space-y-1 text-[12.5px] text-text-dark">
                {detail.effective_assignments.map(row => <li key={`${row.course_offering_id}-${row.instructor_role}`}>{row.course_code} — {row.course_name} • {row.instructor_role === 'practical' ? 'عملي' : 'نظري'} • {[row.year_name, row.semester_name].filter(Boolean).join(' / ')}</li>)}
              </ul>
            )}
          </section>

          {canManage && edit && (
            <section className="border border-primary/12 rounded-[12px] p-4 space-y-3">
              <h4 className="text-[14px] font-extrabold text-text-dark">تعديل الحقول المسموحة</h4>
              <div className="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1">
                {[['academic_rank', 'الرتبة العلمية'], ['specialization', 'الاختصاص'], ['office_location', 'المكتب'], ['phone_number', 'الهاتف'], ['email', 'البريد']].map(([key, label]) => (
                  <Field key={key} label={label} error={fieldErrors[key]}>
                    <input className={`${inputClass} mt-1`} dir={key === 'email' || key === 'phone_number' ? 'ltr' : 'rtl'} value={edit[key]} onChange={e => setEdit(v => ({ ...v, [key]: e.target.value }))} />
                  </Field>
                ))}
                <label className="flex items-center gap-2 text-[12.5px] font-bold text-text-dark">
                  <input type="checkbox" className="accent-[#569933]" checked={edit.is_active} onChange={e => setEdit(v => ({ ...v, is_active: e.target.checked }))} />
                  الملف التدريسي مفعّل
                </label>
              </div>
              <button type="button" disabled={busy} className={primaryButton}
                onClick={() => run(() => updateFaculty(facultyMemberId, { ...edit, email: edit.email.trim() || null }), edit.is_active ? null : 'تعطيل الملف يُخفي المدرس من قوائم الاختيار لدى العمداء. متابعة؟')}>
                <FaSave className="text-[11px]" /> حفظ
              </button>
              <p className="text-[11px] text-text-light">حالة الموظف ونوعه ووحدته الأساسية تُدار من الموارد البشرية ولا تُعدّل هنا.</p>
            </section>
          )}
        </div>
      )}
    </Dialog>
  )
}

export default function AdministrativeFacultyPage() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ total: 0, last_page: 1 })
  const [colleges, setColleges] = useState([])
  const [capabilities, setCapabilities] = useState({ faculty_manage: false })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [filters, setFilters] = useState({ search: '', college_id: '', is_active: '', employee_status: '', page: 1 })
  const [creating, setCreating] = useState(false)
  const [selectedId, setSelectedId] = useState(null)
  const [success, setSuccess] = useState('')
  const debounceRef = useRef(null)
  const firstLoad = useRef(true)
  const canManage = capabilities.faculty_manage && canUseAdministrative('facultyManage')
  const hasFilters = Boolean(filters.search.trim() || filters.college_id || filters.is_active || filters.employee_status)

  const load = useCallback(async (state) => {
    setLoading(true); setError('')
    try {
      const json = await fetchFaculty(facultyQuery(state, PER_PAGE))
      setRows(json.data?.data ?? [])
      setMeta(json.data?.meta ?? { total: 0, last_page: 1 })
      setColleges(json.data?.college_options ?? [])
      setCapabilities(json.data?.capabilities ?? { faculty_manage: false })
    } catch (err) {
      if (err?.status === 401) { clearIdentity(); navigate('/login'); return }
      setRows([])
      setError(governanceError(err).message)
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => {
    clearTimeout(debounceRef.current)
    const delay = firstLoad.current ? 0 : 350
    firstLoad.current = false
    debounceRef.current = setTimeout(() => load(filters), delay)
    return () => clearTimeout(debounceRef.current)
  }, [filters, load])

  const setFilter = key => value => setFilters(current => ({ ...current, [key]: value, page: 1 }))
  const clearFilters = () => setFilters({ search: '', college_id: '', is_active: '', employee_status: '', page: 1 })

  const columns = [
    { key: 'idx', header: '#', dir: 'rtl', cellClassName: 'text-[12px] text-text-light font-semibold w-10', render: (_, idx) => (filters.page - 1) * PER_PAGE + idx + 1 },
    { key: 'name', header: 'المدرس', dir: 'rtl', render: row => <div><div className="font-semibold text-[13.5px] text-text-dark">{row.full_name || '—'}</div><div className="text-[12px] text-text-gray">رقم الموظف {row.employee_number || '—'}</div></div> },
    { key: 'rank', header: 'الرتبة / الاختصاص', dir: 'rtl', render: row => <div className="text-[12.5px]"><div className="text-text-dark">{row.academic_rank || '—'}</div><div className="text-text-light">{row.specialization || '—'}</div></div> },
    { key: 'colleges', header: 'الانتماء إلى الكليات', dir: 'rtl', render: row => <span className={`text-[12.5px] ${row.colleges?.length ? 'text-text-dark font-semibold' : 'text-text-light'}`}>{affiliationText(row.colleges)}</span> },
    { key: 'assignments', header: 'التكليفات النافذة', align: 'center', render: row => <span className="text-[12.5px] font-bold">{row.effective_assignments_count}</span> },
    { key: 'status', header: 'الحالة', dir: 'rtl', render: row => <div className="flex flex-col gap-1 items-start"><span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${row.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>{row.is_active ? 'ملف مفعّل' : 'ملف غير مفعّل'}</span><span className="text-[11px] text-text-light">{row.employee_status?.name || row.employee_status?.code || '—'}</span></div> },
    { key: 'actions', header: 'الإجراءات', dir: 'rtl', render: row => <button type="button" onClick={() => setSelectedId(row.faculty_member_id)} className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-blue-500 border-blue-500/20 bg-blue-50 hover:bg-blue-100 transition-colors" title="عرض الملف" aria-label={`عرض ملف ${row.full_name}`}><FaEye /></button> },
  ]

  return (
    <div className="py-6 px-2" dir="rtl">
      {creating && <CreateTeacherModal colleges={colleges} onClose={() => setCreating(false)} onCreated={(member, message) => { setCreating(false); setSuccess(message || 'تم إنشاء الملف التدريسي.'); load(filters); setSelectedId(member.faculty_member_id) }} />}
      {selectedId && <FacultyDetailPanel facultyMemberId={selectedId} colleges={colleges} canManage={canManage} onClose={() => setSelectedId(null)} onChanged={() => load(filters)} />}

      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">إدارة المدرسين</h2>
          <p className="text-[12.5px] text-text-light">{meta.total > 0 ? `${meta.total} مدرس` : 'ملفات المدرسين وانتماؤهم إلى الكليات'}</p>
        </div>
        {canManage && <button type="button" onClick={() => setCreating(true)} className={headerButton}><FaUserPlus /> إضافة مدرس</button>}
      </div>

      <div className="flex items-start gap-2 bg-primary/5 border border-primary/15 rounded-[12px] px-4 py-3 mb-4 text-[12.5px] text-text-gray">
        <FaInfoCircle className="text-primary mt-0.5 flex-shrink-0" />
        <span>الانتماء إلى كلية يجعل المدرس ظاهرًا لعميدها ومقدّمًا في قائمة الاختيار. التكليف بمادة شيء مختلف: يقترحه العميد ويعتمده النائبان، ولا يُنشأ من هذه الصفحة.</span>
      </div>

      {success && <div className="mb-4"><Notice tone="success" onDismiss={() => setSuccess('')}>✓ {success}</Notice></div>}

      <FilterBar
        search={{ value: filters.search, onChange: setFilter('search'), placeholder: 'ابحث بالاسم أو رقم الموظف أو الاختصاص أو الرتبة…' }}
        filters={[
          { key: 'college', value: filters.college_id, onChange: setFilter('college_id'), placeholder: 'كل الكليات', minWidth: 200, options: [{ value: 'none', label: 'بلا انتماء لكلية' }, ...colleges.map(college => ({ value: String(college.id), label: college.label }))] },
          { key: 'active', value: filters.is_active, onChange: setFilter('is_active'), placeholder: 'كل الملفات', options: [{ value: '1', label: 'ملف مفعّل' }, { value: '0', label: 'ملف غير مفعّل' }] },
          { key: 'employee', value: filters.employee_status, onChange: setFilter('employee_status'), placeholder: 'كل حالات الموظف', minWidth: 160, options: [{ value: 'active', label: 'على رأس عمله' }] },
        ]}
        hasActiveFilters={Boolean(filters.college_id || filters.is_active || filters.employee_status)}
        onClear={clearFilters}
      />

      {error && <div className="mb-4"><Notice tone="error" action={<button type="button" onClick={() => load(filters)} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px]">إعادة المحاولة</button>}>⚠ {error}</Notice></div>}

      <DataTable
        columns={columns}
        rows={rows}
        rowKey={row => row.faculty_member_id}
        loading={loading}
        animationKey={JSON.stringify(filters)}
        emptyIcon={FaUserTie}
        emptyTitle={error ? 'تعذّر عرض المدرسين' : hasFilters ? 'لا يوجد مدرسون مطابقون' : 'لا توجد ملفات تدريسية'}
        emptySubtitle={hasFilters ? 'جرّب تعديل البحث أو الفلاتر.' : undefined}
        hasFilters={hasFilters}
        onClearFilters={clearFilters}
        page={filters.page}
        totalPages={meta.last_page ?? 1}
        onPageChange={page => setFilters(current => ({ ...current, page }))}
      />
    </div>
  )
}
