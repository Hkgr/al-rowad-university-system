/** The existing ColumnsDialog contract, exclusively backed by the selected MONTH. */
export function createMonthlyConfigApi(api, period, session, accessCheck) {
  let unresolved = null
  const read = async () => { try { const result = await api.read('months/config', { period }); accessCheck(); return result } catch (error) { accessCheck(error); throw error } }
  const save = async (action, payload, revision) => {
    if (unresolved) throw { status: 409, message: 'نتيجة حفظ القالب غير مؤكدة؛ تحقق منها قبل أي كتابة جديدة.', pendingRequestId: unresolved }
    const scope = session.scope
    const requestId = crypto.randomUUID()
    try {
      const result = await api.write('months/config/save', { request_id: requestId, period, revision, action, payload, confirmed: scope.confirmed, publish_future: scope.publishFuture, ...(scope.publishFuture ? { future_revision: session.futureRevision } : {}), ...(session.status === 'approved' ? { correction_reason: scope.reason } : {}) })
      accessCheck(); return result
    } catch (error) {
      if (!error.status || error.status >= 500) { unresolved = requestId; error.pendingRequestId = requestId }
      accessCheck(error); throw error
    }
  }
  return {
    fetchPayrollConfig: read,
    previewPayrollConfig: async payload => { try { const result = await api.write('months/config/preview', { ...payload, period, revision: session.revision }); accessCheck(); return result } catch (error) { accessCheck(error); throw error } },
    createPayrollColumn: (column, revision) => save('column_create', { column }, revision),
    updatePayrollColumn: (key, column, revision) => save('column_update', { key, column }, revision),
    restorePayrollColumnFormula: (key, revision) => save('restore', { key }, revision),
    deletePayrollColumn: (key, revision, confirmValues) => save('delete', { key, confirm_values: confirmValues }, revision),
    savePayrollLayout: (columns, revision) => save('layout', { columns }, revision),
    savePayrollSettings: (settings, revision) => save('settings', { settings }, revision),
    recoverPayrollConfig: async () => { try { const result = await api.read(`months/results/${unresolved}`); accessCheck(); unresolved = null; return result } catch (error) { accessCheck(error); throw error } },
  }
}
