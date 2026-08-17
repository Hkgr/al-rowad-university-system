import { FaHome } from 'react-icons/fa'

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
    ],
  },
]
