export const operationLabels = {
  prepared: 'كلمة أولية مجهزة مؤقتًا', preflight: 'جارٍ التحقق من العنوان', in_progress: 'عملية إنشاء قيد التنفيذ',
  uncertain: 'نتيجة غير مؤكدة — يلزم التحقق', confirmed: 'عملية مؤكدة', conflict: 'تعارض عنوان — لا يتم تبنيه', failed: 'فشل قبل الكتابة',
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
