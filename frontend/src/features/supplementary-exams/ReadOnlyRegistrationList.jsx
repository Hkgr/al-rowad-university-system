import { useState } from 'react'
import apiClient from '../../services/apiClient'
export default function ReadOnlyRegistrationList({ title='قائمة التسجيل التكميلي' }){
 const [period,setPeriod]=useState(''),[result,setResult]=useState(null)
 const load=()=>apiClient.get(`/v1/supplementary-exam-periods/${period}/registrations`).then(r=>setResult(r.data))
 return <section dir="rtl" className="bg-white border rounded-xl p-5 my-4"><h2 className="font-bold text-lg">{title}</h2><div className="flex gap-2 my-3"><input className="border rounded p-2" value={period} onChange={e=>setPeriod(e.target.value)} placeholder="رقم الدورة"/><button disabled={!period} onClick={load}>عرض</button></div>{result&&<><p className="font-bold">{result.list_status==='fixed'?'القائمة النهائية':'قائمة أولية'} — {result.period_status}</p><table className="w-full mt-3"><thead><tr><th>الطالب</th><th>المادة</th><th>البرنامج</th><th>سبب الأهلية</th></tr></thead><tbody>{(result.data??[]).map(x=><tr className="border-t" key={x.supplementary_exam_registration_id}><td>{x.student?.student_number}</td><td>{x.offering?.course?.course_name}</td><td>{x.offering?.academic_program?.program_name}</td><td>{x.eligibility_reason}</td></tr>)}</tbody></table></>}</section>
}
