// Presentation-only classification. Codes are NOT persisted units, counts or authority.
export const ADMINISTRATIVE_PORTAL_TITLE = 'الشؤون الإدارية'
export const ADMINISTRATIVE_HEAD = Object.freeze({ code: '7', title: 'نائب رئيس الجامعة للشؤون الإدارية' })

const office = (code, title, servicePaths = []) => Object.freeze({ code, title, servicePaths: Object.freeze(servicePaths) })
const directorate = (code, title, offices) => Object.freeze({ code, title, offices: Object.freeze(offices) })

export const ADMINISTRATIVE_DIRECTORATES = Object.freeze([
  directorate('71', 'مديرية الشؤون الإدارية', [
    office('711', 'مكتب الموارد البشرية', [
      '/vp/administrative/faculty', '/vp/administrative/deans',
      '/vp/administrative/teaching-assignments', '/vp/administrative/exceptional-openings',
    ]),
    office('712', 'مكتب الديوان والأرشيف'),
    office('713', 'مكتب الرعاية الصحية'),
    office('714', 'مكتب الخدمات الإدارية'),
    office('715', 'المكتب التقني'),
  ]),
  directorate('72', 'مديرية الشؤون المالية', [
    office('721', 'مكتب المحاسبة'), office('722', 'أمين الصندوق'), office('723', 'أمين المستودع'),
  ]),
  directorate('73', 'مديرية شؤون الطلاب', [
    office('731', 'مكتب الإرشاد والتوجيه', ['/vp/administrative/calendar']),
    office('732', 'مكتب القبول والتسجيل'), office('733', 'مكتب الخدمات الطلابية'),
    office('734', 'مكتب المنح والإيفاد والتبادل الطلابي'),
    office('735', 'إدارة الامتحانات'), office('736', 'مكتب التوثيق والتدقيق'),
  ]),
])

// Input is the existing permission-filtered navigation, not a new access decision.
export function administrativeDirectorates(items) {
  const byPath = new Map(items.map(item => [item.to, item]))
  return ADMINISTRATIVE_DIRECTORATES.map(group => ({ ...group,
    offices: group.offices.map(unit => ({ ...unit, services: unit.servicePaths.map(path => byPath.get(path)).filter(Boolean) })),
  }))
}

export function administrativeActiveDirectorate(pathname, groups) {
  return groups.find(group => group.offices.some(unit => unit.services.some(item =>
    pathname === item.to || pathname.startsWith(`${item.to}/`),
  )))?.code ?? null
}

export function isAdministrativePortalPath(path) {
  return path === '/vp/administrative' || path.startsWith('/vp/administrative/')
}

export function partitionAdministrativeSections(sections) {
  const isPortalSection = section => section.items.every(item => isAdministrativePortalPath(item.to))
  return {
    items: sections.filter(isPortalSection).flatMap(section => section.items),
    otherSections: sections.filter(section => !isPortalSection(section)),
  }
}
