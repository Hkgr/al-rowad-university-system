import { useState, useEffect, useMemo } from 'react'
import { FaSpinner, FaGraduationCap, FaEdit, FaTrash, FaPlus, FaSave, FaTimes } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const EMPTY = {
  department_id: '', program_code: '', program_name: '', degree_level: '',
  total_credit_hours: '', duration_years: '', description: '', is_active: 1,
}

export default function ProgramsPage() {
  const [programs,    setPrograms]    = useState([])
  const [departments, setDepartments] = useState([])
  const [colleges,    setColleges]    = useState([])
  const [loading,     setLoading]     = useState(true)
  const [error,       setError]       = useState('')
  const [editing,     setEditing]     = useState(null) // null | 'new' | program obj
  const [form,        setForm]        = useState({ ...EMPTY })
  const [saving,      setSaving]      = useState(false)
  const [saveErr,     setSaveErr]     = useState('')

  function loadAll() {
    Promise.all([
      fetch(`${API}/academic-programs?per_page=100`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/departments?per_page=100`,        { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/colleges?per_page=100`,            { headers: authHeaders() }).then(r => r.json()),
    ]).then(([prog, dep, col]) => {
      if (prog.success) setPrograms(prog.data?.data ?? prog.data ?? [])
      else setError(prog.message || 'فشل التحميل')
      if (dep.success) setDepartments(dep.data?.data ?? dep.data ?? [])
      if (col.success) setColleges(col.data?.data ?? col.data ?? [])
    }).catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }

  useEffect(() => { loadAll() }, [])

  const collegeMap = useMemo(() => {
    const m = new Map()
    colleges.forEach(c => m.set(String(c.college_id), c.college_name))
    return m
  }, [colleges])

  const departmentMap = useMemo(() => {
    const m = new Map()
    departments.forEach(d => m.set(String(d.department_id), d))
    return m
  }, [departments])

  function departmentLabel(dept) {
    const collegeName = collegeMap.get(String(dept.college_id)) ?? '—'
    return `${dept.department_name} (${collegeName})`
  }

  function startEdit(prog) {
    setEditing(prog)
    setForm(prog
      ? {
          department_id: String(prog.department_id),
          program_code: prog.program_code,
          program_name: prog.program_name,
          degree_level: prog.degree_level,
          total_credit_hours: String(prog.total_credit_hours ?? ''),
          duration_years: String(prog.duration_years ?? ''),
          description: prog.description ?? '',
          is_active: prog.is_active ? 1 : 0,
        }
      : { ...EMPTY })
    setSaveErr('')
  }

  async function handleSave() {
    if (!form.department_id) { setSaveErr('يرجى اختيار القسم'); return }
    setSaving(true); setSaveErr('')
    const url    = editing && editing !== 'new' ? `${API}/academic-programs/${editing.academic_program_id}` : `${API}/academic-programs`
    const method = editing && editing !== 'new' ? 'PUT' : 'POST'
    try {
      const res  = await fetch(url, {
        method,
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({
          ...form,
          department_id: Number(form.department_id),
          total_credit_hours: Number(form.total_credit_hours),
          duration_years: Number(form.duration_years),
          is_active: Number(form.is_active),
        }),
      })
      const json = await res.json()
      if (json.success) { setEditing(null); loadAll() }
      else setSaveErr(json.message || 'فشلت العملية')
    } catch { setSaveErr('تعذّر الاتصال بالخادم') }
    finally   { setSaving(false) }
  }

  async function handleDelete(prog) {
    if (!window.confirm(`هل تريد حذف اختصاص "${prog.program_name}"؟`)) return
    try {
      const res  = await fetch(`${API}/academic-programs/${prog.academic_program_id}`, { method: 'DELETE', headers: authHeaders() })
      const json = await res.json()
      if (json.success) loadAll()
      else alert(json.message || 'فشل الحذف')
    } catch { alert('تعذّر الاتصال بالخادم') }
  }

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  return (
    <>
      <div className="flex items-center justify-between mb-5 gap-4" dir="rtl">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الاختصاصات</h2>
          <p className="text-[12.5px] text-text-light">{programs.length} اختصاص مسجل</p>
        </div>
        <button onClick={() => startEdit('new')} disabled={departments.length === 0}
          className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold shadow-[0_4px_16px_rgba(86,153,51,0.35)] hover:-translate-y-0.5 transition-all duration-[220ms] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:translate-y-0">
          <FaPlus className="text-[12px]" /> إضافة اختصاص
        </button>
      </div>

      {error && <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {error}</div>}
      {!loading && departments.length === 0 && (
        <div className="bg-amber-50 border border-amber-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-amber-700" dir="rtl">
          يجب إضافة قسم واحد على الأقل قبل إضافة الاختصاصات.
        </div>
      )}

      {editing && (
        <div className="bg-white border border-primary/15 rounded-[16px] p-5 mb-5 shadow-[0_2px_12px_rgba(86,153,51,0.08)]" dir="rtl">
          <h3 className="text-[14px] font-extrabold text-text-dark mb-4">
            {editing === 'new' ? 'إضافة اختصاص جديد' : `تعديل: ${editing.program_name}`}
          </h3>
          <div className="grid grid-cols-2 max-[560px]:grid-cols-1 gap-4">
            <div className="col-span-2 max-[560px]:col-span-1">
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">القسم *</label>
              <select value={form.department_id} onChange={e => set('department_id', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary bg-white">
                <option value="">اختر القسم…</option>
                {departments.map(d => <option key={d.department_id} value={d.department_id}>{departmentLabel(d)}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">كود الاختصاص *</label>
              <input value={form.program_code} onChange={e => set('program_code', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary font-mono uppercase" placeholder="CS-BSC" />
            </div>
            <div>
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">اسم الاختصاص *</label>
              <input value={form.program_name} onChange={e => set('program_name', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" placeholder="علم الحاسوب" />
            </div>
            <div>
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">المستوى الأكاديمي *</label>
              <input value={form.degree_level} onChange={e => set('degree_level', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" placeholder="بكالوريوس" />
            </div>
            <div>
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">مدة الدراسة (سنوات) *</label>
              <input type="number" min="1" value={form.duration_years} onChange={e => set('duration_years', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" placeholder="4" />
            </div>
            <div>
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">إجمالي الساعات المعتمدة *</label>
              <input type="number" min="0" value={form.total_credit_hours} onChange={e => set('total_credit_hours', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" placeholder="132" />
            </div>
            <div className="col-span-2 max-[560px]:col-span-1">
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">الوصف</label>
              <input value={form.description} onChange={e => set('description', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" placeholder="وصف الاختصاص…" />
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="progActive" checked={!!form.is_active} onChange={e => set('is_active', e.target.checked ? 1 : 0)} />
              <label htmlFor="progActive" className="text-[13px] font-semibold text-text-dark">نشط</label>
            </div>
          </div>
          {saveErr && <p className="mt-3 text-[12px] text-red-600 bg-red-50 border border-red-200 rounded-[8px] px-3 py-2">{saveErr}</p>}
          <div className="flex gap-2 mt-4 pt-4 border-t border-primary/10">
            <button onClick={handleSave} disabled={saving}
              className="flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-[9px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors">
              {saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaSave className="text-[11px]" />} حفظ
            </button>
            <button onClick={() => setEditing(null)}
              className="flex items-center gap-1.5 px-4 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark hover:bg-gray-50 transition-colors">
              <FaTimes className="text-[11px]" /> إلغاء
            </button>
          </div>
        </div>
      )}

      <div className="bg-white rounded-[16px] border border-primary/12 overflow-hidden shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
        {loading ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary">
            <FaSpinner className="text-[28px] animate-spin" />
          </div>
        ) : programs.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-16">
            <FaGraduationCap className="text-[48px] text-[#d1eab8] mb-2" />
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">لا توجد اختصاصات مسجلة</p>
          </div>
        ) : (
          <table className="w-full border-collapse">
            <thead>
              <tr>
                {['#', 'الكود', 'اسم الاختصاص', 'القسم', 'المستوى', 'المدة', 'الساعات', 'الحالة', 'الإجراءات'].map(h => (
                  <th key={h} className="px-4 py-3.5 text-right text-[11.5px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {programs.map((prog, idx) => {
                const dept = departmentMap.get(String(prog.department_id))
                return (
                  <tr key={prog.academic_program_id} className="border-b border-primary/7 last:border-b-0 hover:bg-primary/[0.025] transition-colors">
                    <td className="px-4 py-[13px] text-[12px] text-text-light w-10">{idx + 1}</td>
                    <td className="px-4 py-[13px]">
                      <span className="inline-block px-2 py-[3px] bg-primary/8 border border-primary/15 rounded-[6px] text-[11px] font-bold text-primary-dark font-mono">{prog.program_code}</span>
                    </td>
                    <td className="px-4 py-[13px] font-semibold text-[13.5px] text-text-dark" dir="rtl">{prog.program_name}</td>
                    <td className="px-4 py-[13px] text-[12.5px] text-text-gray" dir="rtl">{dept ? departmentLabel(dept) : '—'}</td>
                    <td className="px-4 py-[13px] text-[12.5px] text-text-gray" dir="rtl">{prog.degree_level}</td>
                    <td className="px-4 py-[13px] text-[12.5px] text-text-gray" dir="rtl">{prog.duration_years} سنوات</td>
                    <td className="px-4 py-[13px] text-[12.5px] text-text-gray" dir="rtl">{prog.total_credit_hours}</td>
                    <td className="px-4 py-[13px]">
                      <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${prog.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'}`} dir="rtl">
                        {prog.is_active ? 'نشط' : 'غير نشط'}
                      </span>
                    </td>
                    <td className="px-4 py-[13px]">
                      <div className="flex items-center gap-1.5">
                        <button onClick={() => startEdit(prog)}
                          className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-amber-500 border-amber-200 bg-amber-50 hover:bg-amber-100 transition-colors" title="تعديل">
                          <FaEdit />
                        </button>
                        <button onClick={() => handleDelete(prog)}
                          className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-red-500 border-red-200 bg-red-50 hover:bg-red-100 transition-colors" title="حذف">
                          <FaTrash />
                        </button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>
    </>
  )
}
