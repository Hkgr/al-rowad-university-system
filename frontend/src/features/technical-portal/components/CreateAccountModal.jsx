import { useState } from 'react'
import { motion } from 'framer-motion'
import { FaTimes, FaSave, FaSpinner } from 'react-icons/fa'
import { createAccount } from '../lib/accountsApi'
import { accountErrorMessage } from '../lib/accountsState'

const EMPTY = { username: '', email: '', password: '', passwordConfirmation: '', accountStatus: 'active', roleIds: [] }

const inputClass = 'w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark outline-none focus:border-primary'

function Field({ label, error, children }) {
  return (
    <div>
      <label className="block text-[11.5px] font-bold text-text-dark mb-1">{label}</label>
      {children}
      {error && <p className="mt-1 text-[11.5px] text-red-600">{error}</p>}
    </div>
  )
}

// Roles offered here are only those the server marked `assignable` for this actor.
export default function CreateAccountModal({ roles, statuses, onClose, onCreated }) {
  const [form, setForm] = useState(EMPTY)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const assignable = roles.filter(role => role.assignable)

  const set = (key, value) => setForm(current => ({ ...current, [key]: value }))
  const toggleRole = roleId => set('roleIds', form.roleIds.includes(roleId) ? form.roleIds.filter(id => id !== roleId) : [...form.roleIds, roleId])

  async function handleSubmit(event) {
    event.preventDefault()
    setSaving(true); setError(''); setFieldErrors({})
    try {
      const json = await createAccount(form)
      setForm(EMPTY)
      onCreated(json.data, json.message)
    } catch (err) {
      const mapped = accountErrorMessage(err)
      setError(mapped.message)
      setFieldErrors(mapped.fieldErrors)
      if (mapped.fieldErrors.password) setForm(current => ({ ...current, password: '', passwordConfirmation: '' }))
    } finally {
      setSaving(false)
    }
  }

  const roleError = fieldErrors.role_ids ?? Object.entries(fieldErrors).find(([key]) => key.startsWith('role_ids.'))?.[1]

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <motion.form
        onSubmit={handleSubmit}
        className="bg-white rounded-[18px] shadow-2xl w-full max-w-[620px] max-h-[90vh] overflow-y-auto"
        initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.2 }}
        dir="rtl" autoComplete="off" aria-label="إنشاء حساب"
      >
        <div className="flex items-center justify-between px-6 py-4 border-b border-primary/10">
          <h3 className="text-[16px] font-extrabold text-text-dark">إنشاء حساب جديد</h3>
          <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-text-light transition-colors" aria-label="إغلاق"><FaTimes /></button>
        </div>

        <div className="p-6 grid grid-cols-2 gap-4 max-[560px]:grid-cols-1">
          <Field label="اسم المستخدم *" error={fieldErrors.username}>
            <input className={inputClass} dir="ltr" value={form.username} onChange={e => set('username', e.target.value)} autoComplete="off" required />
          </Field>
          <Field label="البريد الإلكتروني *" error={fieldErrors.email}>
            <input className={inputClass} dir="ltr" type="email" value={form.email} onChange={e => set('email', e.target.value)} autoComplete="off" required />
          </Field>
          <Field label="كلمة المرور *" error={fieldErrors.password}>
            <input className={inputClass} dir="ltr" type="password" value={form.password} onChange={e => set('password', e.target.value)} autoComplete="new-password" required />
          </Field>
          <Field label="تأكيد كلمة المرور *" error={fieldErrors.password_confirmation}>
            <input className={inputClass} dir="ltr" type="password" value={form.passwordConfirmation} onChange={e => set('passwordConfirmation', e.target.value)} autoComplete="new-password" required />
          </Field>
          <p className="col-span-2 max-[560px]:col-span-1 -mt-2 text-[11px] text-text-light">
            10 محارف على الأقل، وتتضمن حرفًا كبيرًا وحرفًا صغيرًا ورقمًا. تُشفَّر كلمة المرور على الخادم ولا تُعرض مجددًا.
          </p>
          <Field label="حالة الحساب *" error={fieldErrors.account_status}>
            <select className={inputClass} value={form.accountStatus} onChange={e => set('accountStatus', e.target.value)}>
              {statuses.map(status => <option key={status.code} value={status.code}>{status.code === 'active' ? 'مفعّل' : 'معطّل'}</option>)}
            </select>
          </Field>

          <div className="col-span-2 max-[560px]:col-span-1">
            <p className="text-[11.5px] font-bold text-text-dark mb-1.5">الأدوار (اختياري)</p>
            {assignable.length === 0 ? (
              <p className="text-[12px] text-text-light">لا توجد أدوار متاحة لك لإسنادها.</p>
            ) : (
              <div className="grid grid-cols-2 gap-2 max-[560px]:grid-cols-1">
                {assignable.map(role => (
                  <label key={role.role_id} className="flex items-start gap-2 px-3 py-2 border border-primary/15 rounded-[10px] cursor-pointer hover:bg-primary/[0.035]">
                    <input type="checkbox" className="mt-1 accent-[#569933]" checked={form.roleIds.includes(role.role_id)} onChange={() => toggleRole(role.role_id)} />
                    <span className="flex flex-col">
                      <span className="text-[12.5px] font-bold text-text-dark">{role.role_name}</span>
                      <span className="text-[10.5px] text-text-light">{role.permissions.length} صلاحية من هذا الدور</span>
                    </span>
                  </label>
                ))}
              </div>
            )}
            {roleError && <p className="mt-1 text-[11.5px] text-red-600">{roleError}</p>}
          </div>
        </div>

        {error && <p className="mx-6 mb-3 text-[12px] text-red-600 bg-red-50 border border-red-200 rounded-[8px] px-3 py-2" role="alert">⚠ {error}</p>}

        <div className="flex items-center justify-end gap-3 px-6 pb-5 pt-1">
          <button type="button" onClick={onClose} className="px-5 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark hover:bg-gray-50 transition-colors">إلغاء</button>
          <button type="submit" disabled={saving}
            className="flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-[9px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors">
            {saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaSave className="text-[11px]" />}
            إنشاء الحساب
          </button>
        </div>
      </motion.form>
    </div>
  )
}
