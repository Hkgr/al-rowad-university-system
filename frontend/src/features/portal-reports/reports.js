export const REPORT_PATHS = { president:'/president/reports', ministry:'/ministry/reports', dean:'/dean/reports', 'student-affairs':'/student-affairs/reports', admissions:'/exam-board/registration-reports', 'exam-board':'/exam-board/results', professor:'/professor/reports', student:'/student/reports', hr:'/hr/reports', 'academic-structure':'/academic-structure/reports', technical:'/technical/reports' }
const permissions = { dean:['students.view','teaching_staff.view','courses.view','registration.view','grades.view'], 'student-affairs':['students.view','registration.view','registration_requests.view','academic_progression.view','graduation_decisions.view','admissions.view'], admissions:['admissions.view','registration.view','registration_requests.view'], 'exam-board':['exams.manage','grades.view','courses.view','supplementary_exams.registrations.view'], professor:['grades.manage','attendance.manage'], student:['grades.view','attendance.view','registration.view'], hr:['hr.view'], 'academic-structure':['academic_structure.view'], technical:['user_accounts.view','system_activity.view'] }
export function reportAccess(portal) {
  if (portal==='president') return {allRoles:['university_president'],assignedPermissions:['president_portal.access','president_portal.reports.view'],actualUniversityScope:true}
  if (portal==='ministry') return {anyAccess:['dashboard','students','colleges','courses','faculty'].map(s=>({allRoles:['ministry_observer'],assignedPermissions:['ministry_portal.access',`ministry_portal.${s}.view`]}))}
  const roles={dean:'dean',admissions:'registration_officer','exam-board':'exam_officer',professor:'doctor_instructor',student:'student'}
  return {anyAccess:(permissions[portal]||[]).map(permission=>({assignedPermissions:[permission,...(portal==='technical'?['technical_portal.access']:[])],...(roles[portal]?{allRoles:[roles[portal]]}:{}),...(portal==='student'?{studentIdentity:true}:portal==='professor'?{employeeIdentity:true}:portal==='technical'?{}:portal==='dean'?{actualScopeTypes:['college']}:portal==='hr'?{actualScopeTypes:['college','university']}:{actualAcademicScope:true})}))}
}
export const reportEndpoint = portal => portal==='ministry'?'/v1/ministry/reports':`/v1/portal-reports/${portal}`
export function reportQuery(params, definition) {
  const yearSelected = params.get('academic_year_id')
  return new URLSearchParams([...params].filter(([key,v])=>v!==''&&(['category','search','page','per_page'].includes(key)||(definition?.period&&(key==='academic_year_id'||(key==='semester_id'&&yearSelected)))||(definition?.scoped&&['college_id','program_id'].includes(key))))).toString()
}
export function changeReportFilter(params,key,value) {
  const next=new URLSearchParams(params);next.delete('page')
  if(value==='')next.delete(key);else next.set(key,value)
  if(key==='academic_year_id')next.delete('semester_id')
  if(key==='college_id')next.delete('program_id')
  if(key==='report') for(const k of ['category','search','academic_year_id','semester_id','college_id','program_id'])next.delete(k)
  return next
}
export const reportLabel = value => value==null?'غير محدد':({draft:'مسودة',submitted:'مرسل',approved:'معتمد',returned:'معاد',returned_for_correction:'معاد للتصحيح',registered:'مسجل',completed:'مكتمل',active:'فعال',inactive:'غير فعال',disabled:'معطل',open:'مفتوح',closed:'مغلق',passed:'ناجح',failed:'راسب',deprived:'محروم',graduated:'متخرج',archived:'مؤرشف',pending:'قيد الانتظار',accepted:'مقبول',rejected:'مرفوض',theoretical:'نظري',practical:'عملي',present:'حاضر',absent:'غائب',expired:'منتهٍ',superseded:'مستبدل',frozen:'مجمّد',withdrawn:'منسحب',cancelled:'ملغى',dropped:'مسقط'})[value]??String(value)
export const reportSourceLabel = { students:'الطلاب وحالاتهم الحالية', offerings:'الطروحات الأكاديمية', registrations:'تسجيلات المقررات الحالية', results:'النتائج الرسمية المعتمدة', parts:'أجزاء العلامات المطلوبة', requests:'طلبات التسجيل الأولية', progression:'قرارات الترفيع', graduation:'قرارات التخرج', admissions:'طلبات القبول', deprivation:'حالات الحرمان المسجلة', sessions:'جلسات الحضور المسجلة', attendance:'قيود الحضور المسجلة', programs:'البرامج الأكاديمية الحالية', employees:'الموظفون ووحداتهم الحالية', faculty:'المدرسون وانتماؤهم الحالي', supplementary:'تسجيلات التكميلي', accounts:'حالات الحسابات', activity:'أكواد النشاط المرئية', academic:'الكشف والتقدم الرسميان للحساب', trends:'سلاسل الالتحاق والتخرج والنتائج الرسمية' }

// Only record-detail routes already present in these same authorized shells.
export function reportDetailLink(portal,report,id) {
  if(report==='students' && ['dean','student-affairs','president','ministry'].includes(portal))return `/${portal}/students/${id}`
  if(report==='offerings'&&portal==='dean')return `/dean/courses/${id}`
  if(report==='employees'&&portal==='hr')return `/hr/employees/${id}`
  return null
}
