import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaExchangeAlt, FaInfoCircle, FaSearch, FaSpinner, FaUniversity, FaUserMinus, FaUserPlus } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import { clearIdentity } from '../../auth/auth'
import { appointDean, endDean, fetchDeans, lookupAccount, lookupEmployee, transferDean } from '../lib/administrativeApi'
import { canUseAdministrative } from '../utils/administrativeAccess'
import { appointPayload, currentDeanId, deanWarningText, governanceError } from '../utils/administrativeGovernance'
import { Dialog, Field, Notice, dangerButton, inputClass, primaryButton, secondaryButton } from '../components/GovernanceUi'

const EMPTY = {
  employeeMode: 'new', employee_id: '', employee_number: '', first_name: '', last_name: '', father_name: '', phone_number: '', email: '',
  accountMode: 'new', user_id: '', username: '', account_email: '', password: '', password_confirmation: '',
  replace_current: false, start_date: '', confirmed: false,
}

function AppointDialog({ college, onClose, onDone }) {
  const [form, setForm] = useState(EMPTY)
  const [employee, setEmployee] = useState(null)
  const [account, setAccount] = useState(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const set = (key, value) => setForm(current => ({ ...current, [key]: value }))
  const occupied = (college.deans ?? []).length > 0

  async function findEmployee() {
    setEmployee(null); setError('')
    try {
      const json = await lookupEmployee(form.employee_number.trim())
      setEmployee(json.data ?? false)
      if (json.data) set('employee_id', String(json.data.employee_id))
    } catch (err) { setError(governanceError(err).message) }
  }

  async function findAccount() {
    setAccount(null); setError('')
    try {
      const json = await lookupAccount(form.username.trim())
      setAccount(json.data ?? false)
      if (json.data) set('user_id', String(json.data.user_id))
    } catch (err) { setError(governanceError(err).message) }
  }

  async function submit(event) {
    event.preventDefault()
    setSaving(true); setError(''); setFieldErrors({})
    try {
      const json = await appointDean(appointPayload(form, college))
      onDone(json.message, json.data)
    } catch (err) {
      const mapped = governanceError(err)
      setError(mapped.message); setFieldErrors(mapped.fieldErrors)
      setForm(current => ({ ...current, password: '', password_confirmation: '' }))
      if (mapped.reload) onDone(null, null, mapped.message)
    } finally {
      setSaving(false)
    }
  }

  const existingEmployeeReady = form.employeeMode !== 'existing' || (employee && employee.employee_status === 'active')
  const existingAccountReady = form.accountMode !== 'existing' || (account && account.eligible)
  const ready = form.confirmed && existingEmployeeReady && existingAccountReady && (!occupied || form.replace_current)

  return (
    <Dialog
      as="form" onSubmit={submit} title={`تعيين عميد — ${college.college_name}`} onClose={onClose} maxWidth="max-w-[760px]"
      footer={<>
        <button type="button" onClick={onClose} className={secondaryButton}>إلغاء</button>
        <button type="submit" disabled={saving || !ready} className={primaryButton}>{saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaUserPlus className="text-[11px]" />} تعيين العميد</button>
      </>}
    >
      <div className="p-6 space-y-5">
        <Notice>يُمنح الحساب دور «عميد» ونطاق هذه الكلية فقط، ويُربط بسجل الموظف، ويُسجَّل منصب العميد بتاريخ البدء. لا تُمنح أي أدوار أو نطاقات أخرى.</Notice>

        <section className="space-y-3">
          <h4 className="text-[14px] font-extrabold text-text-dark">1. سجل الموظف</h4>
          <div className="flex gap-2 flex-wrap">
            {[['new', 'موظف جديد'], ['existing', 'موظف قائم (تحقق بالرقم والكنية)']].map(([value, label]) => (
              <label key={value} className={`flex items-center gap-2 px-3 py-2 border rounded-[10px] cursor-pointer text-[12.5px] font-bold ${form.employeeMode === value ? 'border-primary bg-primary/5 text-primary-dark' : 'border-primary/15'}`}>
                <input type="radio" className="accent-[#569933]" checked={form.employeeMode === value} onChange={() => { set('employeeMode', value); setEmployee(null) }} /> {label}
              </label>
            ))}
          </div>
          <div className="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1">
            <Field label="رقم الموظف *" error={fieldErrors['employee.employee_number']}>
              <div className="flex gap-2 mt-1">
                <input className={inputClass} dir="ltr" value={form.employee_number} onChange={e => { set('employee_number', e.target.value); setEmployee(null) }} required />
                {form.employeeMode === 'existing' && <button type="button" onClick={findEmployee} className="px-3 border border-primary/20 rounded-[9px] text-primary" aria-label="بحث عن الموظف"><FaSearch /></button>}
              </div>
            </Field>
            <Field label="الكنية *" error={fieldErrors['employee.last_name']}><input className={`${inputClass} mt-1`} value={form.last_name} onChange={e => set('last_name', e.target.value)} required /></Field>
            {form.employeeMode === 'new' && <>
              <Field label="الاسم *" error={fieldErrors['employee.first_name']}><input className={`${inputClass} mt-1`} value={form.first_name} onChange={e => set('first_name', e.target.value)} required /></Field>
              <Field label="اسم الأب"><input className={`${inputClass} mt-1`} value={form.father_name} onChange={e => set('father_name', e.target.value)} /></Field>
              <Field label="بريد الموظف" error={fieldErrors['employee.email']}><input className={`${inputClass} mt-1`} dir="ltr" type="email" value={form.email} onChange={e => set('email', e.target.value)} /></Field>
              <Field label="الهاتف"><input className={`${inputClass} mt-1`} dir="ltr" value={form.phone_number} onChange={e => set('phone_number', e.target.value)} /></Field>
            </>}
          </div>
          {form.employeeMode === 'existing' && employee === false && <p className="text-[12.5px] text-red-600">لا يوجد موظف بهذا الرقم.</p>}
          {form.employeeMode === 'existing' && employee && (
            <p className="text-[12.5px] text-text-gray">{employee.full_name} • {employee.employee_status === 'active' ? 'على رأس عمله' : <span className="text-red-600">غير نشط</span>} • {employee.has_account ? 'له حساب دخول — اختر «ربط حساب قائم»' : 'بلا حساب دخول'}</p>
          )}
        </section>

        <section className="space-y-3">
          <h4 className="text-[14px] font-extrabold text-text-dark">2. حساب الدخول</h4>
          <div className="flex gap-2 flex-wrap">
            {[['new', 'إنشاء حساب جديد'], ['existing', 'ربط حساب قائم']].map(([value, label]) => (
              <label key={value} className={`flex items-center gap-2 px-3 py-2 border rounded-[10px] cursor-pointer text-[12.5px] font-bold ${form.accountMode === value ? 'border-primary bg-primary/5 text-primary-dark' : 'border-primary/15'}`}>
                <input type="radio" className="accent-[#569933]" checked={form.accountMode === value} onChange={() => { set('accountMode', value); setAccount(null) }} /> {label}
              </label>
            ))}
          </div>
          <div className="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1">
            <Field label="اسم المستخدم *" error={fieldErrors['account.username']}>
              <div className="flex gap-2 mt-1">
                <input className={inputClass} dir="ltr" value={form.username} onChange={e => { set('username', e.target.value); setAccount(null) }} required autoComplete="off" />
                {form.accountMode === 'existing' && <button type="button" onClick={findAccount} className="px-3 border border-primary/20 rounded-[9px] text-primary" aria-label="بحث عن الحساب"><FaSearch /></button>}
              </div>
            </Field>
            {form.accountMode === 'new' && <>
              <Field label="بريد الحساب *" error={fieldErrors['account.email']}><input className={`${inputClass} mt-1`} dir="ltr" type="email" value={form.account_email} onChange={e => set('account_email', e.target.value)} required autoComplete="off" /></Field>
              <Field label="كلمة المرور *" error={fieldErrors['account.password']}><input className={`${inputClass} mt-1`} dir="ltr" type="password" value={form.password} onChange={e => set('password', e.target.value)} required autoComplete="new-password" /></Field>
              <Field label="تأكيد كلمة المرور *" error={fieldErrors['account.password_confirmation']}><input className={`${inputClass} mt-1`} dir="ltr" type="password" value={form.password_confirmation} onChange={e => set('password_confirmation', e.target.value)} required autoComplete="new-password" /></Field>
              <p className="col-span-2 max-[560px]:col-span-1 -mt-1 text-[11px] text-text-light">10 محارف على الأقل، وتتضمن حرفًا كبيرًا وحرفًا صغيرًا ورقمًا. تُشفَّر كلمة المرور على الخادم ولا تُعرض أو تُسجَّل.</p>
            </>}
          </div>
          {form.accountMode === 'existing' && account === false && <p className="text-[12.5px] text-red-600">لا يوجد حساب بهذا الاسم.</p>}
          {form.accountMode === 'existing' && account && (
            <p className={`text-[12.5px] ${account.eligible ? 'text-text-gray' : 'text-red-600'}`}>
              الأدوار: {account.roles.length ? account.roles.join('، ') : 'بلا أدوار'} • {account.employee_number ? `مرتبط بالموظف ${account.employee_number}` : 'غير مرتبط بموظف'} • {account.eligible ? 'يمكن ربطه' : 'غير قابل للربط من هذا المسار (أدوار أخرى أو نطاق جامعة أو حسابك)'}
            </p>
          )}
        </section>

        <section className="space-y-3">
          <h4 className="text-[14px] font-extrabold text-text-dark">3. التأكيد</h4>
          <Field label="تاريخ بدء التكليف"><input className={`${inputClass} mt-1`} type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)} /></Field>
          {occupied && (
            <label className="flex items-start gap-2 text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-3 py-2">
              <input type="checkbox" className="mt-1 accent-[#b45309]" checked={form.replace_current} onChange={e => set('replace_current', e.target.checked)} />
              <span>للكلية عميد حالي ({college.deans.map(dean => dean.full_name || dean.username).join('، ')}). أوافق على إنهاء تكليفه وسحب نطاق الكلية منه، مع إبقاء حسابه وسجله.</span>
            </label>
          )}
          <label className="flex items-start gap-2 text-[12.5px] text-text-dark">
            <input type="checkbox" className="mt-1 accent-[#569933]" checked={form.confirmed} onChange={e => set('confirmed', e.target.checked)} />
            <span>تحققت من هوية الموظف، وأفهم أن الحساب سيصل إلى بوابة العميد لكلية «{college.college_name}» فقط.</span>
          </label>
        </section>
        {error && <p className="text-[12px] text-red-600 bg-red-50 border border-red-200 rounded-[8px] px-3 py-2" role="alert">⚠ {error}</p>}
      </div>
    </Dialog>
  )
}

function TransferDialog({ college, dean, colleges, onClose, onDone }) {
  const [target, setTarget] = useState('')
  const [replace, setReplace] = useState(false)
  const [startDate, setStartDate] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const targetCollege = colleges.find(item => String(item.college_id) === String(target))
  const occupied = (targetCollege?.deans ?? []).length > 0

  async function submit(event) {
    event.preventDefault()
    setSaving(true); setError('')
    try {
      const json = await transferDean(college.college_id, {
        dean_user_id: dean.user_id,
        to_college_id: Number(target),
        expected_target_dean_user_id: currentDeanId(targetCollege),
        replace_current: replace || undefined,
        start_date: startDate || undefined,
      })
      onDone(json.message)
    } catch (err) {
      const mapped = governanceError(err)
      setError(mapped.message)
      if (mapped.reload) onDone(null, null, mapped.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog as="form" onSubmit={submit} title={`نقل العميد ${dean.full_name || dean.username}`} onClose={onClose}
      footer={<>
        <button type="button" onClick={onClose} className={secondaryButton}>إلغاء</button>
        <button type="submit" disabled={saving || !target || (occupied && !replace)} className={primaryButton}>{saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaExchangeAlt className="text-[11px]" />} نقل</button>
      </>}
    >
      <div className="p-6 space-y-4">
        <Notice tone="warning">يُسحب نطاق «{college.college_name}» من الحساب ويُغلق منصب العميد فيها، ثم يُمنح نطاق الكلية الجديدة فقط في العملية نفسها.</Notice>
        <Field label="الكلية الجديدة *">
          <select className={`${inputClass} mt-1`} value={target} onChange={e => { setTarget(e.target.value); setReplace(false) }} required>
            <option value="">اختر</option>
            {colleges.filter(item => item.college_id !== college.college_id && item.is_active && item.has_organizational_unit).map(item => <option key={item.college_id} value={item.college_id}>{item.college_name}</option>)}
          </select>
        </Field>
        <Field label="تاريخ البدء في الكلية الجديدة"><input className={`${inputClass} mt-1`} type="date" value={startDate} onChange={e => setStartDate(e.target.value)} /></Field>
        {occupied && (
          <label className="flex items-start gap-2 text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-3 py-2">
            <input type="checkbox" className="mt-1" checked={replace} onChange={e => setReplace(e.target.checked)} />
            <span>للكلية الجديدة عميد حالي؛ أوافق على إنهاء تكليفه وسحب نطاقه.</span>
          </label>
        )}
        {error && <p className="text-[12px] text-red-600 bg-red-50 border border-red-200 rounded-[8px] px-3 py-2" role="alert">⚠ {error}</p>}
      </div>
    </Dialog>
  )
}

export default function AdministrativeDeansPage() {
  const navigate = useNavigate()
  const [colleges, setColleges] = useState([])
  const [capabilities, setCapabilities] = useState({ deans_manage: false })
  const [roleAvailable, setRoleAvailable] = useState(true)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [actionError, setActionError] = useState('')
  const [dialog, setDialog] = useState(null)
  const [busy, setBusy] = useState(false)
  const canManage = capabilities.deans_manage && canUseAdministrative('deansManage') && roleAvailable

  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const json = await fetchDeans()
      setColleges(json.data?.colleges ?? [])
      setCapabilities(json.data?.capabilities ?? { deans_manage: false })
      setRoleAvailable(json.data?.dean_role_available !== false)
    } catch (err) {
      if (err?.status === 401) { clearIdentity(); navigate('/login'); return }
      setError(governanceError(err).message)
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => {
    const timer = setTimeout(load, 0)
    return () => clearTimeout(timer)
  }, [load])

  // A stale/conflicting state closes the dialog: its college snapshot is out of date.
  const done = (message, _data, staleMessage = null) => {
    setDialog(null)
    if (staleMessage) { setSuccess(''); setActionError(staleMessage) } else { setActionError(''); setSuccess(message || 'تم.') }
    load()
  }

  async function end(college, dean) {
    if (!window.confirm(`إنهاء تكليف ${dean.full_name || dean.username} عميدًا لـ«${college.college_name}»؟\nيُسحب نطاق الكلية ويُغلق المنصب، ويبقى الحساب وسجله. لن يصل بعدها إلى بيانات الكلية.`)) return
    setBusy(true); setActionError(''); setSuccess('')
    try {
      const json = await endDean(college.college_id, { dean_user_id: dean.user_id })
      setSuccess(json.message)
    } catch (err) {
      setActionError(governanceError(err).message)
    } finally {
      setBusy(false)
      load()
    }
  }

  const columns = [
    { key: 'college', header: 'الكلية', dir: 'rtl', render: row => <div><div className="font-semibold text-[13.5px] text-text-dark">{row.college_name}</div><div className="text-[11.5px] text-text-light">{row.college_code}{row.is_active ? '' : ' • غير مفعّلة'}{row.has_organizational_unit ? '' : ' • بلا وحدة تنظيمية'}</div></div> },
    {
      key: 'dean', header: 'العميد الحالي', dir: 'rtl',
      render: row => row.deans.length === 0 ? <span className="text-[12.5px] text-text-light">لا يوجد</span> : (
        <ul className="space-y-1">
          {row.deans.map(dean => (
            <li key={dean.user_id} className="text-[12.5px]">
              <span className="font-bold text-text-dark">{dean.full_name || '—'}</span>
              <span className="text-text-gray" dir="ltr"> {dean.username}</span>
              <span className="block text-[11px] text-text-light">{dean.employee_number ? `موظف ${dean.employee_number}` : 'غير مرتبط بموظف'} • منذ {dean.position_start_date ? String(dean.position_start_date).slice(0, 10) : 'غير مسجل'}{dean.account_status && dean.account_status !== 'active' ? ' • الحساب غير مفعّل' : ''}</span>
            </li>
          ))}
        </ul>
      ),
    },
    { key: 'warning', header: 'ملاحظة', dir: 'rtl', render: row => <span className={`text-[12px] ${row.warning === 'multiple_deans' ? 'text-red-600 font-bold' : 'text-text-light'}`}>{deanWarningText(row.warning) || '—'}</span> },
    {
      key: 'actions', header: 'الإجراءات', dir: 'rtl',
      render: row => !canManage ? <span className="text-[12px] text-text-light">عرض فقط</span> : (
        <div className="flex flex-wrap gap-1.5">
          {row.is_active && row.has_organizational_unit && <button type="button" onClick={() => setDialog({ kind: 'appoint', college: row })} className="flex items-center gap-1.5 px-3 py-1.5 border border-primary/20 bg-primary/5 text-primary-dark rounded-[9px] text-[12px] font-bold"><FaUserPlus /> {row.deans.length ? 'استبدال' : 'تعيين'}</button>}
          {row.deans.map(dean => (
            <span key={dean.user_id} className="flex gap-1.5">
              <button type="button" onClick={() => setDialog({ kind: 'transfer', college: row, dean })} className="flex items-center gap-1.5 px-3 py-1.5 border border-blue-500/20 bg-blue-50 text-blue-600 rounded-[9px] text-[12px] font-bold"><FaExchangeAlt /> نقل</button>
              <button type="button" disabled={busy} onClick={() => end(row, dean)} className={dangerButton}><FaUserMinus /> إنهاء</button>
            </span>
          ))}
        </div>
      ),
    },
  ]

  return (
    <div className="py-6 px-2" dir="rtl">
      {dialog?.kind === 'appoint' && <AppointDialog college={dialog.college} onClose={() => setDialog(null)} onDone={done} />}
      {dialog?.kind === 'transfer' && <TransferDialog college={dialog.college} dean={dialog.dean} colleges={colleges} onClose={() => setDialog(null)} onDone={done} />}

      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">عمداء الكليات</h2>
          <p className="text-[12.5px] text-text-light">{colleges.length ? `${colleges.length} كلية` : 'الكليات وعمداؤها'}</p>
        </div>
      </div>

      <div className="flex items-start gap-2 bg-primary/5 border border-primary/15 rounded-[12px] px-4 py-3 mb-4 text-[12.5px] text-text-gray">
        <FaInfoCircle className="text-primary mt-0.5 flex-shrink-0" />
        <span>يمنح هذا المسار دور «عميد» ونطاق كلية واحدة فقط. لا يعدّل حسابات مدير النظام أو الحسابات ذات نطاق الجامعة أو الأدوار الأخرى، ولا يحذف حسابًا. كل عملية تُسجَّل مع القيم السابقة والجديدة.</span>
      </div>
      {!roleAvailable && <div className="mb-4"><Notice tone="warning">دور العميد غير معرّف أو غير مفعّل في النظام؛ لا يمكن التعيين.</Notice></div>}
      {success && <div className="mb-4"><Notice tone="success" onDismiss={() => setSuccess('')}>✓ {success}</Notice></div>}
      {actionError && <div className="mb-4"><Notice tone="error" onDismiss={() => setActionError('')}>⚠ {actionError}</Notice></div>}
      {error && <div className="mb-4"><Notice tone="error" action={<button type="button" onClick={load} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px]">إعادة التحميل</button>}>⚠ {error}</Notice></div>}

      <DataTable
        columns={columns}
        rows={colleges}
        rowKey={row => row.college_id}
        loading={loading}
        emptyIcon={FaUniversity}
        emptyTitle={error ? 'تعذّر عرض الكليات' : 'لا توجد كليات'}
      />
    </div>
  )
}
