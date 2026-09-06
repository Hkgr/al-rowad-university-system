import { alignComparison, detailColumns, formatMetric, reportPeriodText, reportRowKey, sortableFieldsForApplied } from '../presentation'
import { GroupedComparisonChart, HorizontalBarChart, LineChart } from './ReportCharts'

function scopeText(scope, labels) {
  const dimensions = { college_ids: 'college', department_ids: 'department', program_ids: 'program', academic_level_ids: 'academic_level', course_ids: 'course' }
  const values = Object.entries(scope ?? {}).flatMap(([key, ids]) => (Array.isArray(ids) ? ids : []).map(id => labels?.[dimensions[key]]?.[String(id)] ?? `${key}:${id}`))
  return values.length ? values.join('، ') : 'النطاق المصرح الكامل'
}

function SummaryCards({ metrics, summary }) {
  return <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{metrics.map(metric => { const formatted = formatMetric(metric, summary?.[metric.code]); return <div key={metric.code} className="rounded-2xl border border-primary/10 bg-white p-4 shadow-sm"><p className="text-xs font-bold text-text-light">{metric.label}</p><p className="mt-2 text-2xl font-black text-primary-dark">{formatted.text}</p>{formatted.note && <p className="mt-1 text-[11px] text-text-light">{formatted.note}</p>}</div> })}</div>
}

function ComparisonSummary({ metrics, primary, baseline }) {
  return <section className="rounded-2xl border border-primary/15 bg-white p-4"><h3 className="font-black">ملخص المقارنة كما أعاده الخادم</h3><div className="mt-3 overflow-x-auto"><table className="w-full min-w-[560px] text-sm"><thead><tr className="border-b"><th className="p-2 text-right">المؤشر</th><th className="p-2 text-right">التقرير الأساسي</th><th className="p-2 text-right">خط الأساس</th></tr></thead><tbody>{metrics.map(metric => <tr key={metric.code} className="border-b border-black/5"><td className="p-2 font-bold">{metric.label}</td><td className="p-2">{formatMetric(metric, primary?.[metric.code]).text}</td><td className="p-2">{formatMetric(metric, baseline?.[metric.code]).text}</td></tr>)}</tbody></table></div></section>
}

function DetailTable({ report, onPage, onSort, applied }) {
  const columns = detailColumns(report.report.subject, report.rows)
  const sortable = new Set(sortableFieldsForApplied(applied))
  return <section className="rounded-2xl border border-black/5 bg-white shadow-sm"><div className="overflow-x-auto"><table className="w-full min-w-[760px] text-sm"><thead className="bg-primary-dark text-white"><tr>{columns.map(([key, label, sortCode = key]) => <th key={key} className="p-3 text-right"><button type="button" disabled={!sortable.has(sortCode)} onClick={() => onSort(sortCode)} className="font-bold disabled:cursor-default">{label}</button></th>)}</tr></thead><tbody>{report.rows.map((row, index) => <tr key={reportRowKey(report.report.subject, row, index)} className="border-b border-black/5 even:bg-primary/5">{columns.map(([key]) => <td key={key} className="p-3">{typeof row[key] === 'object' ? formatMetric(report.metrics.find(metric => metric.code === key), row[key]).text : row[key] ?? '—'}</td>)}</tr>)}</tbody></table></div>{report.rows.length === 0 && <p className="p-8 text-center text-text-light">لا توجد صفوف مطابقة.</p>}<div className="flex items-center justify-between gap-3 p-4 text-sm"><button type="button" disabled={report.pagination.current_page <= 1} onClick={() => onPage(report.pagination.current_page - 1)} className="rounded-lg border px-4 py-2 disabled:opacity-40">السابق</button><span>صفحة {report.pagination.current_page} من {report.pagination.last_page} — {report.pagination.total} سجل</span><button type="button" disabled={report.pagination.current_page >= report.pagination.last_page} onClick={() => onPage(report.pagination.current_page + 1)} className="rounded-lg border px-4 py-2 disabled:opacity-40">التالي</button></div></section>
}

export default function ReportResults({ report, applied, labels, stale, loading, onRefresh, onPage, onSort }) {
  if (!report) return null
  const dimensions = report.grouping?.dimensions ?? []
  const firstDimension = dimensions[0]
  const comparisonPairs = report.comparison?.type !== 'selected_scopes' && report.comparison?.series ? alignComparison(report.series, report.comparison.series, dimensions) : []
  const historyUnavailable = report.history_availability?.available === false
  return <div className="space-y-5">
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary/15 bg-primary/5 p-4"><div><p className="font-black text-text-dark">{report.report.label}</p><p className="text-xs text-text-light">آخر توليد: {new Date(report.generated_at).toLocaleString('ar-SY')}</p>{stale && <p className="mt-1 text-xs font-bold text-amber-700">تعرض النتائج إعداد التقرير السابق. اضغط إنشاء التقرير لتطبيق تعديلاتك.</p>}</div><button type="button" onClick={onRefresh} disabled={loading} className="rounded-xl bg-primary px-4 py-2 text-sm font-black text-white disabled:opacity-50">تحديث النتائج</button></div>
    {historyUnavailable && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">البيانات التاريخية المطلوبة غير متاحة لأن النظام يحتفظ بهذه الحالة كلقطة حالية فقط.</div>}
    {!historyUnavailable && <SummaryCards metrics={report.metrics} summary={report.summary} />}
    {report.grouping?.groups_may_overlap && <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">قد يظهر عضو الهيئة في أكثر من كلية. لا تُجمع مجموعات الكليات لاشتقاق إجمالي جديد.</div>}
    {report.comparison?.available && <div className="grid gap-2 rounded-2xl border border-primary/15 bg-white p-4 text-sm sm:grid-cols-2"><p><strong>التقرير الأساسي:</strong> {scopeText(report.scope, labels)} — {reportPeriodText(report.period, labels)}</p><p><strong>{report.comparison.type === 'selected_scopes' ? 'النطاقات المقارنة' : 'خط الأساس'}:</strong> {report.comparison.type === 'selected_scopes' ? scopeText(report.comparison.scope, labels) : `${scopeText(report.comparison.scope, labels)} — ${reportPeriodText(report.comparison.period, labels)}`}</p></div>}
    {report.comparison?.available && report.comparison.type !== 'selected_scopes' && <ComparisonSummary metrics={report.metrics} primary={report.summary} baseline={report.comparison.summary} />}
    {!historyUnavailable && firstDimension && report.series.length === 0 && <div className="rounded-2xl bg-white p-8 text-center text-text-light">لا توجد بيانات مطابقة للنطاق المحدد.</div>}
    {!historyUnavailable && firstDimension && report.series.length > 0 && <div className="grid gap-5 xl:grid-cols-2">{report.metrics.map(metric => applied.config.mode === 'trend'
      ? <LineChart key={metric.code} title={metric.label} rows={report.series} metric={metric} dimension={dimensions} labels={labels} />
      : report.comparison?.available && comparisonPairs.length
        ? <GroupedComparisonChart key={metric.code} title={metric.label} pairs={comparisonPairs} metric={metric} dimension={dimensions} labels={labels} />
        : <HorizontalBarChart key={metric.code} title={metric.label} rows={report.series} metric={metric} dimension={dimensions} labels={labels} />)}</div>}
    {applied.config.mode === 'details' && <DetailTable report={report} applied={applied} onPage={onPage} onSort={onSort} />}
    {report.comparison && report.comparison.available === false && <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-text-light">لا يتوفر خط أساس صالح لهذه المقارنة. لم تُحوّل القيمة الغائبة إلى صفر.</div>}
  </div>
}
