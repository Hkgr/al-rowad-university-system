const TYPE_LABELS = {
  mandatory: 'إجباري',
  elective: 'اختياري',
}

const SCOPE_LABELS = {
  university: 'متطلب جامعة',
  college: 'متطلب كلية',
  department: 'متطلب قسم',
}

const TYPE_CLASS = {
  mandatory: 'bg-primary/10 text-primary-dark border-primary/25',
  elective: 'bg-slate-100 text-slate-700 border-slate-200',
}

function normalize(value) {
  return String(value || '').trim().toLowerCase()
}

export function pickRequirementClassification(entity) {
  if (!entity || typeof entity !== 'object') return null
  return entity.requirement_classification
    || entity.course?.requirement_classification
    || entity.course_offering?.requirement_classification
    || entity.offering?.requirement_classification
    || null
}

export function typeLabel(type) {
  const key = normalize(type)
  return TYPE_LABELS[key] || null
}

export function scopeLabel(scope) {
  const key = normalize(scope)
  return SCOPE_LABELS[key] || null
}

export function classificationPlainText(classification) {
  if (!classification || typeof classification !== 'object') return ''
  const status = normalize(classification.status)
  if (status === 'outside_current_curriculum') return 'خارج الخطة الحالية'
  if (status === 'requirement_mapping_missing') return 'غير مصنف أكاديمياً'
  if (status === 'not_linked_to_program') return 'غير مرتبط بخطة'

  const parts = [typeLabel(classification.requirement_type), scopeLabel(classification.requirement_scope)].filter(Boolean)
  if (parts.length > 0) return parts.join(' · ')
  if (status === 'requirement_configuration_invalid') return 'غير مصنف أكاديمياً'
  return ''
}

export function classificationOptionSuffix(classification) {
  const text = classificationPlainText(classification)
  return text ? ` — ${text}` : ''
}

function Badge({ children, className }) {
  return (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[10.5px] font-bold border whitespace-nowrap ${className}`}>
      {children}
    </span>
  )
}

export default function CourseRequirementBadges({
  classification,
  compact = false,
  showGroupName = false,
}) {
  const data = classification && typeof classification === 'object' ? classification : null
  if (!data) return null

  const status = normalize(data.status)
  const type = typeLabel(data.requirement_type)
  const scope = scopeLabel(data.requirement_scope)
  const gap = compact ? 'gap-1' : 'gap-1.5'

  if (status === 'outside_current_curriculum') {
    return (
      <span className={`inline-flex flex-wrap items-center ${gap}`} dir="rtl">
        <Badge className="bg-amber-50 text-amber-800 border-amber-200">خارج الخطة الحالية</Badge>
      </span>
    )
  }

  if (status === 'requirement_mapping_missing' || (status === 'requirement_configuration_invalid' && !type && !scope)) {
    return (
      <span className={`inline-flex flex-wrap items-center ${gap}`} dir="rtl">
        <Badge className="bg-amber-50 text-amber-900 border-amber-200">⚠ غير مصنف أكاديمياً</Badge>
      </span>
    )
  }

  if (status === 'not_linked_to_program' && !type && !scope) {
    return (
      <span className={`inline-flex flex-wrap items-center ${gap}`} dir="rtl">
        <Badge className="bg-gray-100 text-text-dark border-gray-200">غير مرتبط بخطة</Badge>
      </span>
    )
  }

  if (!type && !scope) return null

  return (
    <span className={`inline-flex flex-wrap items-center ${gap}`} dir="rtl">
      {type ? (
        <Badge className={TYPE_CLASS[normalize(data.requirement_type)] || TYPE_CLASS.mandatory}>
          {type}
        </Badge>
      ) : null}
      {scope ? (
        <Badge className="bg-white text-text-dark border-primary/30">
          {scope}
        </Badge>
      ) : null}
      {status === 'requirement_configuration_invalid' ? (
        <Badge className="bg-amber-50 text-amber-900 border-amber-200">⚠ غير مصنف أكاديمياً</Badge>
      ) : null}
      {showGroupName && data.group_name ? (
        <span className="text-[10.5px] text-text-light">{data.group_name}</span>
      ) : null}
    </span>
  )
}

export function ProgramRequirementClassifications({ items, compact = true }) {
  if (!Array.isArray(items)) return null
  const list = items.filter(Boolean)
  if (list.length === 0) {
    return (
      <span className="inline-flex" dir="rtl">
        <Badge className="bg-gray-100 text-text-dark border-gray-200">غير مرتبط بخطة</Badge>
      </span>
    )
  }

  return (
    <div className="flex flex-col gap-1 min-w-0" dir="rtl">
      {list.map((item, index) => (
        <div key={`${item.academic_program_id ?? index}-${item.program_course_id ?? index}`} className="flex flex-wrap items-center gap-1.5 min-w-0">
          {item.program_name || item.program_code ? (
            <span className="text-[10.5px] text-text-light truncate max-w-[140px]" title={item.program_name || item.program_code}>
              {item.program_name || item.program_code}
            </span>
          ) : null}
          <CourseRequirementBadges classification={item.requirement_classification} compact={compact} />
        </div>
      ))}
    </div>
  )
}

export function CourseRequirementMeta({ classification, label = 'تصنيف المقرر' }) {
  if (!classification) return null
  return (
    <div dir="rtl">
      <p className="text-[11px] font-bold text-text-light mb-1">{label}</p>
      <CourseRequirementBadges classification={classification} />
    </div>
  )
}
