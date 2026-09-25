import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { fetchMinistryFilters, fetchMinistryList } from '../lib/ministryApi'
import { appliedFilterLabels, buildQuery, errorMessage, formatNumber, hasActiveFilters, readFilters } from '../lib/ministryState'
import { AppliedFilters, PageHeader } from './MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

const PER_PAGE = 20
const EMPTY_OPTIONS = { academic_years: [], semesters: [], colleges: [], departments: [], programs: [], student_statuses: [], levels: [] }

/**
 * Paginated, filterable list backed by /api/v1/ministry/{page}. Filters live in the URL,
 * so a dashboard number opens exactly the list that produced it (and the back button works).
 */
export default function MinistryListPage({ page, title, en, subtitle, columns, rowKey, searchPlaceholder, renderFilters, emptyTitle, notice, EmptyIcon }) {
  const [params, setParams] = useSearchParams()
  const filters = useMemo(() => readFilters(page, params), [page, params])
  const pageNumber = Math.max(1, Number(params.get('page') ?? 1) || 1)
  // The search box follows the URL unless the user is typing (draft based on the current URL value).
  const [draft, setDraft] = useState({ base: filters.search ?? '', value: filters.search ?? '' })
  const search = draft.base === (filters.search ?? '') ? draft.value : (filters.search ?? '')
  const setSearch = value => setDraft({ base: filters.search ?? '', value })
  const [options, setOptions] = useState(EMPTY_OPTIONS)
  useEffect(() => {
    let active = true
    fetchMinistryFilters().then(response => { if (active) setOptions({ ...EMPTY_OPTIONS, ...(response?.data ?? {}) }) }).catch(() => {})
    return () => { active = false }
  }, [])

  const update = patch => {
    const next = { ...filters, ...patch }
    // Dependent filters: a college change clears a program/department of another college.
    if ('college_id' in patch) { next.program_id = ''; next.department_id = '' }
    if ('registered_year_id' in patch && !patch.registered_year_id) next.registered_semester_id = ''
    if ('offered_year_id' in patch && !patch.offered_year_id) next.offered_semester_id = ''
    setParams(new URLSearchParams(buildQuery(page, next)), { replace: true })
  }
  const set = key => value => update({ [key]: value })
  const goToPage = number => setParams(new URLSearchParams(buildQuery(page, filters, { pageNumber: number })), { replace: false })

  // Debounced search → URL.
  useEffect(() => {
    if ((search ?? '') === (filters.search ?? '')) return undefined
    const timer = setTimeout(() => update({ search }), 350)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const query = buildQuery(page, filters, { pageNumber, perPage: PER_PAGE })
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryList(page, query), [page, query])
  const rows = data?.data ?? []
  const meta = data?.meta ?? { total: 0, last_page: 1 }
  const filtered = hasActiveFilters(filters)

  return (
    <>
      <PageHeader title={title} en={en} subtitle={subtitle}>
        {!loading && !error && <span className="text-[12.5px] font-bold text-primary-dark bg-primary/8 border border-primary/15 rounded-[10px] px-3 py-1.5">{formatNumber(meta.total)} سجل</span>}
      </PageHeader>
      {notice && <div className="mb-4">{notice}</div>}
      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: searchPlaceholder }}
        filters={[]}
        hasActiveFilters={filtered}
        onClear={() => { setSearch(''); setParams(new URLSearchParams(), { replace: true }) }}
      />
      <div className="flex items-end gap-3 flex-wrap -mt-2 mb-3" dir="rtl">{renderFilters(filters, set, options)}</div>
      <div className="mb-4"><AppliedFilters labels={appliedFilterLabels(filters, options)} /></div>
      {error ? (
        <div className="flex items-center justify-between gap-3 flex-wrap border rounded-[12px] px-4 py-3 text-[13px] bg-red-50 border-red-200 text-red-700 mb-4" role="alert" dir="rtl">
          <span>{errorMessage(error)}</span>
          {error.status !== 403 && <button type="button" onClick={reload} className="px-3 py-1.5 rounded-[9px] border border-red-300 bg-white font-bold">إعادة المحاولة</button>}
        </div>
      ) : (
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={rowKey}
          loading={loading}
          animationKey={query}
          emptyIcon={EmptyIcon}
          emptyTitle={filtered ? 'لا توجد نتائج مطابقة للمرشحات' : emptyTitle}
          emptySubtitle={filtered ? 'غيّر المرشحات أو امسحها.' : 'لا توجد سجلات مسجلة في النظام لهذه القائمة.'}
          hasFilters={filtered}
          onClearFilters={() => { setSearch(''); setParams(new URLSearchParams(), { replace: true }) }}
          page={meta.current_page ?? pageNumber}
          totalPages={meta.last_page ?? 1}
          onPageChange={goToPage}
        />
      )}
    </>
  )
}
