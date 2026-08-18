import { FaChalkboardTeacher, FaHome, FaUnlock } from 'react-icons/fa'

import { PERMISSIONS } from '../auth/auth'

export const scientificVicePresidentNav = [
  {
    label: 'نيابة الشؤون العلمية',
    items: [
      {
        to: '/vp/scientific',
        Icon: FaHome,
        ar: 'الرئيسية',
        en: 'Home',
        end: true,
        permissions: [PERMISSIONS.vicePresidencyScientificAccess],
      },
      {
        to: '/vp/scientific/teaching-assignments',
        Icon: FaChalkboardTeacher,
        ar: 'تكليفات المدرسين',
        en: 'Teaching assignments',
        permissions: [PERMISSIONS.teachingAssignmentsView],
      },
      {
        to: '/vp/scientific/exceptional-openings',
        Icon: FaUnlock,
        ar: 'الفتح الاستثنائي',
        en: 'Exceptional opening',
        permissions: [PERMISSIONS.exceptionalOpenView],
      },
    ],
  },
]

export const administrativeVicePresidentNav = [
  {
    label: 'نيابة الشؤون الإدارية',
    items: [
      {
        to: '/vp/administrative',
        Icon: FaHome,
        ar: 'الرئيسية',
        en: 'Home',
        end: true,
        permissions: [PERMISSIONS.vicePresidencyAdministrativeAccess],
      },
      {
        to: '/vp/administrative/teaching-assignments',
        Icon: FaChalkboardTeacher,
        ar: 'تكليفات المدرسين',
        en: 'Teaching assignments',
        permissions: [PERMISSIONS.teachingAssignmentsView],
      },
      {
        to: '/vp/administrative/exceptional-openings',
        Icon: FaUnlock,
        ar: 'الفتح الاستثنائي',
        en: 'Exceptional opening',
        permissions: [PERMISSIONS.exceptionalOpenView],
      },
    ],
  },
]
