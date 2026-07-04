import { useState, useEffect, useCallback } from 'react'
import {
  FaFileAlt, FaUpload, FaDownload, FaTrash, FaSpinner,
  FaCheckCircle, FaClock, FaTimesCircle, FaFolderOpen,
} from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

function fmt(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('ar-SY', { year: 'numeric', month: 'long', day: 'numeric' })
}

const STATUS_STYLES = {
  pending:  { bg: 'bg-amber-500/10', text: 'text-amber-700', border: 'border-amber-500/25', ar: 'قيد المراجعة', Icon: FaClock       },
  verified: { bg: 'bg-green-500/10', text: 'text-green-700', border: 'border-green-500/25', ar: 'موثّق',        Icon: FaCheckCircle },
  rejected: { bg: 'bg-red-500/10',   text: 'text-red-600',   border: 'border-red-500/25',   ar: 'مرفوض',        Icon: FaTimesCircle },
}

export default function StudentDocuments({ studentId }) {
  const [documents, setDocuments]           = useState([])
  const [documentTypes, setDocumentTypes]   = useState([])
  const [loading, setLoading]               = useState(true)
  const [error, setError]                   = useState('')

  const [documentTypeId, setDocumentTypeId] = useState('')
  const [file, setFile]                     = useState(null)
  const [notes, setNotes]                   = useState('')
  const [uploading, setUploading]           = useState(false)
  const [uploadError, setUploadError]       = useState('')

  const [downloadingId, setDownloadingId]   = useState(null)
  const [deletingId, setDeletingId]         = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [docsRes, typesRes] = await Promise.all([
        fetch(`${API}/students/${studentId}/documents?per_page=100`, { headers: authHeaders() }).then(r => r.json()),
        fetch(`${API}/document-types?per_page=100`, { headers: authHeaders() }).then(r => r.json()),
      ])
      if (docsRes.success) setDocuments(docsRes.data?.data ?? [])
      else setError(docsRes.message || 'تعذّر تحميل ملفات الطالب')
      if (typesRes.success) setDocumentTypes(typesRes.data?.data ?? [])
    } catch {
      setError('تعذّر الاتصال بالخادم')
    } finally {
      setLoading(false)
    }
  }, [studentId])

  useEffect(() => { load() }, [load])

  async function handleUpload(e) {
    e.preventDefault()
    setUploadError('')
    if (!documentTypeId) { setUploadError('اختر نوع الملف'); return }
    if (!file) { setUploadError('اختر ملفاً للرفع'); return }
    if (file.size > 5 * 1024 * 1024) { setUploadError('حجم الملف يتجاوز 5 ميغابايت'); return }

    setUploading(true)
    try {
      const formData = new FormData()
      formData.append('document_type_id', documentTypeId)
      formData.append('file', file)
      if (notes.trim()) formData.append('verification_notes', notes.trim())

      const res = await fetch(`${API}/students/${studentId}/documents`, {
        method: 'POST',
        headers: authHeaders(),
        body: formData,
      })
      const json = await res.json()
      if (json.success) {
        setDocuments(prev => [json.data, ...prev])
        setDocumentTypeId('')
        setFile(null)
        setNotes('')
        e.target.reset?.()
      } else {
        setUploadError(json.message || (json.errors && Object.values(json.errors)[0]?.[0]) || 'فشل رفع الملف')
      }
    } catch {
      setUploadError('تعذّر الاتصال بالخادم')
    } finally {
      setUploading(false)
    }
  }

  async function handleDownload(doc) {
    setDownloadingId(doc.student_document_id)
    try {
      const res = await fetch(doc.download_url, { headers: authHeaders() })
      if (!res.ok) throw new Error()
      const blob = await res.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = doc.file_name
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      setError('تعذّر تحميل الملف')
    } finally {
      setDownloadingId(null)
    }
  }

  async function handleDelete(doc) {
    if (!window.confirm(`هل تريد حذف الملف "${doc.file_name}"؟`)) return
    setDeletingId(doc.student_document_id)
    try {
      const res = await fetch(`${API}/student-documents/${doc.student_document_id}`, {
        method: 'DELETE',
        headers: authHeaders(),
      })
      const json = await res.json()
      if (json.success) {
        setDocuments(prev => prev.filter(d => d.student_document_id !== doc.student_document_id))
      } else {
        setError(json.message || 'فشل حذف الملف')
      }
    } catch {
      setError('تعذّر الاتصال بالخادم')
    } finally {
      setDeletingId(null)
    }
  }

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary-light">
        <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" />
        <span className="text-[13.5px] font-medium">جاري تحميل ملفات الطالب…</span>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {error && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 text-[13px] text-red-600" dir="rtl">
          ⚠ {error}
        </div>
      )}

      {/* Upload form */}
      <form
        onSubmit={handleUpload}
        className="bg-[#fafaf9] border border-primary/12 rounded-[16px] p-5"
        dir="rtl"
      >
        <div className="flex items-center gap-2 mb-4">
          <FaUpload className="text-primary text-[14px]" />
          <span className="text-[14px] font-extrabold text-text-dark">رفع ملف جديد</span>
        </div>
        <div className="grid grid-cols-3 max-[640px]:grid-cols-1 gap-4 mb-4">
          <div className="flex flex-col gap-1.5">
            <label className="text-[12.5px] font-semibold text-text-dark">نوع الملف <span className="text-red-500">*</span></label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)]"
              value={documentTypeId}
              onChange={e => setDocumentTypeId(e.target.value)}
            >
              <option value="">اختر النوع</option>
              {documentTypes.map(t => (
                <option key={t.document_type_id} value={t.document_type_id}>{t.type_name}</option>
              ))}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-[12.5px] font-semibold text-text-dark">الملف <span className="text-red-500">*</span></label>
            <input
              type="file"
              accept=".pdf,.jpg,.jpeg,.png"
              className="px-3 py-2 border border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none focus:border-primary file:ml-2 file:px-3 file:py-1 file:rounded-[6px] file:border-0 file:bg-primary/10 file:text-primary-dark file:text-[12px] file:font-bold"
              onChange={e => setFile(e.target.files?.[0] ?? null)}
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-[12.5px] font-semibold text-text-dark">ملاحظات</label>
            <input
              type="text"
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] bg-white text-[13.5px] text-text-dark outline-none focus:border-primary focus:shadow-[0_0_0_3px_rgba(86,153,51,0.1)]"
              placeholder="اختياري"
              value={notes}
              onChange={e => setNotes(e.target.value)}
            />
          </div>
        </div>
        {uploadError && <p className="text-[12.5px] text-red-600 mb-3">⚠ {uploadError}</p>}
        <button
          type="submit"
          disabled={uploading}
          className="flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13.5px] font-bold disabled:opacity-50 disabled:cursor-not-allowed hover:enabled:bg-primary-dark transition-colors"
        >
          {uploading ? <FaSpinner className="animate-spin" /> : <FaUpload />}
          <span>رفع الملف</span>
        </button>
        <p className="text-[11px] text-text-light mt-2">الصيغ المسموحة: PDF, JPG, PNG — بحد أقصى 5 ميغابايت</p>
      </form>

      {/* Documents list */}
      {documents.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-14 gap-2" dir="rtl">
          <FaFolderOpen className="text-[36px] text-primary/20 mb-1" />
          <p className="text-[13.5px] font-semibold text-text-light">لا توجد ملفات مرفوعة بعد</p>
        </div>
      ) : (
        <div className="space-y-3">
          {documents.map(doc => {
            const st = STATUS_STYLES[doc.verification_status] || STATUS_STYLES.pending
            return (
              <div
                key={doc.student_document_id}
                className="flex items-center justify-between gap-4 border border-primary/12 rounded-[14px] px-5 py-3.5 bg-white flex-wrap"
                dir="rtl"
              >
                <div className="flex items-center gap-3 min-w-0">
                  <div className="w-10 h-10 rounded-[10px] bg-primary/8 flex items-center justify-center text-primary text-[16px] flex-shrink-0">
                    <FaFileAlt />
                  </div>
                  <div className="min-w-0">
                    <div className="font-bold text-[13.5px] text-text-dark truncate">{doc.file_name}</div>
                    <div className="text-[11.5px] text-text-light flex items-center gap-2 flex-wrap mt-0.5">
                      <span>{doc.document_type?.type_name || '—'}</span>
                      <span className="text-primary/30">•</span>
                      <span>{fmt(doc.uploaded_at)}</span>
                    </div>
                    {doc.verification_notes && (
                      <div className="text-[11.5px] text-text-light mt-1 italic">{doc.verification_notes}</div>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <span className={`flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold border ${st.bg} ${st.text} ${st.border}`}>
                    <st.Icon className="text-[10px]" /> {st.ar}
                  </span>
                  <button
                    className="flex items-center justify-center w-8 h-8 rounded-[8px] bg-primary/8 text-primary hover:bg-primary/15 transition-colors disabled:opacity-50"
                    title="تحميل"
                    onClick={() => handleDownload(doc)}
                    disabled={downloadingId === doc.student_document_id}
                  >
                    {downloadingId === doc.student_document_id
                      ? <FaSpinner className="animate-spin text-[12px]" />
                      : <FaDownload className="text-[12px]" />}
                  </button>
                  <button
                    className="flex items-center justify-center w-8 h-8 rounded-[8px] bg-red-500/8 text-red-600 hover:bg-red-500/15 transition-colors disabled:opacity-50"
                    title="حذف"
                    onClick={() => handleDelete(doc)}
                    disabled={deletingId === doc.student_document_id}
                  >
                    {deletingId === doc.student_document_id
                      ? <FaSpinner className="animate-spin text-[12px]" />
                      : <FaTrash className="text-[12px]" />}
                  </button>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
