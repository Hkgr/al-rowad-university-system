import { hasRole, ROLES } from '../../auth/auth'

export function hasAssignedPermission(permission, user) {
  return user?.permissions?.includes(permission) ?? false
}

export function canViewSupplementaryExamOfferings(user) {
  return hasRole(ROLES.dean, user)
    && hasAssignedPermission('supplementary_exams.offerings.view', user)
}

export function canManageSupplementaryExamOfferings(user) {
  return hasRole(ROLES.dean, user)
    && hasAssignedPermission('supplementary_exams.offerings.manage', user)
}

export function semesterOrderLabel(order) {
  if (Number(order) === 1) return 'الفصل الأول'
  if (Number(order) === 2) return 'الفصل الثاني'
  if (Number(order) === 3) return 'الفصل الثالث'
  return `الفصل ${order ?? '—'}`
}

export function sourceSemestersLabel(sources) {
  const labels = []
  const seen = new Set()
  for (const source of Array.isArray(sources) ? sources : []) {
    const order = Number(source?.semester_order)
    if (!order || seen.has(order)) continue
    seen.add(order)
    labels.push(semesterOrderLabel(order))
  }
  return labels.join('، ')
}

export function offeringErrorMessage(error) {
  const code = error?.errorCode
  if (code === 'supplementary_exam_offering_schema_not_ready') {
    return 'حوكمة طرح مواد الامتحانات التكميلية غير جاهزة على قاعدة البيانات.'
  }
  if (code === 'supplementary_exam_period_not_manageable') {
    return 'لا يمكن إدارة طرح المواد إلا لدورة تكميلية معلنة وغير تراثية.'
  }
  if (code === 'supplementary_exam_unsupported_semester_policy') {
    return 'ترتيب الفصل الدراسي لهذه الدورة التكميلية غير مدعوم.'
  }
  if (code === 'supplementary_exam_program_out_of_scope') {
    return 'هذا البرنامج خارج نطاق صلاحية العميد.'
  }
  if (code === 'supplementary_exam_no_actual_source_offering') {
    return 'لا توجد مواد مستوفية لشروط الطرح التكميلي لهذا البرنامج ضمن نطاق الدورة المحددة.'
  }
  if (code === 'supplementary_exam_offering_exists') {
    return 'هذه المادة مطروحة مسبقًا. استخدم إعادة الفتح إذا كانت مغلقة.'
  }
  if (code === 'supplementary_exam_offering_not_open') {
    return 'لا يمكن إغلاق طرح تكميلي غير مفتوح.'
  }
  if (code === 'supplementary_exam_offering_not_closed') {
    return 'لا يمكن إعادة فتح طرح تكميلي غير مغلق.'
  }
  if (code === 'supplementary_exam_source_stale') {
    return 'لم تعد مصادر الطرح التكميلي مستوفية لشروط الإثبات الأكاديمي.'
  }
  return error?.message || 'تعذّر إكمال العملية.'
}

export function isSummerPeriod(period) {
  return Number(period?.semester_order) === 3
}
