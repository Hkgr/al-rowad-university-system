import DashboardLayout from '../../components/layout/DashboardLayout'
import { getIdentity } from '../auth/auth'
import { GUIDES } from './content/index.js'
import { guideTitle } from './guideModel'

// For sidebars shared by several route groups: the portal title follows the viewer's access.
export default function GuideLayout({ nav, guideId }) {
  return <DashboardLayout nav={nav} appTitle={guideTitle(GUIDES[guideId], getIdentity())} />
}
