import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaEye, FaHistory, FaInfoCircle, FaSpinner, FaTimes } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { clearIdentity } from '../../auth/auth'
import { fetchActivity, fetchActivityEvent, fetchActivityOptions } from '../lib/activityApi'
import {
  EMPTY_FILTERS, OUTCOME_AR, OUTCOME_BADGE, SOURCE_AR, actionsForModule, activityErrorMessage, buildActivityQuery, changeText, formatActivityTime, hasActiveFilters,
  SEARCH_HELP, SEARCH_PLACEHOLDER,
} from '../lib/activityState'

const PER_PAGE = 25
const inputClass = 'py-2 px-3 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none focus:border-primary'

function EventDetail({ eventKey, onClose }) {
  const [event, setEvent] = useState(null)
  const [error, setError] = useState('')
  useEffect(() => {
    const [source, id] = eventKey.split(':')
    fetchActivityEvent(source, id)
      .then(json => setEvent(json.data))
      .catch(err => setError(activityErrorMessage(err)))
  }, [eventKey])

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <motion.div className="bg-white rounded-[18px] shadow-2xl w-full max-w-[640px] max-h-[92vh] overflow-y-auto"
        initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.2 }} dir="rtl" role="dialog" aria-label="تفاصيل الحدث">
        <div className="flex items-center justify-between px-6 py-4 border-b border-primary/10 sticky top-0 bg-white z-[1]">
          <h3 className="text-[16px] font-extrabold text-text-dark">تفاصيل الحدث</h3>
          <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-text-light transition-colors" aria-label="إغلاق"><FaTimes /></button>
        </div>
        {!event && !error && <div className="flex items-center justify-center gap-2 py-12 text-primary-light text-[14px]"><FaSpinner className="animate-spin" /> جاري التحميل…</div>}
        {error && <p className="m-6 text-[13px] text-red-600 bg-red-50 border border-red-200 rounded-[10px] px-4 py-3" role="alert">⚠ {error}</p>}
        {event && (
          <div className="p-6 flex flex-col gap-4 text-[13px]">
            <div>
              <p className="text-[15px] font-black text-text-dark">{event.action.label}</p>
              <p className="text-text-gray mt-1">{event.summary}</p>
              <div className="flex flex-wrap gap-1.5 mt-2 text-[11px]">
                <span className={`font-bold px-2 py-0.5 rounded-full ${OUTCOME_BADGE[event.outcome]}`}>حالة العملية: {OUTCOME_AR[event.outcome]}</span>
                <span className="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-bold">{SOURCE_AR[event.source]}</span>
                <span className="px-2 py-0.5 rounded-full bg-primary/8 text-primary-dark font-bold">{event.module.label}</span>
              </div>
            </div>
            <dl className="grid grid-cols-[120px_1fr] gap-x-3 gap-y-2 max-[480px]:grid-cols-1">
              <dt className="text-text-light">وقت التنفيذ</dt><dd>{formatActivityTime(event.occurred_at)}</dd>
              <dt className="text-text-light">المنفّذ</dt><dd dir="ltr" className="text-right">{event.actor?.username ?? (event.actor ? `#${event.actor.user_id}` : 'غير معروف (محاولة دون حساب مطابق)')}</dd>
              <dt className="text-text-light">الحساب المتأثر</dt>
              <dd>{event.target ? (event.target.link ? <Link className="font-bold text-primary-dark hover:underline" to={event.target.link} dir="ltr">{event.target.username ?? `#${event.target.user_id}`}</Link> : <span dir="ltr">{event.target.username ?? `#${event.target.user_id}`}</span>) : '—'}</dd>
              {event.ip_address && <><dt className="text-text-light">عنوان IP</dt><dd dir="ltr" className="text-right">{event.ip_address}</dd></>}
              {event.user_agent && <><dt className="text-text-light">المتصفح</dt><dd className="text-[11.5px] text-text-gray break-all" dir="ltr">{event.user_agent}</dd></>}
            </dl>
            {event.changes?.length > 0 && (
              <section>
                <h4 className="text-[13px] font-extrabold text-text-dark mb-2">التغييرات</h4>
                <ul className="flex flex-col gap-1.5">{event.changes.map(change => <li key={change.field} className="border border-primary/12 rounded-[10px] px-3 py-2">{changeText(change)}</li>)}</ul>
              </section>
            )}
            {Object.keys(event.fields ?? {}).length > 0 && (
              <section>
                <h4 className="text-[13px] font-extrabold text-text-dark mb-2">بيانات الحدث (الحقول الآمنة فقط)</h4>
                <ul className="flex flex-col gap-1 text-[12px] text-text-gray">
                  {Object.entries(event.fields).map(([key, value]) => <li key={key} className="flex gap-2 flex-wrap"><span className="font-mono" dir="ltr">{key}</span><span dir="ltr">{typeof value === 'object' ? JSON.stringify(value) : String(value)}</span></li>)}
                </ul>
              </section>
            )}
            {event.notes?.map(note => <p key={note} className="flex items-start gap-2 text-[12px] text-text-gray bg-primary/5 border border-primary/15 rounded-[10px] px-3 py-2"><FaInfoCircle className="text-primary mt-0.5 flex-shrink-0" />{note}</p>)}
          </div>
        )}
      </motion.div>
    </div>
  )
}

export default function ActivityLogPage() {
  const navigate = useNavigate()
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ total: 0, last_page: 1 })
  const [options, setOptions] = useState({ modules: [], actions: [], scope: 'technical_modules' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [selected, setSelected] = useState(null)
  const debounceRef = useRef(null)
  const firstLoad = useRef(true)

  const load = useCallback(async state => {
    setLoading(true); setError('')
    try {
      const json = await fetchActivity(buildActivityQuery(state, PER_PAGE))
      setRows(json.data?.data ?? [])
      setMeta(json.data?.meta ?? { total: 0, last_page: 1 })
    } catch (err) {
      if (err?.status === 401) { clearIdentity(); navigate('/login'); return }
      setRows([])
      setError(activityErrorMessage(err))
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => { fetchActivityOptions().then(json => setOptions(json.data)).catch(() => {}) }, [])

  useEffect(() => {
    clearTimeout(debounceRef.current)
    const delay = firstLoad.current ? 0 : 380
    firstLoad.current = false
    debounceRef.current = setTimeout(() => load(filters), delay)
    return () => clearTimeout(debounceRef.current)
  }, [filters, load])

  const set = key => value => setFilters(current => ({ ...current, [key]: value, page: 1, ...(key === 'module' ? { action: '' } : {}) }))
  const clear = () => setFilters(EMPTY_FILTERS)
  const filtered = hasActiveFilters(filters)

  const columns = [
    { key: 'time', header: 'الوقت', dir: 'rtl', render: row => <span className="text-[12px] text-text-gray whitespace-nowrap">{formatActivityTime(row.occurred_at)}</span> },
    { key: 'actor', header: 'المنفّذ', dir: 'rtl', render: row => <span className="text-[12.5px] text-text-dark" dir="ltr">{row.actor?.username ?? (row.actor ? `#${row.actor.user_id}` : '—')}</span> },
    {
      key: 'action', header: 'الإجراء', dir: 'rtl',
      render: row => (
        <div className="min-w-[150px]">
          <div className="text-[13px] font-bold text-text-dark">{row.action.label}</div>
          <div className="text-[11.5px] text-text-light">{row.module.label}</div>
        </div>
      ),
    },
    {
      key: 'target', header: 'الحساب المتأثر', dir: 'rtl',
      render: row => row.target ? (row.target.link
        ? <Link to={row.target.link} className="text-[12.5px] font-bold text-primary-dark hover:underline" dir="ltr">{row.target.username ?? `#${row.target.user_id}`}</Link>
        : <span className="text-[12.5px]" dir="ltr">{row.target.username ?? `#${row.target.user_id}`}</span>) : <span className="text-[12px] text-text-light">—</span>,
    },
    {
      key: 'summary', header: 'ملخص التغيير', dir: 'rtl',
      render: row => (
        <div className="max-w-[340px]">
          <div className="text-[12.5px] text-text-dark">{row.summary}</div>
          {row.changes?.slice(0, 2).map(change => <div key={change.field} className="text-[11.5px] text-text-gray truncate" title={changeText(change)}>{changeText(change)}</div>)}
        </div>
      ),
    },
    { key: 'outcome', header: 'حالة العملية', dir: 'rtl', render: row => <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap ${OUTCOME_BADGE[row.outcome]}`}>{OUTCOME_AR[row.outcome]}</span> },
    {
      key: 'open', header: '', dir: 'rtl',
      render: row => <button type="button" onClick={() => setSelected(row.key)} className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-blue-500 border-blue-500/20 bg-blue-50 hover:bg-blue-100 transition-colors" title="تفاصيل الحدث" aria-label={`تفاصيل ${row.action.label}`}><FaEye /></button>,
    },
  ]

  return (
    <>
      {selected && <EventDetail eventKey={selected} onClose={() => setSelected(null)} />}

      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">سجل النشاط</h2>
          <p className="text-[12.5px] text-text-light">{loading ? 'جاري التحميل…' : `${meta.total ?? 0} حدث`}</p>
        </div>
      </div>

      <div className="flex items-start gap-2 bg-primary/5 border border-primary/15 rounded-[12px] px-4 py-3 mb-4 text-[12.5px] text-text-gray" dir="rtl">
        <FaInfoCircle className="text-primary mt-0.5 flex-shrink-0" />
        <span>
          سجل للقراءة فقط يعرض أحداث التدقيق المسجّلة فعليًا منذ تفعيل كل نوع منها، ومحاولات تسجيل الدخول والخروج. لا يعرض كلمات المرور أو التوكنات، ويُظهر البريد الإلكتروني مقنّعًا، ولا يمكن تعديل أي حدث أو حذفه.
          {` ${SEARCH_HELP}`}
          {options.scope === 'technical_modules' ? ' يقتصر عرضك على وحدات الحسابات وتسجيل الدخول وتعيينات النيابة الإدارية وتعديلات السجلات العامة (أسماء الحقول فقط).' : ' تعرض كمدير نظام جميع الوحدات.'}
        </span>
      </div>

      <FilterBar
        search={{ value: filters.search, onChange: set('search'), placeholder: SEARCH_PLACEHOLDER }}
        filters={[
          { key: 'module', value: filters.module, onChange: set('module'), placeholder: 'كل الوحدات', minWidth: 190, options: options.modules.map(m => ({ value: m.code, label: m.label })) },
          { key: 'action', value: filters.action, onChange: set('action'), placeholder: 'كل الإجراءات', minWidth: 200, options: actionsForModule(options.actions, filters.module).map(a => ({ value: a.code, label: a.label })) },
        ]}
        hasActiveFilters={filtered}
        onClear={clear}
      />
      <div className="flex items-end gap-3 flex-wrap -mt-2 mb-5" dir="rtl">
        <label className="flex flex-col gap-1 text-[12px] font-semibold text-text-light">المنفّذ (اسم المستخدم)
          <input className={`${inputClass} min-w-[180px]`} dir="ltr" value={filters.actor} onChange={e => set('actor')(e.target.value)} placeholder="username" />
        </label>
        <label className="flex flex-col gap-1 text-[12px] font-semibold text-text-light">من تاريخ
          <input type="date" className={inputClass} value={filters.from} onChange={e => set('from')(e.target.value)} />
        </label>
        <label className="flex flex-col gap-1 text-[12px] font-semibold text-text-light">إلى تاريخ
          <input type="date" className={inputClass} value={filters.to} onChange={e => set('to')(e.target.value)} />
        </label>
      </div>

      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl" role="alert">
          <span>⚠ {error}</span>
          <button type="button" onClick={() => load(filters)} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px] hover:bg-red-50 transition-colors">إعادة المحاولة</button>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={rows}
        rowKey={row => row.key}
        loading={loading}
        animationKey={JSON.stringify(filters)}
        emptyIcon={FaHistory}
        emptyTitle={error ? 'تعذّر عرض السجل' : filtered ? 'لا توجد أحداث مطابقة' : 'لا توجد أحداث مسجّلة بعد'}
        emptySubtitle={filtered ? 'جرّب تعديل البحث أو الفلاتر أو الفترة الزمنية.' : undefined}
        hasFilters={filtered}
        onClearFilters={clear}
        page={filters.page}
        totalPages={meta.last_page ?? 1}
        onPageChange={page => setFilters(current => ({ ...current, page }))}
      />
    </>
  )
}
