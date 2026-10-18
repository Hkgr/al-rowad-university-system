export function reportConfigurationFingerprint(configuration) {
  return JSON.stringify(configuration ?? null)
}

export function draftAfterSuccessfulRequest(currentDraft, submittedDraft, appliedDraft, preserveDraft = false) {
  if (preserveDraft) return currentDraft
  return reportConfigurationFingerprint(currentDraft) === reportConfigurationFingerprint(submittedDraft)
    ? appliedDraft
    : currentDraft
}

export function resetReportLifecycle(initialDraft) {
  return {
    draft: initialDraft,
    applied: null,
    report: null,
    reportError: null,
    fieldErrors: {},
    dimensionLabels: {},
    loading: false,
  }
}

export function purgeReportLifecycle(currentDraft, reportError) {
  const baseline = currentDraft?.comparison?.baseline
  const draft = currentDraft ? {
    ...currentDraft,
    labels: {},
    ...(baseline ? { comparison: { ...currentDraft.comparison, baseline: { ...baseline, labels: {} } } } : {}),
  } : currentDraft
  return {
    draft,
    applied: null,
    report: null,
    reportError,
    fieldErrors: {},
    dimensionLabels: {},
    loading: false,
  }
}
