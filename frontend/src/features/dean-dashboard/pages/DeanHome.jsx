import { getIdentity } from '../../auth/auth'
import DeanPlaceholder from '../components/DeanPlaceholder'
import { deanHomeWelcome, deanPortalTitle } from '../utils/deanPortalCopy'

export default function DeanHome() {
  const identity = getIdentity()

  return (
    <DeanPlaceholder
      title={deanPortalTitle(identity)}
      description={`${deanHomeWelcome(identity)}. ستتوفر لوحة المتابعة ومحتويات البوابة في مرحلة لاحقة.`}
    />
  )
}
