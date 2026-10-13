// Presentation only. These codes never create organizational units, study types or authority.
export const SCIENTIFIC_PORTAL_TITLE = 'الشؤون العلمية'
export const SCIENTIFIC_HEAD = Object.freeze({ code: '8', title: 'نائب رئيس الجامعة للشؤون العلمية' })

const section = (code, title, servicePaths = []) => Object.freeze({ code, title, servicePaths: Object.freeze(servicePaths) })
const department = (code, title, sections, sharedPaths = []) => Object.freeze({ code, title, sections: Object.freeze(sections), sharedPaths: Object.freeze(sharedPaths) })

export const SCIENTIFIC_DEPARTMENTS = Object.freeze([
  department('81', 'إدارة التعليم الجامعي', [
    section('811', 'الكليات', [
      '/vp/scientific/programs-courses',
      '/vp/scientific/semester-offerings',
      '/vp/scientific/semester-offerings/minimum-enrollment',
      '/vp/scientific/teaching-assignments',
      '/vp/scientific/exceptional-openings',
      '/vp/scientific/supplementary-exams',
    ]),
    section('812', 'المعاهد'),
    section('813', 'المخابر'),
  ], ['/vp/scientific/calendar']),
  department('82', 'إدارة الدراسات العليا والبحث العلمي', [
    section('821', 'الماجستير'),
    section('822', 'الدكتوراه'),
    section('823', 'التعليم المهني'),
  ]),
])

// Use the original authorized navigation items without replacing any access rule or URL.
export function scientificDepartments(items) {
  const byPath = new Map(items.map(item => [item.to, item]))
  return SCIENTIFIC_DEPARTMENTS.map(group => ({ ...group,
    sharedServices: group.sharedPaths.map(path => byPath.get(path)).filter(Boolean),
    sections: group.sections.map(unit => ({ ...unit, services: unit.servicePaths.map(path => byPath.get(path)).filter(Boolean) })),
  }))
}

export function scientificActiveService(pathname, items) {
  return items.find(item => pathname === item.to)
    ?? items.filter(item => pathname.startsWith(`${item.to}/`)).sort((a, b) => b.to.length - a.to.length)[0]
}

export function scientificActiveDepartment(pathname, groups) {
  return groups.find(group => [...group.sharedServices, ...group.sections.flatMap(unit => unit.services)]
    .some(item => pathname === item.to || pathname.startsWith(`${item.to}/`)))?.code ?? null
}

export function partitionScientificSections(sections) {
  const isPortalSection = group => group.items.every(item => item.to === '/vp/scientific' || item.to.startsWith('/vp/scientific/'))
  return {
    items: sections.filter(isPortalSection).flatMap(group => group.items),
    otherSections: sections.filter(group => !isPortalSection(group)),
  }
}
