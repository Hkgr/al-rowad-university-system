import { useState, useEffect } from 'react'
import { FaSpinner, FaUniversity, FaEdit, FaTrash, FaPlus, FaSave, FaTimes } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const EMPTY = { college_code: '', college_name: '', description: '', is_active: 1 }

export default function CollegesPage() {
  const [colleges, setColleges] = useState([])
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState('')
  const [editing,  setEditing]  = useState(null) // null | 'new' | college obj
  const [form,     setForm]     = useState({ ...EMPTY })
  const [saving,   setSaving]   = useState(false)
  const [saveErr,  setSaveErr]  = useState('')

  function loadColleges() {
    fetch(`${API}/colleges?per_page=100`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (json.success) setColleges(json.data?.data ?? json.data ?? [])
        else setError(json.message || 'فشل التحميل')
      })
      .catch(() => setError('تعذّر الاتصال بالخادم'))
      .finally(() => setLoading(false))
  }

  useEffect(() => { loadColleges() }, [])

  function startEdit(college) {
    setEditing(college)
    setForm(college
      ? { college_code: college.college_code, college_name: college.college_name, description: college.description ?? '', is_active: college.is_active ? 1 : 0 }
      : { ...EMPTY })
    setSaveErr('')
  }

  async function handleSave() {
    setSaving(true); setSaveErr('')
    const url    = editing && editing !== 'new' ? `${API}/colleges/${editing.college_id}` : `${API}/colleges`
    const method = editing && editing !== 'new' ? 'PUT' : 'POST'
    try {
      const res  = await fetch(url, {
        method,
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, is_active: Number(form.is_active) }),
      })
      const json = await res.json()
      if (json.success) { setEditing(null); loadColleges() }
      else setSaveErr(json.message || 'فشلت العملية')
    } catch { setSaveErr('تعذّر الاتصال بالخادم') }
    finally   { setSaving(false) }
  }

  async function handleDelete(college) {
    if (!window.confirm(`هل تريد حذف كلية "${college.college_name}"؟`)) return
    try {
      const res  = await fetch(`${API}/colleges/${college.college_id}`, { method: 'DELETE', headers: authHeaders() })
      const json = await res.json()
      if (json.success) loadColleges()
      else alert(json.message || 'فشل الحذف')
    } catch { alert('تعذّر الاتصال بالخادم') }
  }

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  return (
    <>
      <div className="flex items-center justify-between mb-5 gap-4" dir="rtl">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الكليات</h2>
          <p className="text-[12.5px] text-text-light">{colleges.length} كلية مسجلة</p>
        </div>
        <button onClick={() => startEdit('new')}
          className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold shadow-[0_4px_16px_rgba(86,153,51,0.35)] hover:-translate-y-0.5 transition-all duration-[220ms]">
          <FaPlus className="text-[12px]" /> إضافة كلية
        </button>
      </div>

      {error && <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {error}</div>}

      {editing && (
        <div className="bg-white border border-primary/15 rounded-[16px] p-5 mb-5 shadow-[0_2px_12px_rgba(86,153,51,0.08)]" dir="rtl">
          <h3 className="text-[14px] font-extrabold text-text-dark mb-4">
            {editing === 'new' ? 'إضافة كلية جديدة' : `تعديل: ${editing.college_name}`}
          </h3>
          <div className="grid grid-cols-2 max-[560px]:grid-cols-1 gap-4">
            <div>
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">كود الكلية *</label>
              <input value={form.college_code} onChange={e => set('college_code', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary font-mono uppercase" placeholder="ENG" />
            </div>
            <div>
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">اسم الكلية *</label>
              <input value={form.college_name} onChange={e => set('college_name', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" placeholder="كلية الهندسة" />
            </div>
            <div className="col-span-2 max-[560px]:col-span-1">
              <label className="block text-[11.5px] font-bold text-text-dark mb-1.5">الوصف</label>
              <input value={form.description} onChange={e => set('description', e.target.value)}
                className="w-full px-3 py-2.5 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" placeholder="وصف الكلية…" />
            </div>
            <div className="flex items-center gap-2">
              <input type="checkbox" id="collegeActive" checked={!!form.is_active} onChange={e => set('is_active', e.target.checked ? 1 : 0)} />
              <label htmlFor="collegeActive" className="text-[13px] font-semibold text-text-dark">نشطة</label>
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
        ) : colleges.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-16">
            <FaUniversity className="text-[48px] text-[#d1eab8] mb-2" />
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">لا توجد كليات مسجلة</p>
          </div>
        ) : (
          <table className="w-full border-collapse">
            <thead>
              <tr>
                {['#', 'الكود', 'اسم الكلية', 'الوصف', 'الحالة', 'الإجراءات'].map(h => (
                  <th key={h} className="px-4 py-3.5 text-right text-[11.5px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {colleges.map((college, idx) => (
                <tr key={college.college_id} className="border-b border-primary/7 last:border-b-0 hover:bg-primary/[0.025] transition-colors">
                  <td className="px-4 py-[13px] text-[12px] text-text-light w-10">{idx + 1}</td>
                  <td className="px-4 py-[13px]">
                    <span className="inline-block px-2 py-[3px] bg-primary/8 border border-primary/15 rounded-[6px] text-[11px] font-bold text-primary-dark font-mono">{college.college_code}</span>
                  </td>
                  <td className="px-4 py-[13px] font-semibold text-[13.5px] text-text-dark" dir="rtl">{college.college_name}</td>
                  <td className="px-4 py-[13px] text-[12.5px] text-text-gray max-w-[240px]" dir="rtl">{college.description || '—'}</td>
                  <td className="px-4 py-[13px]">
                    <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${college.is_active ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600'}`} dir="rtl">
                      {college.is_active ? 'نشطة' : 'غير نشطة'}
                    </span>
                  </td>
                  <td className="px-4 py-[13px]">
                    <div className="flex items-center gap-1.5">
                      <button onClick={() => startEdit(college)}
                        className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-amber-500 border-amber-200 bg-amber-50 hover:bg-amber-100 transition-colors" title="تعديل">
                        <FaEdit />
                      </button>
                      <button onClick={() => handleDelete(college)}
                        className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-red-500 border-red-200 bg-red-50 hover:bg-red-100 transition-colors" title="حذف">
                        <FaTrash />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </>
  )
}
