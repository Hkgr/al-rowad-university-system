import { useState } from 'react'
import { FaEye, FaEyeSlash, FaIdCard, FaKey, FaSave, FaSpinner, FaUserEdit } from 'react-icons/fa'
import { correctHolderName, resetAccountPassword, updateAccountLogin } from '../lib/accountsApi'
import { HOLDER_TYPE_AR, accountErrorMessage } from '../lib/accountsState'

// Same controls as CreateAccountModal / AccountDetailPanel (technical portal references).
const inputClass = 'w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark outline-none focus:border-primary disabled:bg-gray-50'
const sectionClass = 'border border-primary/12 rounded-[12px] px-4 py-3.5'
const primaryButton = 'flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors'
const ghostButton = 'px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] text-text-dark hover:bg-gray-50 transition-colors disabled:opacity-50'

function Field({ label, error, children }) {
  return (
    <div>
      <label className="block text-[11.5px] font-bold text-text-dark mb-1">{label}{children}</label>
      {error && <p className="mt-1 text-[11.5px] text-red-600">{error}</p>}
    </div>
  )
}

function Feedback({ error, notice }) {
  return (
    <>
      {notice && <p className="mt-3 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px] px-4 py-2.5" role="status">✓ {notice}</p>}
      {error && <p className="mt-3 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px] px-4 py-2.5" role="alert">⚠ {error}</p>}
    </>
  )
}

/** Hook: run a request, map errors to Arabic, reload on 409/403 (state may be stale). */
function useAction(onDone, onStale) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  async function run(request, { confirmText, onSuccess } = {}) {
    if (confirmText && !window.confirm(confirmText)) return
    setBusy(true); setError(''); setNotice(''); setFieldErrors({})
    try {
      const json = await request()
      setNotice(json.message)
      onSuccess?.(json)
      await onDone(json.data)
    } catch (err) {
      const mapped = accountErrorMessage(err)
      setError(mapped.message)
      setFieldErrors(mapped.fieldErrors)
      if (err?.status === 409 || err?.status === 403) onStale()
    } finally {
      setBusy(false)
    }
  }
  return { busy, error, notice, fieldErrors, run }
}

export function LoginIdentitySection({ detail, onDone, onStale }) {
  const [editing, setEditing] = useState(false)
  const [username, setUsername] = useState(detail.username)
  const [email, setEmail] = useState(detail.email)
  const action = useAction(onDone, onStale)
  const changed = {}
  if (username.trim() !== detail.username) changed.username = username.trim()
  if (email.trim().toLowerCase() !== detail.email) changed.email = email.trim()

  return (
    <section className={sectionClass} aria-labelledby="login-identity-title">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h4 id="login-identity-title" className="flex items-center gap-2 text-[14px] font-extrabold text-text-dark"><FaUserEdit className="text-primary" /> بيانات الدخول</h4>
        {!editing && <button type="button" className={ghostButton} onClick={() => { setEditing(true); setUsername(detail.username); setEmail(detail.email) }}>تعديل اسم المستخدم أو البريد</button>}
      </div>
      <p className="mt-1 text-[11.5px] text-text-light">«اسم المستخدم» هو معرّف الحساب في النظام (أحرف لاتينية). الدخول يتم بالبريد الإلكتروني وكلمة المرور.</p>
      {editing && (
        <form className="mt-3 grid grid-cols-2 gap-3 max-[560px]:grid-cols-1" onSubmit={e => { e.preventDefault(); action.run(() => updateAccountLogin(detail.user_id, changed), { onSuccess: () => setEditing(false) }) }}>
          <Field label="اسم المستخدم" error={action.fieldErrors.username}>
            <input className={`${inputClass} mt-1`} dir="ltr" value={username} onChange={e => setUsername(e.target.value)} autoComplete="off" />
          </Field>
          <Field label="البريد الإلكتروني" error={action.fieldErrors.email}>
            <input className={`${inputClass} mt-1`} dir="ltr" type="email" value={email} onChange={e => setEmail(e.target.value)} autoComplete="off" />
          </Field>
          <p className="col-span-2 max-[560px]:col-span-1 text-[11px] text-text-light">يُحوَّل البريد إلى أحرف صغيرة، ويُرفض اسم أو بريد مستخدم لحساب آخر. تغيير البريد يغيّر بريد الدخول لصاحب الحساب؛ أبلغه به.</p>
          <div className="col-span-2 max-[560px]:col-span-1 flex gap-2 justify-end">
            <button type="button" className={ghostButton} onClick={() => setEditing(false)} disabled={action.busy}>إلغاء</button>
            <button type="submit" className={primaryButton} disabled={action.busy || Object.keys(changed).length === 0}>
              {action.busy ? <FaSpinner className="animate-spin text-[11px]" /> : <FaSave className="text-[11px]" />} حفظ بيانات الدخول
            </button>
          </div>
        </form>
      )}
      <Feedback error={action.error} notice={action.notice} />
    </section>
  )
}

export function PasswordResetSection({ detail, onDone, onStale }) {
  const [open, setOpen] = useState(false)
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [visible, setVisible] = useState(false)
  const action = useAction(onDone, onStale)
  const clear = () => { setPassword(''); setConfirmation(''); setVisible(false) }

  return (
    <section className={sectionClass} aria-labelledby="password-reset-title">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h4 id="password-reset-title" className="flex items-center gap-2 text-[14px] font-extrabold text-text-dark"><FaKey className="text-primary" /> كلمة المرور</h4>
        {!open && <button type="button" className={ghostButton} onClick={() => setOpen(true)}>إعادة تعيين كلمة المرور</button>}
      </div>
      <p className="mt-1 text-[11.5px] text-text-light">كلمة المرور الحالية غير قابلة للعرض أو الاسترجاع. إعادة التعيين تنهي كل جلسات الحساب المفتوحة.</p>
      {open && (
        <form className="mt-3 grid grid-cols-2 gap-3 max-[560px]:grid-cols-1" autoComplete="off"
          onSubmit={e => {
            e.preventDefault()
            action.run(() => resetAccountPassword(detail.user_id, { password, passwordConfirmation: confirmation }), {
              confirmText: `تعيين كلمة مرور جديدة للحساب "${detail.username}" وإنهاء جلساته الحالية؟`,
              onSuccess: () => { clear(); setOpen(false) },
            }).finally(() => { setPassword(''); setConfirmation('') })
          }}>
          <Field label="كلمة المرور الجديدة" error={action.fieldErrors.password}>
            <div className="relative mt-1">
              <input className={`${inputClass} pl-9`} dir="ltr" type={visible ? 'text' : 'password'} value={password} onChange={e => setPassword(e.target.value)} autoComplete="new-password" required />
              <button type="button" onClick={() => setVisible(v => !v)} className="absolute left-2 top-1/2 -translate-y-1/2 text-text-light" aria-label={visible ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}>{visible ? <FaEyeSlash /> : <FaEye />}</button>
            </div>
          </Field>
          <Field label="تأكيد كلمة المرور" error={action.fieldErrors.password_confirmation}>
            <input className={`${inputClass} mt-1`} dir="ltr" type={visible ? 'text' : 'password'} value={confirmation} onChange={e => setConfirmation(e.target.value)} autoComplete="new-password" required />
          </Field>
          <p className="col-span-2 max-[560px]:col-span-1 text-[11px] text-text-light">10 محارف على الأقل، وتتضمن حرفًا كبيرًا وحرفًا صغيرًا ورقمًا. تُشفَّر على الخادم ولا تُعرض مجددًا؛ سلّمها لصاحب الحساب بقناة آمنة.</p>
          <div className="col-span-2 max-[560px]:col-span-1 flex gap-2 justify-end">
            <button type="button" className={ghostButton} onClick={() => { clear(); setOpen(false) }} disabled={action.busy}>إلغاء</button>
            <button type="submit" className={primaryButton} disabled={action.busy || !password || !confirmation}>
              {action.busy ? <FaSpinner className="animate-spin text-[11px]" /> : <FaKey className="text-[11px]" />} تعيين كلمة المرور
            </button>
          </div>
        </form>
      )}
      <Feedback error={action.error} notice={action.notice} />
    </section>
  )
}

export function HolderNameSection({ detail, onDone, onStale }) {
  const holder = detail.holder ?? {}
  const canCorrect = Boolean(detail.capabilities?.can_correct_holder_name)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState({})
  const action = useAction(onDone, onStale)
  const start = () => { setForm({ firstName: holder.first_name ?? '', lastName: holder.last_name ?? '', fatherName: holder.father_name ?? '', motherName: holder.mother_name ?? '' }); setEditing(true) }
  const set = (key, value) => setForm(current => ({ ...current, [key]: value }))

  return (
    <section className={sectionClass} aria-labelledby="holder-name-title">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h4 id="holder-name-title" className="flex items-center gap-2 text-[14px] font-extrabold text-text-dark"><FaIdCard className="text-primary" /> اسم صاحب الحساب</h4>
        {canCorrect && !editing && <button type="button" className={ghostButton} onClick={start}>تصحيح الاسم</button>}
      </div>
      <p className="mt-1 text-[11.5px] text-text-light">«اسم صاحب الحساب» هو الاسم الحقيقي للشخص، ويُحفظ في سجل {holder.type ? HOLDER_TYPE_AR[holder.type] : 'الموظف أو الطالب'} المرتبط بالحساب، لا في الحساب نفسه. التصحيح يعدّل الاسم في ذلك السجل فقط ويبقي الربط كما هو.</p>
      {holder.links === 0 && (
        <p className="mt-2 text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-4 py-2">هذا الحساب غير مرتبط بموظف أو طالب؛ لا يوجد اسم صاحب حساب لعرضه أو تصحيحه. ربط الحساب بسجل شخص يتم عبر مسار مطابقة الهوية لدى مدير النظام.</p>
      )}
      {holder.links > 1 && (
        <p className="mt-2 text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-4 py-2">الحساب مرتبط بموظف وطالب معًا؛ لا يُصحَّح الاسم من هنا قبل مراجعة الربط.</p>
      )}
      {holder.type && !editing && (
        <p className="mt-2 text-[14px] font-bold text-text-dark">{holder.display_name || '—'} <span className="text-[11.5px] font-normal text-text-light">({HOLDER_TYPE_AR[holder.type]} #{holder.id})</span></p>
      )}
      {holder.type && !canCorrect && detail.capabilities?.restriction !== 'protected_account' && (
        <p className="mt-1 text-[11.5px] text-text-light">تصحيح الاسم يحتاج صلاحية «تصحيح اسم صاحب الحساب»، ولا يشمل حسابك الشخصي.</p>
      )}
      {editing && (
        <form className="mt-3 grid grid-cols-2 gap-3 max-[560px]:grid-cols-1" onSubmit={e => {
          e.preventDefault()
          action.run(() => correctHolderName(detail.user_id, { personType: holder.type, ...form }), { onSuccess: () => setEditing(false) })
        }}>
          <Field label="الاسم الأول *" error={action.fieldErrors.first_name}><input className={`${inputClass} mt-1`} value={form.firstName} onChange={e => set('firstName', e.target.value)} required /></Field>
          <Field label="الكنية *" error={action.fieldErrors.last_name}><input className={`${inputClass} mt-1`} value={form.lastName} onChange={e => set('lastName', e.target.value)} required /></Field>
          <Field label="اسم الأب" error={action.fieldErrors.father_name}><input className={`${inputClass} mt-1`} value={form.fatherName} onChange={e => set('fatherName', e.target.value)} /></Field>
          <Field label="اسم الأم" error={action.fieldErrors.mother_name}><input className={`${inputClass} mt-1`} value={form.motherName} onChange={e => set('motherName', e.target.value)} /></Field>
          <p className="col-span-2 max-[560px]:col-span-1 text-[11px] text-text-light">لا تُعدَّل هنا البيانات الوظيفية أو الأكاديمية أو الرقم الوظيفي أو الجامعي؛ تلك من اختصاص الموارد البشرية وشؤون الطلاب.</p>
          <div className="col-span-2 max-[560px]:col-span-1 flex gap-2 justify-end">
            <button type="button" className={ghostButton} onClick={() => setEditing(false)} disabled={action.busy}>إلغاء</button>
            <button type="submit" className={primaryButton} disabled={action.busy}>{action.busy ? <FaSpinner className="animate-spin text-[11px]" /> : <FaSave className="text-[11px]" />} حفظ الاسم</button>
          </div>
        </form>
      )}
      <Feedback error={action.error} notice={action.notice} />
    </section>
  )
}
