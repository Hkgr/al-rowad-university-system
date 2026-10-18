import { Link, useLocation } from 'react-router-dom'
import { administrativeOfficePath } from './administrativeOfficePaths'
export function OfficeLink({ to, ...props }) {
  const { pathname } = useLocation()
  return <Link to={administrativeOfficePath(to, pathname)} {...props} />
}
