import { useCallback, useRef, useState } from 'react'
import { FaFilePdf, FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { exportTranscriptPdf } from '../lib/transcriptPdf'
import ExamTranscriptRequestDialog from '../../exam-board/components/ExamTranscriptRequestDialog'
import { EXAM_TRANSCRIPT_TEMPLATE } from '../../exam-board/lib/examTranscriptModel'

const EXPORT_ERROR = 'تعذّر إنشاء كشف العلامات الإلكتروني. يرجى المحاولة مجدداً.'

export default function TranscriptPdfExportAction({ endpoint, onFreshRecord, className = '', template = 'default', examExportEndpoint }) {
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [requestOpen, setRequestOpen] = useState(false)
  const exporting = useRef(false)
  const examTemplate = template === EXAM_TRANSCRIPT_TEMPLATE

  const exportPdf = useCallback(async (request = {}) => {
    if (exporting.current) return

    exporting.current = true
    setLoading(true)
    setError('')

    try {
      const response = examTemplate
        ? await apiRequest(examExportEndpoint)
        : await apiRequest(endpoint)
      if (!response?.data) throw new Error('academic_record_missing')

      onFreshRecord?.(response.data)
      if (examTemplate) {
        await exportTranscriptPdf({ academicRecord: response.data, template, request })
        setRequestOpen(false)
      } else {
        await exportTranscriptPdf({ academicRecord: response.data })
      }
    } catch {
      setError(EXPORT_ERROR)
    } finally {
      exporting.current = false
      setLoading(false)
    }
  }, [endpoint, examExportEndpoint, examTemplate, onFreshRecord, template])

  return (
    <div className={`flex flex-col items-start gap-2 ${className}`} dir="rtl">
      <button
        type="button"
        onClick={() => { if (examTemplate) { setError(''); setRequestOpen(true) } else exportPdf() }}
        disabled={loading}
        className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-4 py-2.5 text-[12.5px] font-black text-white transition-colors hover:bg-primary-dark disabled:cursor-not-allowed disabled:opacity-55"
      >
        {loading ? <FaSpinner className="animate-spin" aria-hidden="true" /> : <FaFilePdf aria-hidden="true" />}
        {loading ? 'جاري إنشاء الملف...' : 'استخراج كشف العلامات الإلكتروني'}
      </button>
      {error ? <p className="text-[12.5px] font-semibold text-red-700" role="alert">⚠ {error}</p> : null}
      {examTemplate && requestOpen ? <ExamTranscriptRequestDialog busy={loading} error={error} onClose={() => setRequestOpen(false)} onExport={exportPdf} /> : null}
    </div>
  )
}

export { EXPORT_ERROR as TRANSCRIPT_EXPORT_ERROR }
