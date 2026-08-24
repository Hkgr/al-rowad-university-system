import { classificationPlainText } from '../../../components/academic/CourseRequirementBadges'
import { paginateMeasuredSections } from '../../../utils/pdfPagination'
import { escapeHtml, exportPagedHtmlToPdf, PDF_PAGE_CONFIGS } from '../../../utils/pdfExport'

const DISCLAIMER = 'تنبيه: هذا الكشف مُولّد إلكترونيًا من نظام جامعة الرواد، ولا يُعد وثيقة رسمية أو مصدقة، ولا يكتسب الحجية إلا بعد استكمال التواقيع والأختام الرسمية المعتمدة.'

function value(value) {
  return value === null || value === undefined || value === '' ? '—' : value
}

function relationName(relation, key) {
  if (relation && typeof relation === 'object') return relation[key]
  return relation
}

function officialIdentity(transcript, selectedStudent) {
  const student = transcript?.student && typeof transcript.student === 'object' ? transcript.student : transcript
  const selectedName = [selectedStudent?.first_name, selectedStudent?.last_name].filter(Boolean).join(' ').trim()
  return {
    fullName: student?.full_name || transcript?.full_name || selectedName,
    studentNumber: student?.student_number || transcript?.student_number || selectedStudent?.student_number,
    college: relationName(student?.college, 'college_name') || relationName(transcript?.college, 'college_name'),
    department: relationName(student?.department, 'department_name') || relationName(transcript?.department, 'department_name'),
    program: relationName(student?.program, 'program_name') || relationName(transcript?.program, 'program_name'),
    academicLevel: relationName(student?.academic_level, 'level_name') || relationName(transcript?.academic_level, 'level_name'),
  }
}

export function transcriptFilename(studentNumber) {
  const sanitized = String(studentNumber || 'student').trim().replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '')
  return `grade-transcript-${sanitized || 'student'}.pdf`
}

function headingHtml(identity, generatedAt) {
  const identityFields = [
    ['اسم الطالب', identity.fullName], ['الرقم الجامعي', identity.studentNumber],
    ['الكلية', identity.college], ['القسم', identity.department],
    ['البرنامج', identity.program], ['المستوى الأكاديمي', identity.academicLevel],
  ]
  return `<section dir="rtl" style="border:1px solid #d7e4d0;border-radius:10px;padding:12px;margin-bottom:12px;box-sizing:border-box;">
    <div style="display:grid;grid-template-columns:58px 1fr 58px;align-items:center;border-bottom:2px solid #569933;padding-bottom:9px;margin-bottom:10px;text-align:center;">
      <img src="/logo.png" alt="" style="width:52px;height:52px;object-fit:contain;" />
      <div><div style="font-size:17px;font-weight:900;color:#417327;">جامعة الرواد للعلوم والتقانة</div><div dir="ltr" style="font-size:9px;color:#4b5563;margin-top:2px;">Al-Rawad University for Science and Technology</div><div style="font-size:15px;font-weight:800;margin-top:5px;">كشف الدرجات الأكاديمي</div><div dir="ltr" style="font-size:9px;color:#4b5563;">Academic Transcript</div></div>
      <div></div>
    </div>
    <div style="text-align:center;color:#8a3b12;background:#fff7ed;border:1px solid #fed7aa;border-radius:5px;padding:4px;font-size:9px;font-weight:700;margin-bottom:9px;">نسخة إلكترونية غير مصدقة</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;font-size:10px;">${identityFields.map(([label, item]) => `<div style="display:flex;gap:5px;border-bottom:1px solid #edf2ea;padding:3px 0;"><strong style="color:#417327;white-space:nowrap;">${label}:</strong><span>${escapeHtml(value(item))}</span></div>`).join('')}</div>
    <div style="text-align:left;color:#6b7280;font-size:8px;margin-top:7px;">تاريخ الإنشاء: ${escapeHtml(generatedAt)}</div>
  </section>`
}

function termHeading(term, continuation = false) {
  const year = value(term.academic_year?.year_name)
  const semester = value(term.semester?.semester_name)
  return `<header style="display:flex;justify-content:space-between;align-items:center;background:#edf5e9;border-right:4px solid #569933;padding:7px 9px;margin:0 0 5px;box-sizing:border-box;font-size:10px;"><strong style="color:#315c20;font-size:11px;">${escapeHtml(year)} · ${escapeHtml(semester)}${continuation ? ' — متابعة' : ''}</strong><span>المعدل الفصلي: <strong>${escapeHtml(value(term.term_gpa))}</strong></span></header>`
}

function tableHeader() {
  const cells = [
    ['المقرر / الرمز', '32%'], ['الساعات', '8%'], ['عملي', '10%'], ['نظري', '10%'],
    ['المجموع', '10%'], ['التقدير', '10%'], ['الحالة', '20%'],
  ]
  return `<thead><tr style="background:#417327;color:#ffffff;">${cells.map(([label, width]) => `<th style="width:${width};padding:6px 4px;border:1px solid #376321;text-align:center;font-size:8.5px;">${label}</th>`).join('')}</tr></thead>`
}

function statusText(course) {
  const code = String(course?.result_status?.status_code || '').toLowerCase()
  if (code === 'passed') return 'ناجح'
  if (code === 'failed') return 'راسب'
  if (code === 'deprived') return 'محروم'
  return course?.result_status?.status_name || '—'
}

function courseRow(course, index) {
  const classification = classificationPlainText(course?.requirement_classification)
  const secondary = classification ? `<div style="font-size:7px;color:#6b7280;margin-top:2px;line-height:1.4;">${escapeHtml(classification)}</div>` : ''
  const cells = [
    `<div style="font-weight:700;line-height:1.45;">${escapeHtml(value(course?.course_name))}</div><div dir="ltr" style="font-size:7.5px;color:#6b7280;text-align:right;">${escapeHtml(value(course?.course_code))}</div>${secondary}`,
    escapeHtml(value(course?.credit_hours)), escapeHtml(value(course?.practical_mark)), escapeHtml(value(course?.theoretical_mark)),
    escapeHtml(value(course?.final_mark)), escapeHtml(value(course?.letter_grade)), escapeHtml(statusText(course)),
  ]
  return `<tr style="background:${index % 2 === 0 ? '#ffffff' : '#f8faf7'};">${cells.map((cell, cellIndex) => `<td style="padding:6px 4px;border:1px solid #dce7d7;text-align:${cellIndex === 0 ? 'right' : 'center'};font-size:8.5px;line-height:1.4;overflow-wrap:anywhere;">${cell}</td>`).join('')}</tr>`
}

function transcriptTable(rowHtml) {
  return `<div style="padding-bottom:9px;box-sizing:border-box;"><table style="width:100%;table-layout:fixed;border-collapse:collapse;margin:0;">${tableHeader()}<tbody>${rowHtml}</tbody></table></div>`
}

function summaryHtml(summary = {}) {
  const fields = [
    ['المعدل التراكمي', summary.cgpa],
    ['المقررات المحتسبة', summary.approved_courses_count],
    ['المقررات الناجحة', summary.passed_courses_count],
    ['المقررات الراسبة', summary.failed_courses_count],
    ['المقررات المحروم منها', summary.deprived_courses_count],
    ['الساعات المحاولة', summary.total_attempted_credit_hours],
    ['الساعات المجتازة', summary.total_passed_credit_hours],
    ['الساعات الراسبة', summary.total_failed_credit_hours],
  ]
  return `<section style="border:2px solid #569933;border-radius:8px;padding:9px;margin:2px 0 10px;box-sizing:border-box;"><h2 style="font-size:11px;color:#315c20;margin:0 0 7px;">الخلاصة الرسمية</h2><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;">${fields.map(([label, item]) => `<div style="background:#f5f9f3;border:1px solid #dce7d7;border-radius:5px;padding:6px;text-align:center;"><div style="font-size:7.5px;color:#6b7280;">${label}</div><strong style="font-size:11px;color:#25361e;">${escapeHtml(value(item))}</strong></div>`).join('')}</div></section>`
}

function disclaimerHtml() {
  return `<section style="border:1px solid #d1d5db;border-radius:8px;padding:10px;box-sizing:border-box;"><p style="font-size:9px;line-height:1.8;color:#4b5563;margin:0 0 18px;">${DISCLAIMER}</p><div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center;font-size:9px;"><div><strong>رئيس الهيئة الامتحانية</strong><div style="height:34px;border-bottom:1px dotted #9ca3af;"></div><span style="font-size:7px;color:#9ca3af;">الاسم والتوقيع</span></div><div><strong>رئيس قسم الامتحانات</strong><div style="height:34px;border-bottom:1px dotted #9ca3af;"></div><span style="font-size:7px;color:#9ca3af;">الاسم والتوقيع</span></div><div><strong>الختم الرسمي</strong><div style="height:34px;border:1px dotted #9ca3af;border-radius:50%;width:52px;margin:5px auto 0;"></div></div></div></section>`
}

function emptyTermsHtml() {
  return '<section style="border:1px solid #dce7d7;border-radius:8px;padding:18px;text-align:center;color:#6b7280;font-size:10px;margin-bottom:10px;">لا توجد بيانات دراسية</section>'
}

function generationTimestamp(now = new Date()) {
  return new Intl.DateTimeFormat('ar-SY', {
    timeZone: 'Asia/Damascus', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).format(now)
}

export async function exportTranscriptPdf({ transcript, selectedStudent = null }) {
  const identity = officialIdentity(transcript, selectedStudent)
  const terms = Array.isArray(transcript?.terms) ? transcript.terms : []
  const generatedAt = generationTimestamp()
  const topHtml = headingHtml(identity, generatedAt)
  const summary = summaryHtml(transcript?.summary)
  const disclaimer = disclaimerHtml()
  const emptyTerms = emptyTermsHtml()

  return exportPagedHtmlToPdf({
    filename: transcriptFilename(identity.studentNumber),
    pageConfig: PDF_PAGE_CONFIGS.portrait,
    requiredImages: ['/logo.png'],
    preparePages: ({ measure, contentHeightPx }) => {
      const sectionHtml = new Map([
        ['official-heading', topHtml], ['official-summary', summary], ['disclaimer-signatures', disclaimer], ['empty-terms', emptyTerms],
      ])
      const tableData = new Map()
      const sections = [{ kind: 'atomic', id: 'official-heading', height: measure(topHtml) }]

      if (terms.length === 0) {
        sections.push({ kind: 'atomic', id: 'empty-terms', height: measure(emptyTerms) })
      } else {
        terms.forEach((term, termIndex) => {
          const id = `term-${termIndex}`
          const heading = termHeading(term)
          const continuationHeading = termHeading(term, true)
          const header = tableHeader()
          const courses = Array.isArray(term.courses) && term.courses.length > 0 ? term.courses : [null]
          const rows = courses.map((course, rowIndex) => course
            ? courseRow(course, rowIndex)
            : '<tr><td colspan="7" style="padding:10px;text-align:center;border:1px solid #dce7d7;color:#6b7280;font-size:9px;">لا توجد مقررات في هذا الفصل</td></tr>')
          const headerHeight = measure(`<div style="padding-bottom:9px;box-sizing:border-box;"><table style="width:100%;table-layout:fixed;border-collapse:collapse;margin:0;">${header}</table></div>`)
          tableData.set(id, { heading, continuationHeading, rows })
          sections.push({
            kind: 'table', id,
            headingHeight: measure(heading), continuationHeadingHeight: measure(continuationHeading), headerHeight,
            rows: rows.map((row, rowIndex) => ({ id: String(rowIndex), height: measure(transcriptTable(row)) - headerHeight })),
          })
        })
      }

      sections.push({ kind: 'keepTogether', id: 'official-summary', height: measure(summary) })
      sections.push({ kind: 'keepTogether', id: 'disclaimer-signatures', height: measure(disclaimer) })
      const pages = paginateMeasuredSections({ sections, contentHeight: contentHeightPx })

      return pages.map(page => page.fragments.map(fragment => {
        if (fragment.kind === 'atomic') return sectionHtml.get(fragment.id)
        const data = tableData.get(fragment.id)
        const rows = fragment.rowIds.map(id => data.rows[Number(id)]).join('')
        return `${fragment.continuation ? data.continuationHeading : data.heading}${transcriptTable(rows)}`
      }).join(''))
    },
    footerHtml: ({ pageNumber, totalPages }) => `<div style="height:100%;display:grid;grid-template-columns:1fr 1fr 1fr;align-items:end;border-top:1px solid #cbd5c5;padding-top:2mm;font-size:8px;color:#6b7280;box-sizing:border-box;"><span>نسخة إلكترونية غير مصدقة</span><span style="text-align:center;">الرقم الجامعي: ${escapeHtml(value(identity.studentNumber))}</span><span style="text-align:left;">صفحة ${pageNumber} من ${totalPages}</span></div>`,
  })
}

export { DISCLAIMER as TRANSCRIPT_DISCLAIMER }
