import { apiDownload, apiRequest } from '../../../services/apiClient'

export function createMonthlyApi(office) {
  const root = office === 'administrative' ? '/v1/vice-presidency/administrative' : '/v1/owner'
  const read = (path, query = {}, signal) => { const params = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v !== null && v !== undefined)); return apiRequest(`${root}/payroll/${path}${params.size ? `?${params}` : ''}`, { signal }) }
  const write = (path, data) => apiRequest(`${root}/payroll/${path}`, { method: 'POST', body: JSON.stringify(data) })
  return { read, write, download: (kind, query) => { const p = new URLSearchParams(); for (const [key, value] of Object.entries(query)) { if (Array.isArray(value)) value.forEach((v, i) => p.set(`${key}[${i}]`, v)); else if (value !== '' && value != null) p.set(key, value) }; return apiDownload(`${root}/payroll/months/export/${kind}?${p}`) } }
}

export const FINANCE = Object.freeze({ amounts: 'owner_payroll.amounts.edit', periods: 'owner_payroll.periods.manage', correct: 'owner_payroll.periods.correct', payments: 'owner_payroll.payments.manage', export: 'owner_payroll.export', config: 'owner_payroll.config.manage' })
export const accountingPath = (office, employee) => `${office === 'administrative' ? '/vp/administrative' : '/owner'}/payroll${employee ? `/workers/${encodeURIComponent(employee)}` : ''}`
export function accountingError(error) { if (error.status === 403) return 'لا تملك الصلاحية المالية أو النطاق المطلوب.'; if (error.status === 409) return error.message || 'تغيرت البيانات؛ احتفظ بالمسودة وراجع الحالة الحالية.'; if (error.status === 422 || error.status === 503) return error.message || 'راجع المدخلات أو جاهزية المحاسبة.'; return 'تعذر التأكد من نتيجة العملية. لم نكرر الطلب؛ تحقق من النتيجة الحالية صراحة.' }
