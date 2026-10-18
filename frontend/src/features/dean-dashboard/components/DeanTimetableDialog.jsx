import { useMemo, useState } from 'react'
import { FaPlus, FaSpinner, FaTimes } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import OfficialTimetable from '../../registration-requests/OfficialTimetable'
import { ISO_WEEKDAY_LABELS, TIMETABLE_COMPONENT_LABELS, timetableLockedReason } from '../../registration-requests/courseOfferingTimetable'

function emptySlot(component) {
  return { component_type: component || 'theoretical', day_of_week: 1, start_time: '08:00', end_time: '10:00', location_label: '' }
}

export default function DeanTimetableDialog({ offeringId, schedule, onClose, onSaved }) {
  const required = schedule?.required_components ?? []
  const initial = useMemo(() => (schedule?.slots ?? []).map(slot => ({
    component_type: slot.component_type,
    day_of_week: Number(slot.day_of_week),
    start_time: String(slot.start_time || '').slice(0, 5),
    end_time: String(slot.end_time || '').slice(0, 5),
    location_label: slot.location_label || '',
  })), [schedule])
  const [slots, setSlots] = useState(initial)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  function update(index, field, value) {
    setSlots(current => current.map((slot, slotIndex) => slotIndex === index ? { ...slot, [field]: value } : slot))
  }

  async function save() {
    setBusy(true)
    setError('')
    try {
      const response = await apiRequest(`/v1/dean/registration-offerings/${offeringId}/timetable`, {
        method: 'PUT',
        body: JSON.stringify({ slots }),
      })
      await onSaved(response?.data)
    } catch (requestError) {
      setError(requestError.message || 'تعذّر حفظ الجدول الأسبوعي.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" dir="rtl">
      <div className="max-h-[90vh] w-full max-w-3xl overflow-auto rounded-[18px] bg-white p-5 shadow-2xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 className="text-[18px] font-black text-text-dark">الجدول الأسبوعي الرسمي</h2>
            <p className="mt-1 text-[12px] text-text-light">يُحفظ الجدول كاملاً في عملية ذرية واحدة.</p>
          </div>
          <button type="button" onClick={onClose} aria-label="إغلاق"><FaTimes /></button>
        </div>

        {schedule?.editable !== true ? (
          <p className="mt-4 rounded-[10px] border border-amber-200 bg-amber-50 px-3 py-2 text-[12.5px] text-amber-900">
            الجدول مقفل: {timetableLockedReason(schedule?.locked_reason) || 'لم يعد التعديل مسموحاً'}
          </p>
        ) : null}
        {schedule?.initialization_only === true ? (
          <p className="mt-4 rounded-[10px] border border-blue-200 bg-blue-50 px-3 py-2 text-[12.5px] text-blue-900">
            هذا تعريف الجدول للمرة الأولى. يجب حفظ جدول مكتمل، وسيُقفل أي تعديل لاحق إذا بدأ التسجيل أو اعتمد الطلاب على الطرح.
          </p>
        ) : null}
        {required.length === 0 ? (
          <p className="mt-4 rounded-[10px] border border-red-200 bg-red-50 px-3 py-2 text-[12.5px] text-red-800">
            مكونات التدريس للمقرر غير محددة، ولا يمكن إنشاء جدول قابل للتسجيل.
          </p>
        ) : null}

        <div className="mt-4 space-y-3">
          {slots.map((slot, index) => (
            <div key={index} className="grid grid-cols-6 gap-2 rounded-[12px] border border-primary/15 p-3 max-[700px]:grid-cols-2">
              <select value={slot.component_type} onChange={event => update(index, 'component_type', event.target.value)} className="rounded-[9px] border px-2 py-2">
                {required.map(component => <option key={component} value={component}>{TIMETABLE_COMPONENT_LABELS[component] || component}</option>)}
              </select>
              <select value={slot.day_of_week} onChange={event => update(index, 'day_of_week', Number(event.target.value))} className="rounded-[9px] border px-2 py-2">
                {Object.entries(ISO_WEEKDAY_LABELS).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
              <input type="time" value={slot.start_time} onChange={event => update(index, 'start_time', event.target.value)} className="rounded-[9px] border px-2 py-2" />
              <input type="time" value={slot.end_time} onChange={event => update(index, 'end_time', event.target.value)} className="rounded-[9px] border px-2 py-2" />
              <input value={slot.location_label} maxLength={150} placeholder="الموقع (اختياري)" onChange={event => update(index, 'location_label', event.target.value)} className="rounded-[9px] border px-2 py-2" />
              <button type="button" onClick={() => setSlots(current => current.filter((_, slotIndex) => slotIndex !== index))} className="rounded-[9px] border border-red-200 text-red-700">إزالة</button>
            </div>
          ))}
        </div>

        {schedule?.editable === true && required.length > 0 ? (
          <button type="button" onClick={() => setSlots(current => [...current, emptySlot(required[0])])} className="mt-3 flex items-center gap-2 rounded-[10px] border border-primary/25 px-3 py-2 text-[12.5px] font-bold text-primary-dark">
            <FaPlus /> إضافة موعد
          </button>
        ) : null}

        {error ? <p className="mt-3 text-[12.5px] text-red-700">{error}</p> : null}
        <div className="mt-5"><OfficialTimetable schedule={{ ...schedule, slots }} /></div>
        <div className="mt-5 flex gap-2">
          <button type="button" onClick={save} disabled={busy || schedule?.editable !== true || required.length === 0} className="rounded-[10px] bg-primary px-5 py-2.5 font-bold text-white disabled:opacity-40">
            {busy ? <FaSpinner className="animate-spin" /> : 'حفظ الجدول'}
          </button>
          <button type="button" onClick={onClose} className="rounded-[10px] border px-5 py-2.5 font-bold">إلغاء</button>
        </div>
      </div>
    </div>
  )
}
