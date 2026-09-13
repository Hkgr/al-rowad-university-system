// Render existing components unchanged. Reference-only, no backend writes.
import { useState } from 'react'
import { createRoot } from 'react-dom/client'
import ManualGradeDialog from '../../src/features/exam-board/components/ManualGradeDialog'
import DeanTimetableDialog from '../../src/features/dean-dashboard/components/DeanTimetableDialog'
import '../../src/styles/global.css'

export default function References() {
  const [view, setView] = useState('confirmation')
  return <main className="min-h-screen bg-gray-50 p-6" dir="rtl">
    <h1>مكونات المشروع المرجعية — بيانات اختبار فقط</h1>
    <button onClick={() => setView('confirmation')}>نافذة التأكيد المرجعية</button>
    <button onClick={() => setView('editor')}>نافذة التحرير المرجعية</button>
    {view === 'confirmation' && <ManualGradeDialog title="تأكيد تعديل العلامات" onCancel={() => setView(null)} onConfirm={() => setView(null)}><p>راجع التغييرات قبل تأكيد الحفظ.</p></ManualGradeDialog>}
    {view === 'editor' && <DeanTimetableDialog offeringId={1} schedule={{ schema_ready: true, editable: false, locked_reason: 'registration_started', required_components: ['theoretical'], slots: [{ component_type: 'theoretical', day_of_week: 1, start_time: '08:00', end_time: '10:00', location_label: 'قاعة اختبار' }] }} onClose={() => setView(null)} onSaved={() => { throw Error('Reference fixture must not write') }} />}
  </main>
}
createRoot(document.getElementById('root')).render(<References />)
