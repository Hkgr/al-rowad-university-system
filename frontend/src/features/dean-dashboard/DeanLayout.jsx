import DashboardLayout from '../../components/layout/DashboardLayout'
import { getIdentity } from '../auth/auth'
import deanNav from './nav'
import { deanPortalTitle } from './utils/deanPortalCopy'

export default function DeanLayout() {
  return <DashboardLayout nav={deanNav} appTitle={deanPortalTitle(getIdentity())} />
}
