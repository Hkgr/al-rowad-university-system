export function paginatedRows(response) {
  const payload = response?.data ?? response
  return Array.isArray(payload?.data) ? payload.data : []
}

export function paginationMeta(response) {
  const payload = response?.data ?? response
  return payload?.meta ?? {}
}

export function canImportPreview(preview) {
  return Boolean(preview)
    && Number(preview.invalid_rows) === 0
    && Number(preview.duplicate_rows) === 0
    && Array.isArray(preview.structural_errors)
    && preview.structural_errors.length === 0
}

export function previewStatusLabel(status) {
  if (status === 'valid') return 'صحيح'
  if (status === 'duplicate') return 'مكرر'
  return 'خاطئ'
}

export function buildMinistryPlacementFormData(file, fields = {}) {
  const body = new FormData()
  body.append('file', file)
  Object.entries(fields).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') body.append(key, String(value))
  })
  return body
}
