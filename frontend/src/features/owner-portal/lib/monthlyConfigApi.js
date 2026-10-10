/** The existing ColumnsDialog contract, exclusively backed by the selected MONTH. */
export function createMonthlyConfigApi(api, period, session, accessCheck) {
  if (!period || session.period !== period) throw new DOMException('جلسة القالب لا تخص الشهر المطلوب', 'AbortError')
  const pinnedPeriod = session.period
  let unresolved = null
  const check = error => { accessCheck(error); if (session.period !== pinnedPeriod) throw new DOMException('تغير شهر جلسة القالب', 'AbortError') }
  const read = async () => { check(); try { const result = await api.read('months/config', { period: pinnedPeriod }); check(); if (result.data.period !== pinnedPeriod) throw new DOMException('استجابة قالب لشهر مختلف', 'AbortError'); return result } catch (error) { check(error); throw error } }
  const save = async (action, payload, revision) => {
    check()
    if (unresolved) throw { status: 409, message: 'نتيجة حفظ القالب غير مؤكدة؛ تحقق منها قبل أي كتابة جديدة.', pendingRequestId: unresolved }
    const scope = session.scope
    const requestId = crypto.randomUUID()
    try {
      const result = await api.write('months/config/save', { request_id: requestId, period: pinnedPeriod, revision, action, payload, confirmed: scope.confirmed, publish_future: scope.publishFuture, ...(scope.publishFuture ? { future_revision: session.futureRevision } : {}), ...(session.status === 'approved' ? { correction_reason: scope.reason } : {}) })
      check(); return result
    } catch (error) {
      if (!error.status || error.status >= 500) { unresolved = requestId; error.pendingRequestId = requestId }
      check(error); throw error
    }
  }
  return {
    fetchPayrollConfig: read,
    previewPayrollConfig: async payload => { check(); try { const result = await api.write('months/config/preview', { ...payload, period: pinnedPeriod, revision: session.revision }); check(); return result } catch (error) { check(error); throw error } },
    createPayrollColumn: (column, revision) => save('column_create', { column }, revision),
    updatePayrollColumn: (key, column, revision) => save('column_update', { key, column }, revision),
    restorePayrollColumnFormula: (key, revision) => save('restore', { key }, revision),
    deletePayrollColumn: (key, revision, confirmValues) => save('delete', { key, confirm_values: confirmValues }, revision),
    savePayrollLayout: (columns, revision) => save('layout', { columns }, revision),
    savePayrollSettings: (settings, revision) => save('settings', { settings }, revision),
    recoverPayrollConfig: async () => { check(); try { const result = await api.read(`months/results/${unresolved}`); check(); if (result.data.period !== pinnedPeriod) throw new DOMException('نتيجة عملية لشهر مختلف', 'AbortError'); unresolved = null; return result } catch (error) { check(error); throw error } },
  }
}
