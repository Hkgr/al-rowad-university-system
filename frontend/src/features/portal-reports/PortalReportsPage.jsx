import { Link, useSearchParams } from 'react-router-dom'
import { apiRequest } from '../../services/apiClient'
import { getIdentity } from '../auth/auth'
import { PageHeader, Section, Notice, StatePanel, FilterSelect, MiniTable, InfoGrid } from '../ministry-portal/components/MinistryUi'
import { useMinistryResource } from '../ministry-portal/lib/useMinistryResource'
import { formatDateTime, formatNumber } from '../ministry-portal/lib/ministryState'
import DataTable from '../../components/table/DataTable'
import AcademicRequirementProgress from '../../components/academic/AcademicRequirementProgress'
import { reportEndpoint, reportQuery, changeReportFilter, reportLabel, reportDetailLink, reportSourceLabel } from './reports'

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
    {options.loading?<StatePanel state="loading"/>:options.error?<StatePanel state={options.error.status===403?'forbidden':'error'} error={options.error} message={options.error.status===403?'لا يملك حسابك صلاحية هذا التقرير.':undefined} onRetry={options.reload}/>:<>
      <Section title="ماذا تريد أن تعرف؟"><div className="flex flex-wrap gap-2">{definitions.reports.map(d=><button key={d.id} type="button" aria-pressed={report===d.id} onClick={()=>{if(report!==d.id)change('report',d.id)}} className={`max-w-full rounded-[12px] border p-3 text-right text-[13px] font-bold break-words max-[820px]:basis-full ${report===d.id?'bg-primary/10 border-primary text-primary-dark':'bg-white border-primary/20'}`}>{d.title}</button>)}</div></Section>
      <Section title={definition?.title||'التقرير'} subtitle={definition?.definition}>
        <div className="mb-4 flex flex-wrap items-end gap-3">
          {definition?.scoped&&<><FilterSelect label="الكلية" value={params.get('college_id')||''} onChange={v=>change('college_id',v)} options={[...new Map(definitions.programs.map(p=>[p.college_id,{value:p.college_id,label:p.college_name}])).values()]}/><FilterSelect label="البرنامج" value={params.get('program_id')||''} onChange={v=>change('program_id',v)} options={definitions.programs.filter(p=>!params.get('college_id')||String(p.college_id)===params.get('college_id')).map(p=>({value:p.id,label:p.name}))}/></>}
          {definition?.period&&<><FilterSelect label="السنة الأكاديمية" value={params.get('academic_year_id')||''} onChange={v=>change('academic_year_id',v)} options={definitions.years.map(y=>({value:y.id,label:y.name}))}/><FilterSelect label="الفصل" disabled={!params.get('academic_year_id')} placeholder={params.get('academic_year_id')?'الكل':'اختر السنة أولًا'} value={params.get('semester_id')||''} onChange={v=>{if(params.get('academic_year_id'))change('semester_id',v)}} options={params.get('academic_year_id')?definitions.semesters.map(s=>({value:s.id,label:s.name})):[]}/></>}
          {!['academic','trends'].includes(report)&&<label className="flex min-w-0 flex-col gap-1 text-[11.5px] font-bold text-text-light" dir="rtl">بحث في تسمية السجل<input className="w-full max-w-[240px] rounded-[10px] border-[1.5px] border-primary/20 bg-white px-3 py-2 text-[13px] font-normal text-text-dark outline-none focus:border-primary" value={params.get('search')||''} onChange={e=>change('search',e.target.value)}/></label>}
          <button type="button" className="rounded-[10px] border-[1.5px] border-primary/20 bg-white px-3 py-2 text-[13px] font-bold text-primary-dark" onClick={()=>setParams(new URLSearchParams({report}))}>مسح المرشحات</button>
          <button type="button" className="rounded-[10px] border-[1.5px] border-primary/20 bg-white px-3 py-2 text-[13px] font-bold text-primary-dark" onClick={request.reload}>تحديث البيانات</button>
        </div>
        {request.loading?<StatePanel state="loading"/>:failure?<StatePanel state={failure.error.status===403?'forbidden':'error'} error={failure.error} message={failure.error.status===403?'لا يملك حسابك صلاحية هذا التقرير.':undefined} onRetry={failure.reload}/>:!response?null:!response.available?<Notice tone="warning">غير متاح: {response.reason}</Notice>:<>
          <Notice>{response.scope} — {response.period.label}{params.get('academic_year_id')?' — '+definitions.years.find(y=>String(y.id)===params.get('academic_year_id'))?.name:''}{params.get('semester_id')?' — '+definitions.semesters.find(s=>String(s.id)===params.get('semester_id'))?.name:''} — وقت الحساب: {formatDateTime(response.generated_at)} — المصدر: {reportSourceLabel[report]||'مصدر القراءة المعتمد'}</Notice>
          {response.academic?<PersonalAcademic data={response.academic}/>:response.trends?<div className="grid gap-4">{[['intake_by_year','الالتحاق حسب السنة'],['graduates_by_year','التخرج حسب السنة'],['official_results_by_term','النتائج الرسمية حسب الفصل']].map(([key,title])=><Section key={key} title={title}><MiniTable rows={response.trends[key]} rowKey={(r,i)=>r.key??i} columns={[{key:'label',header:'الفترة',render:r=>r.label},{key:'total',header:'عدد السجلات',render:r=>r.total==null?'غير متاح':formatNumber(r.total)}]}/></Section>)}{response.unavailable.map(r=><Notice key={r.code}>{r.label}: {r.reason}</Notice>)}</div>:<>
            <p className="my-4 text-[15px] font-bold">إجمالي السجلات: <a href="#report-rows" className="text-primary-dark underline">{formatNumber(response.total)}</a></p>
            <p className="mb-3 text-[12px] leading-6 text-text-light">{params.get('category')?'هذا الإجمالي هو عدد سجلات المجموعة المختارة فقط، ويطابق رقمها في التوزيع.':'مجموع أرقام الحالات يساوي هذا الإجمالي. اختيار حالة يعرض القائمة نفسها.'}</p>
            <div id="report-groups"><MiniTable rows={response.groups} rowKey={r=>r.category??'unspecified'} columns={[{key:'category',header:'الحالة',render:r=>reportLabel(r.category)},{key:'total',header:'عدد السجلات',render:r=>{const selected=params.get('category')===r.category;return r.category==null?formatNumber(r.total):<button type="button" aria-pressed={selected} className={selected?'rounded-[8px] bg-primary/10 px-2 py-1 font-black text-primary-dark underline':'text-primary-dark underline'} onClick={()=>change('category',r.category)}>{formatNumber(r.total)}</button>}}]} /></div>
            {params.get('category')&&<p className="my-3">المجموعة: {reportLabel(params.get('category'))} <button type="button" className="underline text-primary-dark" onClick={()=>change('category','')}>عرض الكل</button></p>}
            <div id="report-rows" className="mt-5"><DataTable rows={response.rows} rowKey={r=>`${r.id}-${r.component_type||''}`} columns={[
              {key:'id',header:'السجل',render:r=>reportDetailLink(portal,report,r.id)?<Link className="underline text-primary-dark" to={reportDetailLink(portal,report,r.id)}>{r.id}</Link>:r.id},
              {key:'label',header:'التسمية / الرمز',render:r=>reportLabel(r.label)}, {key:'category',header:'الحالة',render:r=>reportLabel(r.category)},
              ...(report==='parts'?[{key:'component_type',header:'الجزء',render:r=>reportLabel(r.component_type)}]:[]),
              ...(report==='results'?[{key:'value',header:'العلامة الرسمية',render:r=>r.value??'غير متاح'}]:[]),
              ...(['activity','sessions','attendance'].includes(report)?[{key:'occurred_at',header:'التاريخ المسجل',render:r=>r.occurred_at}]:[]),
            ]} page={response.meta.current_page} totalPages={response.meta.last_page} onPageChange={v=>change('page',v)} emptyTitle="لا توجد سجلات ضمن المرشحات"/></div>
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
