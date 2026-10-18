import { classificationLabel, courseAssociations } from './associations'

export default function CourseAssociations({ course, kind }) {
  const associations = courseAssociations(course)
  return <section className="space-y-3 text-[13px]" aria-label="تفاصيل الارتباطات">
    <h3 className="font-bold">{course.course_name} <span dir="ltr">({course.course_code})</span></h3>
    <p className="text-text-light">الارتباطات المعروضة ضمن نطاق صلاحيتك. تصنيف المادة قد يختلف بين البرامج.</p>
    {kind === 'instructors' ? <><p>مدرّسو دليل المادة؛ لا تمثل هذه القائمة التكليفات الفعلية لفصل دراسي.</p>
      {associations.instructors.map(i => <div key={i.faculty_member_id} className="rounded-[10px] border border-primary/15 p-3"><b>{[i.first_name, i.last_name].filter(Boolean).join(' ') || 'الاسم غير متاح'}</b><p>{[...new Set(i.links.map(l => `${l.primary ? 'أساسي' : 'مساند'} — ${l.active ? 'ارتباط فعّال' : 'ارتباط غير فعّال'}`))].join('، ')}</p></div>)}
      {!associations.instructors.length && <p>لا يوجد مدرسون مرتبطون بدليل المادة.</p>}</>
      : <>{associations[kind].map(item => <div className="rounded-[10px] border border-primary/15 p-3" key={item.college_id && kind === 'colleges' ? item.college_id : item.department_id}>
        <b>{kind === 'colleges' ? item.college_name : `${item.college?.college_name || ''} — ${item.department_name}`}</b>
        {(course.program_courses || []).filter(pc => String(kind === 'colleges' ? pc.academic_program?.department?.college_id : pc.academic_program?.department?.department_id) === String(kind === 'colleges' ? item.college_id : item.department_id)).map(pc => <p key={pc.program_course_id} className="mt-2">{pc.academic_program?.department?.department_name} / {pc.academic_program?.program_name}: {classificationLabel(pc)}{!pc.is_active && ' (ارتباط غير فعّال)'}</p>)}
      </div>)}{!associations[kind].length && <p>لا توجد ارتباطات معروضة.</p>}<p className="text-text-light">ارتباط أصل المادة بقسم لا يمنحها تصنيفًا أكاديميًا تلقائيًا؛ التصنيفات أعلاه مأخوذة من البرامج فقط.</p></>}
  </section>
}
