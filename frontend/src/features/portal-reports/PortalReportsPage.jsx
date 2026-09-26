import { Link, useSearchParams } from 'react-router-dom'
import { apiRequest } from '../../services/apiClient'
import { getIdentity } from '../auth/auth'
import { PageHeader, Section, Notice, StatePanel, FilterSelect, MiniTable, InfoGrid } from '../ministry-portal/components/MinistryUi'
import { useMinistryResource } from '../ministry-portal/lib/useMinistryResource'
import { formatDateTime, formatNumber } from '../ministry-portal/lib/ministryState'
import DataTable from '../../components/table/DataTable'
import AcademicRequirementProgress from '../../components/academic/AcademicRequirementProgress'
import { reportEndpoint, reportQuery, changeReportFilter, reportLabel, reportDetailLink } from './reports'

export default function PortalReportsPage({ portal }) {
  const [params,setParams]=useSearchParams(), identity=JSON.stringify(getIdentity()), endpoint=reportEndpoint(portal)
  const options=useMinistryResource(()=>apiRequest(endpoint),[endpoint,identity])
  const definitions=options.data?.data, report=params.get('report')||definitions?.reports[0]?.id
  const definition=definitions?.reports.find(d=>d.id===report), query=reportQuery(params,definition)
  const request=useMinistryResource(()=>report?apiRequest(`${endpoint}/${report}?${query}`):Promise.resolve(null),[endpoint,report,query,identity])
  const change=(key,value)=>setParams(changeReportFilter(params,key,value))
  const response=request.data?.data
  const failure=options.error?options:request.error?request:null
  return <div dir="rtl"><PageHeader title="التقارير" subtitle="اختر تقريرًا جاهزًا. الأرقام محسوبة في الخادم ضمن صلاحيتك؛ لا توجد إجراءات تعديل هنا."/>
    {options.loading?<StatePanel state="loading"/>:options.error?<StatePanel state={options.error.status===403?'forbidden':'error'} error={options.error} onRetry={options.reload}/>:<>
      <Section title="ماذا تريد أن تعرف؟"><div className="flex flex-wrap gap-3">{definitions.reports.map(d=><button key={d.id} type="button" aria-pressed={report===d.id} onClick={()=>{if(report!==d.id)change('report',d.id)}} className={`rounded-[12px] border p-3 text-[13px] font-bold ${report===d.id?'bg-primary/10 border-primary text-primary-dark':'bg-white border-primary/20'}`}>{d.title}</button>)}</div></Section>
      <Section title={definition?.title||'التقرير'} subtitle={definition?.definition}>
        <div className="flex flex-wrap gap-3 items-end mb-4">
          {definition?.scoped&&<><FilterSelect label="الكلية" value={params.get('college_id')||''} onChange={v=>change('college_id',v)} options={[...new Map(definitions.programs.map(p=>[p.college_id,{value:p.college_id,label:p.college_name}])).values()]}/><FilterSelect label="البرنامج" value={params.get('program_id')||''} onChange={v=>change('program_id',v)} options={definitions.programs.filter(p=>!params.get('college_id')||String(p.college_id)===params.get('college_id')).map(p=>({value:p.id,label:p.name}))}/></>}
          {definition?.period&&<><FilterSelect label="السنة الأكاديمية" value={params.get('academic_year_id')||''} onChange={v=>change('academic_year_id',v)} options={definitions.years.map(y=>({value:y.id,label:y.name}))}/><FilterSelect label="الفصل" value={params.get('semester_id')||''} onChange={v=>{if(params.get('academic_year_id'))change('semester_id',v)}} options={params.get('academic_year_id')?definitions.semesters.map(s=>({value:s.id,label:s.name})):[]}/></>}
          {!['academic','trends'].includes(report)&&<label className="text-[12px]">بحث في تسمية السجل<input className="block border border-primary/20 rounded-[10px] p-2" value={params.get('search')||''} onChange={e=>change('search',e.target.value)}/></label>}
          <button className="border border-primary/20 rounded-[10px] p-2 text-[12px]" onClick={()=>setParams(new URLSearchParams({report}))}>مسح المرشحات</button>
          <button className="border border-primary/20 rounded-[10px] p-2 text-[12px]" onClick={request.reload}>تحديث البيانات</button>
        </div>
        {request.loading?<StatePanel state="loading"/>:failure?<StatePanel state={failure.error.status===403?'forbidden':'error'} error={failure.error} onRetry={failure.reload}/>:!response?null:!response.available?<Notice tone="warning">غير متاح: {response.reason}</Notice>:<>
          <Notice>{response.scope} — {response.period.label}{params.get('academic_year_id')?' — '+definitions.years.find(y=>String(y.id)===params.get('academic_year_id'))?.name:''}{params.get('semester_id')?' — '+definitions.semesters.find(s=>String(s.id)===params.get('semester_id'))?.name:''} — وقت الحساب: {formatDateTime(response.generated_at)}</Notice>
          {response.academic?<PersonalAcademic data={response.academic}/>:response.trends?<div className="grid gap-4">{[['intake_by_year','الالتحاق حسب السنة'],['graduates_by_year','التخرج حسب السنة'],['official_results_by_term','النتائج الرسمية حسب الفصل']].map(([key,title])=><Section key={key} title={title}><MiniTable rows={response.trends[key]} rowKey={(r,i)=>r.key??i} columns={[{key:'label',header:'الفترة',render:r=>r.label},{key:'total',header:'عدد السجلات',render:r=>r.total==null?'غير متاح':formatNumber(r.total)}]}/></Section>)}{response.unavailable.map(r=><Notice key={r.code}>{r.label}: {r.reason}</Notice>)}</div>:<>
            <p className="my-4 text-[15px] font-bold">إجمالي القائمة المعروضة: <a href="#report-rows" className="text-primary-dark underline">{formatNumber(response.total)}</a></p>
            <MiniTable rows={response.groups} rowKey={r=>r.category??'unspecified'} columns={[{key:'category',header:'الحالة',render:r=>reportLabel(r.category)},{key:'total',header:'عدد السجلات',render:r=>r.category==null?formatNumber(r.total):<button className="text-primary-dark underline" onClick={()=>change('category',r.category)}>{formatNumber(r.total)}</button>}]} />
            {params.get('category')&&<p className="my-3">المجموعة: {reportLabel(params.get('category'))} <button className="underline text-primary-dark" onClick={()=>change('category','')}>عرض الكل</button></p>}
            <div id="report-rows" className="mt-5"><DataTable rows={response.rows} rowKey={r=>`${r.id}-${r.component_type||''}`} columns={[
              {key:'id',header:'السجل',render:r=>reportDetailLink(portal,report,r.id)?<Link className="underline text-primary-dark" to={reportDetailLink(portal,report,r.id)}>{r.id}</Link>:r.id},
              {key:'label',header:'التسمية / الرمز',render:r=>reportLabel(r.label)}, {key:'category',header:'الحالة',render:r=>reportLabel(r.category)},
              ...(report==='parts'?[{key:'component_type',header:'الجزء',render:r=>reportLabel(r.component_type)}]:[]),
              ...(report==='results'?[{key:'value',header:'العلامة الرسمية',render:r=>r.value??'غير متاح'}]:[]),
              ...(['activity','sessions','attendance'].includes(report)?[{key:'occurred_at',header:'التاريخ المسجل',render:r=>r.occurred_at}]:[]),
            ]} page={response.meta.current_page} totalPages={response.meta.last_page} onPageChange={v=>change('page',v)} emptyTitle="لا توجد سجلات ضمن المرشحات"/></div>
            <p className="text-[11px] text-text-light mt-3">المصدر: {response.source}</p>
          </>}
        </>}
      </Section>
    </>}
  </div>
}
function PersonalAcademic({data}) {
  const s=data.transcript.summary
  return <><InfoGrid items={Object.entries(s).filter(([,v])=>v===null||typeof v!=='object').map(([k,v])=>[({cgpa:'المعدل التراكمي الرسمي',total_passed_credit_hours:'الساعات المكتسبة',total_attempted_credit_hours:'الساعات المحاولة',total_failed_credit_hours:'ساعات المقررات الراسبة',passed_courses_count:'المقررات الناجحة',approved_courses_count:'المقررات المعتمدة',failed_courses_count:'المقررات الراسبة',deprived_courses_count:'المقررات المحروم منها'})[k]||k,v??'غير متاح'])}/>
    <Link to="/student/transcript" className="text-primary-dark underline">عرض الكشف الرسمي</Link>
    {data.requirements.status==='available'?<AcademicRequirementProgress progress={data.requirements.progress} eligibility={data.requirements.graduation_eligibility} selfView/>:<Notice tone="warning">التقدم غير متاح بسبب إعداد أكاديمي يحتاج مراجعة؛ الكشف مستقل عنه.</Notice>}
  </>
}
