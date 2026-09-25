import { Link, useParams, useSearchParams } from 'react-router-dom'
import { apiRequest } from '../../services/apiClient'
import { canAccess, getIdentity } from '../auth/auth'
import DataTable from '../../components/table/DataTable'
import { PageHeader, Section, StatCard, Notice, StatePanel, FilterSelect, MiniTable, InfoGrid } from '../ministry-portal/components/MinistryUi'
import { DeanAccountState, DeanOverallState, DeanPositionState } from '../ministry-portal/components/DeanStatus'
import { useMinistryResource } from '../ministry-portal/lib/useMinistryResource'
import { INDICATORS, formatNumber, formatDateTime } from '../ministry-portal/lib/ministryState'
import { RESOURCES, FILTERS, LABELS, STATES, presidentAccess, queryFor, changedFilter } from './president'

const get = (path, query = '') => apiRequest(`/v1/president/${path}${query ? '?' + query : ''}`)
const authKey = () => JSON.stringify(getIdentity())
function useRead(path, query = '') { return useMinistryResource(() => get(path, query), [path, query, authKey()]) }
function Load({ request }) { return <StatePanel state={request.loading ? 'loading' : request.error?.status === 403 ? 'forbidden' : 'error'} error={request.error} onRetry={request.reload} /> }
const value = v => v === null || v === undefined ? 'غير محدد' : typeof v === 'boolean' ? (v ? 'نعم' : 'لا') : typeof v === 'object' ? (v.label ?? v.name ?? v.college_name ?? '—') : STATES[v] ?? String(v)
function safeLink(link) { if (!link?.startsWith('/president/')) return null; const resource = link.split('/')[2].split('?')[0]; const section = RESOURCES[resource]?.[1]; return section && canAccess(presidentAccess(section)) ? link : null }

function Filters({ resource, params, setParams, options }) {
  const names = { college_id: 'الكلية', program_id: 'البرنامج', department_id: 'القسم', academic_year_id: 'السنة الأكاديمية', semester_id: 'الفصل', registered_year_id: 'سنة التسجيل', registered_semester_id: 'فصل التسجيل', enrollment_year_id: 'سنة الالتحاق', graduated_year_id: 'سنة التخرج', offered_year_id: 'سنة الطرح', offered_semester_id: 'فصل الطرح', level_id: 'المستوى', status: 'حالة الطالب', state: 'الحالة الإجمالية', active: 'حالة التفعيل' }
  const o = options || {}
  const lists = { college_id: o.colleges, department_id: o.departments, program_id: o.programs, level_id: o.levels,
    active: [{ id: 1, name: 'مفعّل' }, { id: 0, name: 'غير مفعّل' }], state: [{ id: 'current', name: 'حالي' }, { id: 'historical', name: 'سابق / غير حالي' }],
    status: o.student_statuses?.map(s => ({ id: s.code, name: s.name })) }
  return <div className="flex flex-wrap items-end gap-3 mb-5" dir="rtl">
    {(FILTERS[resource] || []).map(key => {
      let rows = lists[key] || (key.includes('year') ? o.academic_years : key.includes('semester') ? o.semesters : []) || []
      if (['program_id','department_id'].includes(key) && params.get('college_id')) rows = rows.filter(r => String(r.college_id) === params.get('college_id'))
      return <FilterSelect key={key} label={names[key]} placeholder={key === 'academic_year_id' && ['dashboard','reports'].includes(resource) ? 'السنة الحالية من النظام' : 'الكل'} value={params.get(key) || ''} onChange={v => setParams(changedFilter(params,key,v))} options={rows.map(r => ({ value: r.id, label: r.name }))} />
    })}
    {!['dashboard','reports'].includes(resource) && <label className="text-[12px] text-text-light">بحث<input className="block border border-primary/20 rounded-[10px] p-2 bg-white" value={params.get('search') || ''} onChange={e => setParams(changedFilter(params,'search',e.target.value), { replace:true })} /></label>}
    <button className="text-[12px] text-primary-dark border border-primary/20 rounded-[10px] p-2" onClick={() => setParams(new URLSearchParams())}>مسح المرشحات</button>
  </div>
}

function Related({ resource }) {
  const groups = { colleges:['programs','courses','deans'], students:['students'], exams:['results'], faculty:['deans'] }
  return <div className="flex flex-wrap gap-4 mb-4 text-[13px] text-primary-dark">{(groups[resource] || []).filter(r => r !== resource && canAccess(presidentAccess(RESOURCES[r][1]))).map(r => <Link key={r} className="hover:underline" to={'/president/'+r}>{RESOURCES[r][0]}</Link>)}</div>
}

export function PresidentList({ resource }) {
  const [params,setParams] = useSearchParams(), config = RESOURCES[resource]
  const query = queryFor(resource,params), request = useRead(resource,query), options = useRead('filters')
  const display = (key, v) => key === 'academic_year_id' ? options.data?.data?.academic_years?.find(y=>y.id===v)?.name ?? value(v) : key === 'semester_id' ? options.data?.data?.semesters?.find(s=>s.id===v)?.name ?? value(v) : value(v)
  const data = request.data, columns = config[3].map(key => ({ key, header:LABELS[key], render:row => Array.isArray(row[key]) ? row[key].map(value).join('، ') : display(key,row[key]) }))
  columns.unshift({ key:'detail', header:'التفاصيل', render:row => <Link className="text-primary-dark font-bold hover:underline" to={`/president/${resource}/${row[config[2]]}`}>عرض التفاصيل</Link> })
  if (resource === 'deans') {
    columns.push({ key:'evidence', header:'الدور ونطاق الكلية', render:row => <DeanAccountState row={row} /> })
  }
  return <><PageHeader title={config[0]} subtitle="بيانات النظام المسجلة — للقراءة فقط" /><Related resource={resource} />
    <Filters resource={resource} params={params} setParams={setParams} options={options.data?.data} />
    {options.error && <Notice tone="warning">تعذر تحميل خيارات المرشحات. <button onClick={options.reload}>إعادة المحاولة</button></Notice>}
    {request.loading || request.error ? <Load request={request} /> : <><p className="text-[12px] mb-3">إجمالي المجموعة: {formatNumber(data.meta.total)}</p><DataTable columns={columns} rows={data.data} rowKey={r => resource === 'deans' ? `${r.person}-${r.college.id}` : r[config[2]]} page={data.meta.current_page} totalPages={data.meta.last_page} onPageChange={p => { const n=new URLSearchParams(params); n.set('page',p);setParams(n) }} emptyTitle="لا توجد بيانات مطابقة" /></>}
  </>
}

/** Explicit display allowlist: no raw JSON, account identifiers, private notes or unknown fields. */
function Details({ data, depth = 0, options }) {
  if (!data || depth > 6) return null
  if (Array.isArray(data)) return <div className="grid gap-3">{data.length ? data.map((r,i) => <div key={r.id ?? i} className="border border-primary/10 rounded-[12px] p-3"><Details data={r} depth={depth+1} options={options} /></div>) : <Notice>لا توجد سجلات.</Notice>}</div>
  if (typeof data !== 'object') return <p>{value(data)}</p>
  const entries = Object.entries(data).filter(([key]) => LABELS[key])
  const display = (key,v) => key === 'academic_year_id' ? options?.academic_years?.find(y=>y.id===v)?.name ?? value(v) : key === 'semester_id' ? options?.semesters?.find(s=>s.id===v)?.name ?? value(v) : value(v)
  return <div className="grid gap-4">
    <InfoGrid items={entries.filter(([,v]) => v === null || typeof v !== 'object').map(([k,v]) => [LABELS[k], display(k,v)])} />
    {data.position_state && <div className="flex flex-wrap gap-4"><DeanOverallState row={data}/><DeanPositionState row={data}/><DeanAccountState row={data}/></div>}
    {entries.filter(([,v]) => v && typeof v === 'object').map(([key,v]) => <Section key={key} title={LABELS[key]}><Details data={v} depth={depth+1} options={options}/></Section>)}
  </div>
}

export function PresidentDetail({ resource }) {
  const { id } = useParams(), request = useRead(`${resource}/${id}`), options = useRead('filters')
  return <><PageHeader title={RESOURCES[resource][0]} back={{ to:'/president/'+resource,label:'العودة إلى القائمة' }}/>
    {request.loading || request.error ? <Load request={request}/> : <Section title="البيانات المسجلة"><Details data={request.data.data} options={options.data?.data}/></Section>}
  </>
}

function Trend({ rows, title, metric = 'total' }) {
  const values = rows.map(r => r[metric]).filter(v => v !== null && v !== undefined), max = Math.max(1,...values)
  return <Section title={title} subtitle="اتجاه تاريخي موثق؛ لا تتأثر السلسلة بمرشح السنة. الجدول يعرض الأرقام نفسها.">
    <div className="flex items-end gap-2 overflow-x-auto h-36 mb-4" aria-hidden="true">{rows.map((r,i)=><div key={r.key ?? i} className="flex-1 min-w-12 text-center text-[10px]" title={`${r.label}: ${value(r[metric])}`}><div className="bg-primary/70 rounded-t mx-auto w-7" style={{height:r[metric] == null ? 0 : `${110*r[metric]/max}px`}}/>{r.label}</div>)}</div>
    <MiniTable rows={rows} rowKey={(r,i)=>r.key ?? i} columns={[{key:'period',header:'الفترة',render:r=>r.label},{key:'value',header:'العدد',render:r=>r[metric] == null ? 'غير متاح' : formatNumber(r[metric])}]} />
  </Section>
}

export function PresidentHome({ reports = false }) {
  const resource = reports ? 'reports' : 'dashboard', [params,setParams] = useSearchParams(), options=useRead('filters'), request=useRead(resource,queryFor(resource,params)), d=request.data?.data
  const card = (key,c) => <StatCard key={key} label={INDICATORS[key]?.label ?? LABELS[key] ?? key} value={c?.value} to={safeLink(c?.link)} definition={INDICATORS[key]?.definition} note={c?.note} unavailableReason={c?.reason}/>
  return <><PageHeader title={reports ? 'التقارير الشاملة' : 'رئيس جامعة الروّاد للعلوم والتقانة'} subtitle="متابعة الجامعة من بيانات النظام؛ لا توجد إجراءات تعديل في هذه البوابة."/>
    <Filters resource={resource} params={params} setParams={setParams} options={options.data?.data}/>
    {options.error && <Notice tone="warning">تعذر تحميل خيارات المرشحات. <button onClick={options.reload}>إعادة المحاولة</button></Notice>}
    {request.loading || request.error ? <Load request={request}/> : <div className="grid gap-5">
      <Notice>الفترة: {d.period.label || d.period.reason} — وقت الحساب: {formatDateTime(d.generated_at)}</Notice>
      <Section title="الجامعة — الوضع الحالي" subtitle="الأعداد الحالية لا تتأثر بمرشح السنة والفصل."><div className="grid grid-cols-3 max-[800px]:grid-cols-2 max-[480px]:grid-cols-1 gap-3">{['students','programs','faculty','colleges','deans','courses'].map(k=>card(k,d.counts[k]))}</div></Section>
      <Section title="مؤشرات الفترة المختارة"><div className="grid grid-cols-3 max-[800px]:grid-cols-2 max-[480px]:grid-cols-1 gap-3">{d.period_metrics ? ['registered_students','official_results','graduates','offerings'].map(k=>card(k,d.period_metrics[k])) : <Notice tone="warning">غير متاح: {d.period.reason}</Notice>}</div></Section>
      <Section title="تحتاج متابعتك"><Notice tone="warning">{d.followup.reason}</Notice>{canAccess(presidentAccess('followup')) && <Link to="/president/followup" className="text-primary-dark underline text-[13px]">عرض المسارات والحالات المسجلة للمتابعة</Link>}</Section>
      <Section title="مقارنة الكليات والمعاهد" subtitle={d.comparison_note}><MiniTable rows={d.college_comparison} rowKey={r=>r.college_id} columns={['college_name','programs','students','faculty'].map(key=>({key,header:LABELS[key],render:r=>value(r[key])}))}/></Section>
      <div className="grid grid-cols-2 max-[900px]:grid-cols-1 gap-5"><Trend title="اتجاه الالتحاق" rows={d.trends.intake_by_year}/><Trend title="اتجاه التخرج" rows={d.trends.graduates_by_year}/><Trend title="النتائج الرسمية عبر الفصول" rows={d.trends.official_results_by_term}/></div>
      <Section title="حدود البيانات">{d.unavailable.map(r=><p className="text-[12.5px] mb-2" key={r.code}>{r.label}: {r.reason}</p>)}</Section>
    </div>}
  </>
}

export function PresidentFollowup() {
  const { source,id }=useParams(), [params,setParams]=useSearchParams(), query=new URLSearchParams([...params].filter(([k])=>['status','page','per_page'].includes(k))).toString()
  const request=useRead('followup'+(source ? '/'+source+(id ? '/'+id : '') : ''),id ? '' : query)
  return <><PageHeader title="القرارات والمتابعة" back={{to:'/president'+(source?'/followup':''),label:'رجوع'}} subtitle="متابعة للقراءة فقط؛ صاحب القرار الحالي لا يتغير."/>
    {request.loading || request.error ? <Load request={request}/> : !source ? <div className="grid gap-4"><Notice tone="warning">{request.data.data.inbox.reason}</Notice>
      <MiniTable rows={request.data.data.workflows} rowKey={r=>r.id} columns={[
        {key:'name',header:'المسار',render:r=><Link className="text-primary-dark underline" to={'/president/followup/'+r.id}>{r.name}</Link>},
        {key:'owner',header:'صاحب القرار',render:r=>r.owner}, {key:'states',header:'الحالات المسجلة',render:r=>r.available?r.states.map(s=>`${value(s.status)}: ${s.total}`).join('، '):'غير متاح'},
        {key:'escalation',header:'متى يصل للرئيس؟',render:r=>r.escalation},
      ]}/></div> : <Section title={request.data.definition?.name || 'سجل المسار'} subtitle={request.data.definition?.owner}>
      <Notice>للقراءة فقط؛ لا تمثل هذه القائمة طلبات محالة للرئيس. لا يُستنتج التأخر دون مهلة موثقة.</Notice>
      {!id && <label className="block text-[12px] my-3">رمز الحالة المسجل<input className="border rounded p-2 mr-2" value={params.get('status')||''} onChange={e=>setParams(changedFilter(params,'status',e.target.value))}/></label>}
      {request.data.available ? <DataTable rows={request.data.data} rowKey={r=>r.id} columns={[{key:'id',header:'رقم السجل',render:r=>id?r.id:<Link to={`/president/followup/${source}/${r.id}`}>{r.id}</Link>},{key:'status',header:'الحالة',render:r=>value(r.status)}, ...(source === 'discipline' ? [{key:'decided_by_authority',header:'جهة القرار المسجلة',render:r=>value(r.decided_by_authority)},{key:'decision_date',header:'تاريخ القرار المسجل',render:r=>value(r.decision_date)}] : [])]} page={request.data.meta.current_page} totalPages={request.data.meta.last_page} onPageChange={p=>{const n=new URLSearchParams(params);n.set('page',p);setParams(n)}}/> : <Notice tone="warning">{request.data.reason}</Notice>}
    </Section>}
  </>
}
