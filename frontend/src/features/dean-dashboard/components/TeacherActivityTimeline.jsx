import { FaHistory } from 'react-icons/fa'
import { TabState } from './DeanStudentRecordPanels'
import { formatDisplayDate } from '../utils/teacherDisplay'

export default function TeacherActivityTimeline({ loading, error, events }) {
  return (
    <TabState
      loading={loading}
      error={error}
      empty={!loading && !error && events.length === 0 ? 'لا توجد أحداث مسجّلة يمكن عرضها من التكليفات أو الجلسات' : ''}
      emptyIcon={FaHistory}
    >
      <ol className="relative pr-4 border-r-2 border-primary/15 space-y-4">
        {events.map((event, index) => (
          <li key={`${event.type}-${event.date}-${index}`} className="relative pr-5">
            <span className="absolute top-1.5 -right-[9px] w-3.5 h-3.5 rounded-full bg-white border-2 border-primary" aria-hidden="true" />
            <p className="text-[11.5px] text-text-light mb-0.5">{formatDisplayDate(event.date)}</p>
            <p className="text-[13.5px] font-extrabold text-text-dark">{event.title}</p>
            {event.description && (
              <p className="text-[12.5px] text-text-gray mt-0.5 break-words">{event.description}</p>
            )}
          </li>
        ))}
      </ol>
    </TabState>
  )
}
