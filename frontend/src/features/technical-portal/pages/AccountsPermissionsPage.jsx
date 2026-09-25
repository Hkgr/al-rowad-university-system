import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { FaEye, FaUserPlus, FaUsers, FaInfoCircle, FaLock, FaTimes } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { ACCESS, canAccess, clearIdentity, landingRoute, storeIdentity } from '../../auth/auth'
import { fetchAccountOptions, fetchAccounts, fetchCurrentIdentity } from '../lib/accountsApi'
import { STATUS_AR, STATUS_BADGE, accountErrorMessage, accountsViewState, buildAccountsQuery } from '../lib/accountsState'
import CreateAccountModal from '../components/CreateAccountModal'
import AccountDetailPanel from '../components/AccountDetailPanel'

const PER_PAGE = 15
const STATUS_FILTER_OPTIONS = Object.entries(STATUS_AR).map(([value, label]) => ({ value, label }))

export default function AccountsPermissionsPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ total: 0, last_page: 1, per_page: PER_PAGE })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [roleId, setRoleId] = useState('')
  const [page, setPage] = useState(1)
  const [options, setOptions] = useState({ roles: [], statuses: [], actor: { can_manage: false } })
  const [optionsError, setOptionsError] = useState('')
  const [creating, setCreating] = useState(false)
  const [selectedId, setSelectedId] = useState(() => {
    const requested = Number(new URLSearchParams(location.search).get('user'))
    return Number.isInteger(requested) && requested > 0 ? requested : null
  })
  const [success, setSuccess] = useState('')
  const debounceRef = useRef(null)
  const firstLoad = useRef(true)

  const hasFilters = Boolean(search.trim() || status || roleId)
  const canCreate = options.actor.can_manage && canAccess(ACCESS.technicalAccountsManage)
  const viewState = accountsViewState({ loading, error, rows, hasFilters })

  const loadAccounts = useCallback(async (query) => {
    setLoading(true); setError(null)
    try {
      const json = await fetchAccounts(buildAccountsQuery({ ...query, perPage: PER_PAGE }))
      setRows(json.data?.data ?? [])
      setMeta(json.data?.meta ?? { total: 0, last_page: 1, per_page: PER_PAGE })
    } catch (err) {
      if (err?.status === 401) { clearIdentity(); navigate('/login'); return }
      setRows([])
      setError({ status: err?.status ?? 0, message: accountErrorMessage(err).message })
    } finally {
      setLoading(false)
    }
  }, [navigate])

  const loadOptions = useCallback(() => fetchAccountOptions()
    .then(json => { setOptions(json.data); setOptionsError('') })
    .catch(err => setOptionsError(accountErrorMessage(err).message)), [])

  useEffect(() => { loadOptions() }, [loadOptions])

  // Debounce free-text search; filters and paging reload immediately.
  useEffect(() => {
    clearTimeout(debounceRef.current)
    const delay = firstLoad.current ? 0 : 380
    firstLoad.current = false
    debounceRef.current = setTimeout(() => loadAccounts({ search, status, roleId, page }), delay)
    return () => clearTimeout(debounceRef.current)
  }, [search, status, roleId, page, loadAccounts])

  const resetToFirstPage = setter => value => { setPage(1); setter(value) }
  const clearFilters = () => { setPage(1); setSearch(''); setStatus(''); setRoleId('') }
  const reload = () => loadAccounts({ search, status, roleId, page })

  // A change to the signed-in account's roles must not leave stale permissions in the UI.
  const refreshIdentityIfSelf = useCallback(async (account) => {
    if (!account?.is_current_user) return
    try {
      const json = await fetchCurrentIdentity()
      storeIdentity(json.data)
      const target = canAccess(ACCESS.technicalAccounts, json.data) ? location.pathname : landingRoute(json.data)
      navigate(target, { replace: true })
    } catch (err) {
      if (err?.status === 401 || err?.status === 403) { clearIdentity(); navigate('/login', { replace: true }) }
    }
  }, [location.pathname, navigate])

  const handleChanged = async (account) => {
    await Promise.all([reload(), loadOptions()])
    await refreshIdentityIfSelf(account)
  }

  const columns = [
    { key: 'idx', header: '#', dir: 'rtl', cellClassName: 'text-[12px] text-text-light font-semibold w-10', render: (_, idx) => (page - 1) * (meta.per_page ?? PER_PAGE) + idx + 1 },
    {
      key: 'account', header: 'الحساب', dir: 'rtl',
      render: row => (
        <div>
          <div className="font-semibold text-[13.5px] text-text-dark" dir="ltr">{row.username}</div>
          <div className="text-[12px] text-text-gray" dir="ltr">{row.email}</div>
          {row.holder_name && <div className="text-[12px] text-text-dark">{row.holder_name}</div>}
        </div>
      ),
    },
    {
      key: 'roles', header: 'الأدوار', dir: 'rtl',
      render: row => row.roles.length === 0 ? <span className="text-[12px] text-text-light">بلا أدوار</span> : (
        <div className="flex flex-wrap gap-1 max-w-[320px]">
          {row.roles.map(role => <span key={role.role_id} className="px-2 py-[2px] bg-primary/8 border border-primary/15 rounded-[7px] text-[11.5px] font-bold text-primary-dark">{role.role_name}</span>)}
        </div>
      ),
    },
    {
      key: 'links', header: 'الارتباط', dir: 'rtl', cellClassName: 'text-[12px] text-text-gray',
      render: row => row.student_id ? `طالب #${row.student_id}` : row.employee_id ? `موظف #${row.employee_id}` : '—',
    },
    {
      key: 'status', header: 'الحالة', dir: 'rtl',
      render: row => <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${STATUS_BADGE[row.status?.code] ?? 'bg-gray-100 text-gray-600'}`}>{STATUS_AR[row.status?.code] ?? row.status?.name ?? '—'}</span>,
    },
    {
      key: 'actions', header: 'الإجراءات', dir: 'rtl',
      render: row => (
        <div className="flex items-center gap-1.5">
          <button type="button" onClick={() => setSelectedId(row.user_id)}
            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-blue-500 border-blue-500/20 bg-blue-50 hover:bg-blue-100 transition-colors"
            title="عرض الأدوار والصلاحيات" aria-label={`عرض الحساب ${row.username}`}>
            <FaEye />
          </button>
          {!row.can_manage && <FaLock className="text-[12px] text-text-light" title="حساب محمي أو حسابك الشخصي" />}
        </div>
      ),
    },
  ]

  return (
    <>
      {creating && (
        <CreateAccountModal
          roles={options.roles}
          statuses={options.statuses}
          onClose={() => setCreating(false)}
          onCreated={(account, message) => {
            setCreating(false)
            setSuccess(message || 'تم إنشاء الحساب بنجاح.')
            reload()
            setSelectedId(account.user_id)
          }}
        />
      )}
      {selectedId && (
        <AccountDetailPanel
          userId={selectedId}
          roles={options.roles}
          onClose={() => setSelectedId(null)}
          onChanged={handleChanged}
        />
      )}

      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الحسابات والصلاحيات</h2>
          <p className="text-[12.5px] text-text-light">{meta.total > 0 ? `${meta.total} حساب` : 'عرض جميع الحسابات'}</p>
        </div>
        {canCreate && (
          <button type="button" onClick={() => setCreating(true)}
            className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold shadow-[0_4px_16px_rgba(86,153,51,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(86,153,51,0.45)] transition-all duration-[220ms]"
            dir="rtl">
            <FaUserPlus /> إنشاء حساب
          </button>
        )}
      </div>

      <div className="flex items-start gap-2 bg-primary/5 border border-primary/15 rounded-[12px] px-4 py-3 mb-4 text-[12.5px] text-text-gray" dir="rtl">
        <FaInfoCircle className="text-primary mt-0.5 flex-shrink-0" />
        <span>الصلاحيات تُكتسب من الأدوار المسندة فقط وتُحسب على الخادم. أسند دورًا أو اسحبه لتتغير الصلاحيات؛ لا توجد تحديدات صلاحيات محلية في المتصفح.</span>
      </div>

      {success && (
        <div className="flex items-center justify-between gap-3 bg-green-50 border border-green-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-green-700" dir="rtl" role="status">
          <span>✓ {success}</span>
          <button type="button" onClick={() => setSuccess('')} aria-label="إخفاء الرسالة" className="text-green-700/70 hover:text-green-800"><FaTimes /></button>
        </div>
      )}
      {optionsError && (
        <div className="bg-amber-50 border border-amber-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-amber-800" dir="rtl">⚠ تعذّر تحميل قائمة الأدوار: {optionsError}</div>
      )}

      <FilterBar
        search={{ value: search, onChange: resetToFirstPage(setSearch), placeholder: 'ابحث باسم المستخدم أو البريد الإلكتروني أو رقم الحساب…' }}
        filters={[
          { key: 'status', value: status, onChange: resetToFirstPage(setStatus), placeholder: 'كل الحالات', options: STATUS_FILTER_OPTIONS },
          { key: 'role', value: roleId, onChange: resetToFirstPage(setRoleId), placeholder: 'كل الأدوار', minWidth: 180, options: options.roles.map(role => ({ value: String(role.role_id), label: role.role_name })) },
        ]}
        hasActiveFilters={Boolean(status || roleId)}
        onClear={clearFilters}
      />

      {(viewState === 'error' || viewState === 'forbidden') && (
        <div className="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl" role="alert">
          <span>⚠ {viewState === 'forbidden' ? 'لا يملك حسابك صلاحية عرض الحسابات، أو سُحبت منك مؤخرًا.' : error.message}</span>
          {viewState === 'error' && <button type="button" onClick={reload} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px] hover:bg-red-50 transition-colors">إعادة المحاولة</button>}
        </div>
      )}

      <DataTable
        columns={columns}
        rows={rows}
        rowKey={row => row.user_id}
        loading={loading}
        animationKey={`${search}-${status}-${roleId}-${page}`}
        emptyIcon={FaUsers}
        emptyTitle={viewState === 'emptyFiltered' ? 'لا توجد حسابات مطابقة' : error ? 'تعذّر عرض الحسابات' : 'لا توجد حسابات'}
        emptySubtitle={viewState === 'emptyFiltered' ? 'جرّب تعديل البحث أو الفلاتر.' : undefined}
        hasFilters={hasFilters}
        onClearFilters={clearFilters}
        page={page}
        totalPages={meta.last_page ?? 1}
        onPageChange={setPage}
      />
    </>
  )
}
