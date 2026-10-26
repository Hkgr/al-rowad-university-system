// Browser acceptance harness only. This is not an application route/build entry.
import { createRoot } from 'react-dom/client'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import ExamStudentAcademicRecordPage from '../../src/features/exam-board/pages/ExamStudentAcademicRecordPage'
import ApprovalsPage from '../../src/features/exam-board/pages/ApprovalsPage'
import { exportTranscriptPdf } from '../../src/features/academic-record/lib/transcriptPdf'
import '../../src/styles/global.css'

const root = createRoot(document.getElementById('root'))
window.pdfFixture = {
  exportRecord: (academicRecord, template = 'exam-board-approved', request = {}) => exportTranscriptPdf({ academicRecord, template, request }),
  show: (mode = 'exam') => {
    localStorage.setItem('user', JSON.stringify({
      username: 'synthetic.exam', roles: ['exam_officer'],
      permissions: ['grades.view', 'students.view'], access_scopes: [{ type: 'university', id: 1 }],
    }))
    localStorage.setItem('token', 'synthetic-local-test-token')
    root.render(
      <div className="min-h-screen bg-bg-light p-6 max-[460px]:p-3">
        <MemoryRouter key={mode} initialEntries={['/exam-board/grade-sheet/1']}>
          <Routes>
            <Route path="/exam-board/grade-sheet/:studentId" element={mode === 'approvals'
              ? <ApprovalsPage />
              : <ExamStudentAcademicRecordPage transcriptTemplate={mode === 'exam' ? 'exam-board-approved' : 'default'} />} />
          </Routes>
        </MemoryRouter>
      </div>,
    )
  },
}
window.pdfFixtureReady = true
