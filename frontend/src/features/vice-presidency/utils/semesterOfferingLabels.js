export const semesterOfferingStatusLabel = status => ({
  draft: 'مسودة',
  submitted: 'بانتظار اعتماد النائب العلمي',
  returned: 'معاد للتعديل',
  approved: 'معتمد',
}[status] || 'حالة غير معروفة')

export const courseTypeLabel = type => type === 'mandatory' ? 'إجباري' : type === 'elective' ? 'اختياري' : 'غير محدد'

export const coverageLabel = coverage => {
  if (coverage?.complete === true) return 'التكليف الفعّال مكتمل'
  const missing = coverage?.missing_roles ?? []
  if (missing.includes('theoretical') && missing.includes('practical')) return 'التكليف النظري والعملي غير مكتمل'
  if (missing.includes('theoretical')) return 'التكليف النظري غير مكتمل'
  if (missing.includes('practical')) return 'التكليف العملي غير مكتمل'
  return 'مكونات التدريس غير مكتملة'
}
