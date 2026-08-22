import { useEffect, useState } from 'react'
import { apiRequest } from '../../services/apiClient'
export default function ReadOnlyRegistrationList({ title='قائمة التسجيل التكميلي' }){
 const [periods,setPeriods]=useState([]),[period,setPeriod]=useState(''),[result,setResult]=useState(null)
 useEffect(()=>{void apiRequest('/v1/supplementary-exam-registration-periods').then(payload=>setPeriods(payload?.data??[]))},[])
 const load=()=>apiRequest(`/v1/supplementary-exam-periods/${period}/registrations`).then(setResult)
 return <section dir="rtl" className="bg-white border rounded-xl p-5 my-4"><h2 className="font-bold text-lg">{title}</h2><div className="flex gap-2 my-3"><select className="border rounded p-2" value={period} onChange={e=>setPeriod(e.target.value)}><option value="">اختر الدورة</option>{periods.map(p=><option key={p.supplementary_exam_period_id} value={p.supplementary_exam_period_id}>{p.period_name} — {p.status}</option>)}</select><button disabled={!period} onClick={load}>عرض</button></div>{result&&<><p className="font-bold">{result.list_status==='fixed'?'القائمة النهائية':'قائمة أولية'} — {result.period_status}</p><table className="w-full mt-3"><thead><tr><th>الطالب</th><th>المادة</th><th>البرنامج</th><th>سبب الأهلية</th></tr></thead><tbody>{(result.data??[]).map(x=><tr className="border-t" key={x.supplementary_exam_registration_id}><td>{x.student?.student_number}</td><td>{x.offering?.course?.course_name}</td><td>{x.offering?.academic_program?.program_name}</td><td>{x.eligibility_reason}</td></tr>)}</tbody></table></>}</section>
}
