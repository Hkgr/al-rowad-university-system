import { useCallback, useEffect, useState } from 'react'
import { motion } from 'framer-motion'
import { FaTimes, FaSpinner, FaPlus, FaMinusCircle, FaLock, FaInfoCircle, FaToggleOn, FaToggleOff } from 'react-icons/fa'
import { assignAccountRole, fetchAccount, revokeAccountRole, updateAccountStatus } from '../lib/accountsApi'
import { RESTRICTION_AR, STATUS_AR, STATUS_BADGE, accountErrorMessage, assignableRolesFor, groupPermissionsByRole } from '../lib/accountsState'

const formatDate = value => value ? new Date(value).toLocaleString('ar-SY', { dateStyle: 'medium', timeStyle: 'short' }) : '—'

export default function AccountDetailPanel({ userId, roles, onClose, onChanged }) {
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [roleToAssign, setRoleToAssign] = useState('')

  // State is only set from the async callbacks, never synchronously inside the effect.
  const fetchDetail = useCallback(() => fetchAccount(userId)
    .then(json => { setDetail(json.data); return null })
    .catch(err => accountErrorMessage(err).message)
    .then(message => { if (message) setError(message); setLoading(false) }), [userId])

  useEffect(() => { fetchDetail() }, [fetchDetail])

  const load = () => { setLoading(true); return fetchDetail() }

  async function run(action, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return
    setBusy(true); setError(''); setNotice('')
    try {
      const json = await action()
      setDetail(json.data)
      setNotice(json.message)
      setRoleToAssign('')
      await onChanged(json.data)
    } catch (err) {
      setError(accountErrorMessage(err).message)
      if (err?.status === 409) load()
    } finally {
      setBusy(false)
    }
  }

  const capabilities = detail?.capabilities ?? {}
  const roleById = new Map(roles.map(role => [role.role_id, role]))
  const candidates = detail && capabilities.can_manage ? assignableRolesFor(detail, roles) : []
  const permissionGroups = groupPermissionsByRole(detail?.effective_permissions)
  const revoked = (detail?.role_assignments ?? []).filter(row => !row.is_active)
  const active = (detail?.role_assignments ?? []).filter(row => row.is_active)
  const statusCode = detail?.status?.code

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <motion.div
        className="bg-white rounded-[18px] shadow-2xl w-full max-w-[760px] max-h-[92vh] overflow-y-auto"
        initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.2 }}
        dir="rtl" role="dialog" aria-label="تفاصيل الحساب"
      >
        <div className="flex items-center justify-between px-6 py-4 border-b border-primary/10 sticky top-0 bg-white z-[1]">
          <h3 className="text-[16px] font-extrabold text-text-dark">تفاصيل الحساب</h3>
          <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-text-light transition-colors" aria-label="إغلاق"><FaTimes /></button>
        </div>

        {loading && !detail ? (
          <div className="flex flex-col items-center justify-center gap-3.5 py-[60px] text-primary-light text-[14px] font-medium">
            <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" />
            <span>جاري التحميل…</span>
          </div>
        ) : !detail ? (
          <p className="m-6 text-[13px] text-red-600 bg-red-50 border border-red-200 rounded-[10px] px-4 py-3" role="alert">⚠ {error || 'تعذّر تحميل الحساب.'}</p>
        ) : (
          <div className="p-6 flex flex-col gap-5">
            <div className="flex items-start justify-between gap-4 flex-wrap">
              <div>
                <p className="text-[17px] font-black text-text-dark" dir="ltr">{detail.username}</p>
                <p className="text-[12.5px] text-text-gray" dir="ltr">{detail.email}</p>
                <div className="flex flex-wrap gap-1.5 mt-2 text-[11px]">
                  <span className={`font-bold px-2 py-0.5 rounded-full ${STATUS_BADGE[statusCode] ?? 'bg-gray-100 text-gray-600'}`}>{STATUS_AR[statusCode] ?? statusCode ?? '—'}</span>
                  {detail.student_id && <span className="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-bold">مرتبط بطالب #{detail.student_id}</span>}
                  {detail.employee_id && <span className="px-2 py-0.5 rounded-full bg-violet-50 text-violet-700 font-bold">مرتبط بموظف #{detail.employee_id}</span>}
                  {detail.is_current_user && <span className="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-bold">حسابك الحالي</span>}
                </div>
              </div>
              {capabilities.can_change_status && (statusCode === 'active' ? (
                <button type="button" disabled={busy}
                  onClick={() => run(() => updateAccountStatus(detail.user_id, 'disabled'), `هل تريد تعطيل الحساب "${detail.username}"؟ سيتم إنهاء جلساته الحالية.`)}
                  className="flex items-center gap-2 px-4 py-2 border border-red-200 bg-red-50 text-red-600 rounded-[10px] text-[13px] font-bold hover:bg-red-100 disabled:opacity-50 transition-colors">
                  <FaToggleOff /> تعطيل الحساب
                </button>
              ) : (
                <button type="button" disabled={busy}
                  onClick={() => run(() => updateAccountStatus(detail.user_id, 'active'))}
                  className="flex items-center gap-2 px-4 py-2 border border-primary/25 bg-primary/8 text-primary-dark rounded-[10px] text-[13px] font-bold hover:bg-primary/15 disabled:opacity-50 transition-colors">
                  <FaToggleOn /> تفعيل الحساب
                </button>
              ))}
            </div>

            {!capabilities.can_manage && capabilities.restriction && (
              <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-[12px] px-4 py-3 text-[12.5px] text-amber-800">
                <FaLock className="mt-1 flex-shrink-0" />
                <span>{RESTRICTION_AR[capabilities.restriction] ?? 'لا يمكنك تعديل هذا الحساب.'}</span>
              </div>
            )}
            {notice && <p className="text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px] px-4 py-2.5" role="status">✓ {notice}</p>}
            {error && <p className="text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px] px-4 py-2.5" role="alert">⚠ {error}</p>}

            <section>
              <h4 className="text-[14px] font-extrabold text-text-dark mb-2">الأدوار المسندة</h4>
              {active.length === 0 ? <p className="text-[12.5px] text-text-light">لا توجد أدوار مفعّلة لهذا الحساب.</p> : (
                <ul className="flex flex-col gap-2">
                  {active.map(row => {
                    const option = roleById.get(row.role_id)
                    const canRevoke = capabilities.can_manage && (option?.assignable || option?.restriction === 'role_inactive')
                    return (
                      <li key={row.user_role_id} className="flex items-center justify-between gap-3 border border-primary/12 rounded-[12px] px-4 py-2.5 flex-wrap">
                        <div>
                          <p className="text-[13px] font-bold text-text-dark">{row.role_name} <span className="text-[11px] font-mono text-text-light" dir="ltr">({row.role_code})</span></p>
                          <p className="text-[11px] text-text-light">أُسند بواسطة {row.assigned_by ?? 'غير مسجّل'} — {formatDate(row.assigned_at)}{row.role_is_active ? '' : ' — الدور غير مفعّل على مستوى النظام'}</p>
                        </div>
                        {canRevoke && (
                          <button type="button" disabled={busy}
                            onClick={() => run(() => revokeAccountRole(detail.user_id, row.role_id), `هل تريد سحب دور "${row.role_name}" من الحساب؟`)}
                            className="flex items-center gap-1.5 px-3 py-1.5 border border-red-200 bg-red-50 text-red-600 rounded-[9px] text-[12px] font-bold hover:bg-red-100 disabled:opacity-50 transition-colors">
                            <FaMinusCircle /> سحب الدور
                          </button>
                        )}
                      </li>
                    )
                  })}
                </ul>
              )}

              {capabilities.can_manage && (
                <div className="flex items-center gap-2 mt-3 flex-wrap">
                  <select value={roleToAssign} onChange={e => setRoleToAssign(e.target.value)} disabled={busy || candidates.length === 0}
                    className="flex-1 min-w-[200px] py-2 px-3 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none focus:border-primary">
                    <option value="">{candidates.length === 0 ? 'لا توجد أدوار إضافية متاحة لك' : 'اختر دورًا لإسناده'}</option>
                    {candidates.map(role => <option key={role.role_id} value={role.role_id}>{role.role_name}</option>)}
                  </select>
                  <button type="button" disabled={busy || !roleToAssign}
                    onClick={() => run(() => assignAccountRole(detail.user_id, Number(roleToAssign)))}
                    className="flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors">
                    {busy ? <FaSpinner className="animate-spin text-[11px]" /> : <FaPlus className="text-[11px]" />} إسناد الدور
                  </button>
                </div>
              )}

              {revoked.length > 0 && (
                <details className="mt-3">
                  <summary className="text-[12px] text-text-light cursor-pointer">أدوار مسحوبة سابقًا ({revoked.length})</summary>
                  <ul className="mt-2 flex flex-col gap-1">
                    {revoked.map(row => (
                      <li key={row.user_role_id} className="text-[12px] text-text-light">{row.role_name} — آخر إسناد: {row.assigned_by ?? 'غير مسجّل'}، {formatDate(row.assigned_at)}</li>
                    ))}
                  </ul>
                </details>
              )}
            </section>

            <section>
              <h4 className="text-[14px] font-extrabold text-text-dark mb-2">الصلاحيات الفعلية</h4>
              <div className="flex items-start gap-2 bg-primary/5 border border-primary/15 rounded-[12px] px-4 py-2.5 text-[12px] text-text-gray mb-3">
                <FaInfoCircle className="text-primary mt-0.5 flex-shrink-0" />
                <span>الصلاحيات تُكتسب من الأدوار المسندة فقط، وتُحسب على الخادم؛ لا يمكن منح صلاحية مباشرة من هذه الصفحة.</span>
              </div>
              {detail.super_admin_bypass && (
                <p className="text-[12px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-4 py-2 mb-3">هذا الحساب يحمل دور super_admin، ويُعامل على الخادم كمن يملك جميع الصلاحيات.</p>
              )}
              {permissionGroups.length === 0 ? <p className="text-[12.5px] text-text-light">لا توجد صلاحيات ناتجة عن أدوار مفعّلة.</p> : (
                <div className="flex flex-col gap-3">
                  {permissionGroups.map(group => (
                    <div key={group.role_code} className="border border-primary/12 rounded-[12px] px-4 py-3">
                      <p className="text-[12.5px] font-bold text-primary-dark mb-2">من دور: {group.role_name}</p>
                      <div className="flex flex-wrap gap-1.5">
                        {group.permissions.map(permission => (
                          <span key={permission.permission_code} title={permission.permission_name} className="px-2 py-[2px] bg-slate-50 border border-slate-200 rounded-[7px] text-[11.5px] text-text-gray font-mono" dir="ltr">{permission.permission_code}</span>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </section>
          </div>
        )}
      </motion.div>
    </div>
  )
}
