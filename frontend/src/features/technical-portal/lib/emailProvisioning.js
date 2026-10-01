export const operationLabels = {
  prepared: 'عملية مجهزة — لم تبدأ الكتابة', preflight: 'جارٍ التحقق من العنوان', in_progress: 'عملية قيد التنفيذ',
  uncertain: 'نتيجة غير مؤكدة — يلزم التحقق', confirmed: 'عملية مؤكدة', conflict: 'تعارض عنوان — لا يتم تبنيه', failed: 'فشل قبل الكتابة',
  cancelled: 'أُلغيت قبل الكتابة — بياناتها غير صالحة',
}
export const kindLabels = { create: 'إنشاء صندوق', reset: 'إعادة إصدار أولية محدودة', password_reset: 'إعادة تعيين كلمة المرور', suspend: 'إيقاف الحساب', activate: 'تفعيل الحساب', link: 'ربط حساب سابق' }
export function canCancelOperation(operation, { mayCreate, mayReset, mayGeneralReset, maySuspend, mayActivate, mayLink }) {
  return operation?.can_cancel === true && !operation.write_started_at
    && ['prepared', 'failed', 'preflight', 'conflict'].includes(operation.status)
    && ({ create: mayCreate, reset: mayReset, password_reset: mayGeneralReset, suspend: maySuspend, activate: mayActivate, link: mayLink }[operation.kind] === true)
}
export function credentialVisible(state, credentials) {
  return !!credentials && (credentials.kind !== 'password_reset' || printableCredentials(state, credentials))
}
export function usageLabel(bytes) {
  return bytes === null || bytes === undefined ? 'غير متاح' : `${(bytes / 1048576).toLocaleString('ar-SY', { maximumFractionDigits: 2 })} MiB`
}
export function printableCredentials(state, credentials) {
  if (!state || !credentials || state.provisioning_status !== 'created' || state.credential_operation_id !== credentials.operation_id) return false
  if (state.operations.some(op => op.status !== 'confirmed' && ['prepared', 'preflight', 'in_progress', 'uncertain'].includes(op.status))) return false
  return state.operations.some(op => op.operation_id === credentials.operation_id && op.status === 'confirmed' && op.generation === credentials.generation)
}
export function provisioningFailure(error) {
  if ([401, 403].includes(error?.status)) return 'انتهت الصلاحية؛ أُخفيت بيانات الدخول.'
  if (error?.status === 422) return 'تحقق من التأكيد وبيانات العملية؛ لم يُرسل طلب إنشاء صالح.'
  if (error?.status === 503) return 'إنشاء البريد غير جاهز أو معطّل في إعدادات الخادم.'
  return 'تعذر تأكيد العملية. قد تكون نجحت على خادم البريد؛ راجع الحالة الرسمية ولا تُعد إرسال الكتابة تلقائيًا.'
}
