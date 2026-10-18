// Registry of guide definitions keyed by guide id (see guideAccess.js GUIDE_PATHS).
import student from './student.js'
import professor from './professor.js'
import studentAffairs from './studentAffairs.js'
import examBoard from './examBoard.js'
import dean from './dean.js'
import { scientific, administrative } from './vicePresidency.js'
import { hr, academicStructure, technical } from './staff.js'
import ministry from './ministry.js'

export const GUIDES = Object.freeze({
  student,
  professor,
  studentAffairs,
  examBoard,
  dean,
  hr,
  academicStructure,
  vpScientific: scientific,
  vpAdministrative: administrative,
  technical,
  ministry,
})
