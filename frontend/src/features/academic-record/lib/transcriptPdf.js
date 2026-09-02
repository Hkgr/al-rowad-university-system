import { classificationPlainText } from '../../../components/academic/CourseRequirementBadges'
import {
  academicRequirementPresentation,
  REQUIREMENT_SCOPE_LABELS,
  requirementGroupPresentation,
} from '../../../components/academic/requirementProgress'
import { paginateMeasuredSections } from '../../../utils/pdfPagination'
import { escapeHtml, exportPagedHtmlToPdf, PDF_PAGE_CONFIGS } from '../../../utils/pdfExport'
import { transcriptGenerationMetadata } from './academicRecordPresentation'

const DISCLAIMER = 'تنبيه: هذا الكشف مُولّد إلكترونيًا من نظام جامعة الرواد، ولا يُعد وثيقة رسمية أو مصدقة، ولا يكتسب الحجية إلا بعد استكمال التواقيع والأختام الرسمية المعتمدة.'

function value(value) {
  return value === null || value === undefined || value === '' ? '—' : value
}

function relationName(relation, key) {
  if (relation && typeof relation === 'object') return relation[key]
  return relation
}

function officialIdentity(student, transcript) {
  const source = student && typeof student === 'object'
    ? student
    : (transcript?.student && typeof transcript.student === 'object' ? transcript.student : transcript)
  return {
    fullName: source?.full_name || transcript?.full_name,
    studentNumber: source?.student_number || transcript?.student_number,
    college: relationName(source?.college, 'college_name') || relationName(transcript?.college, 'college_name'),
    department: relationName(source?.department, 'department_name') || relationName(transcript?.department, 'department_name'),
    program: relationName(source?.program, 'program_name') || relationName(transcript?.program, 'program_name'),
    academicLevel: relationName(source?.academic_level, 'level_name') || relationName(transcript?.academic_level, 'level_name'),
    studentStatus: relationName(source?.student_status, 'status_name'),
  }
}

export function transcriptFilename(studentNumber) {
  const sanitized = String(studentNumber || 'student').trim().replace(/[^A-Za-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '')
  return `grade-transcript-${sanitized || 'student'}.pdf`
}

function headingHtml(identity) {
  const identityFields = [
    ['اسم الطالب', identity.fullName], ['الرقم الجامعي', identity.studentNumber],
    ['الكلية', identity.college], ['القسم', identity.department],
    ['البرنامج', identity.program], ['المستوى الأكاديمي', identity.academicLevel], ['حالة الطالب', identity.studentStatus],
  ]
  return `<section dir="rtl" style="border:1px solid #d7e4d0;border-radius:10px;padding:12px;margin-bottom:12px;box-sizing:border-box;">
    <div style="display:grid;grid-template-columns:58px 1fr 58px;align-items:center;border-bottom:2px solid #569933;padding-bottom:9px;margin-bottom:10px;text-align:center;">
      <img src="/logo.png" alt="" style="width:52px;height:52px;object-fit:contain;" />
      <div><div style="font-size:17px;font-weight:900;color:#417327;">جامعة الرواد للعلوم والتقانة</div><div dir="ltr" style="font-size:9px;color:#4b5563;margin-top:2px;">Al-Rawad University for Science and Technology</div><div style="font-size:15px;font-weight:800;margin-top:5px;">كشف الدرجات الأكاديمي</div><div dir="ltr" style="font-size:9px;color:#4b5563;">Academic Transcript</div></div>
      <div></div>
    </div>
    <div style="text-align:center;color:#8a3b12;background:#fff7ed;border:1px solid #fed7aa;border-radius:5px;padding:4px;font-size:9px;font-weight:700;margin-bottom:9px;">نسخة إلكترونية غير مصدقة</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;font-size:10px;">${identityFields.map(([label, item]) => `<div style="display:flex;gap:5px;border-bottom:1px solid #edf2ea;padding:3px 0;"><strong style="color:#417327;white-space:nowrap;">${label}:</strong><span>${escapeHtml(value(item))}</span></div>`).join('')}</div>
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

function requirementHeading(scope, continuation = false) {
  const label = REQUIREMENT_SCOPE_LABELS[scope] || scope || 'متطلبات أخرى'
  return `<header style="background:#edf5e9;border-right:4px solid #569933;padding:7px 9px;margin:0 0 5px;box-sizing:border-box;font-size:11px;font-weight:800;color:#315c20;">${escapeHtml(label)}${continuation ? ' — متابعة' : ''}</header>`
}

function requirementTableHeader() {
  const cells = [
    ['مجموعة المتطلبات', '30%'], ['المطلوب', '10%'], ['المجتاز', '10%'], ['المحتسب', '10%'],
    ['مسجل', '10%'], ['قيد الطلب', '10%'], ['المتبقي', '10%'], ['التقدم', '10%'],
  ]
  return `<thead><tr style="background:#417327;color:#fff;">${cells.map(([label, width]) => `<th style="width:${width};padding:5px 3px;border:1px solid #376321;text-align:center;font-size:8px;">${label}</th>`).join('')}</tr></thead>`
}

function requirementRow(group, index) {
  const view = requirementGroupPresentation(group)
  const completed = view.completed ? 'مكتمل' : 'غير مكتمل'
  const progress = view.progress == null ? '—' : `${view.progress}%`
  const counted = view.counted === null || view.counted === undefined ? view.earned : view.counted
  const title = `<div style="font-weight:700;line-height:1.4;">${escapeHtml(value(group.group_name))}</div><div style="font-size:7px;color:#6b7280;margin-top:2px;">${escapeHtml(view.typeLabel)}${group.group_code ? ` · ${escapeHtml(group.group_code)}` : ''}</div>`
  const cells = [title, view.required, view.earned, counted, view.registered, view.pending, view.remaining, `${progress} · ${completed}`]
  return `<tr style="background:${index % 2 === 0 ? '#fff' : '#f8faf7'};">${cells.map((cell, cellIndex) => `<td style="padding:5px 3px;border:1px solid #dce7d7;text-align:${cellIndex === 0 ? 'right' : 'center'};font-size:8px;line-height:1.45;overflow-wrap:anywhere;">${cellIndex === 0 ? cell : escapeHtml(value(cell))}</td>`).join('')}</tr>`
}

function requirementTable(rowHtml) {
  return `<div style="padding-bottom:9px;box-sizing:border-box;"><table style="width:100%;table-layout:fixed;border-collapse:collapse;margin:0;">${requirementTableHeader()}<tbody>${rowHtml}</tbody></table></div>`
}

function unavailableRequirementsHtml() {
  return '<section style="border:1px solid #f3d18a;background:#fffaf0;border-radius:8px;padding:12px;margin-bottom:10px;font-size:9px;line-height:1.7;color:#7c4a03;"><strong>تقدم الخطة الدراسية غير متاح</strong><br>تعذر حساب المتطلبات بسبب إعداد أكاديمي يحتاج إلى مراجعة. بقيت بيانات الدرجات المعتمدة متاحة في هذا الكشف.</section>'
}

function emptyRequirementsHtml() {
  return '<section style="border:1px solid #dce7d7;border-radius:8px;padding:12px;margin-bottom:10px;font-size:9px;color:#6b7280;">لا توجد مجموعات متطلبات أكاديمية متاحة لهذا السجل.</section>'
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
  return `<section style="border:2px solid #569933;border-radius:8px;padding:9px;margin:2px 0 10px;box-sizing:border-box;"><h2 style="font-size:11px;color:#315c20;margin:0 0 7px;">الملخص الأكاديمي</h2><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;">${fields.map(([label, item]) => `<div style="background:#f5f9f3;border:1px solid #dce7d7;border-radius:5px;padding:6px;text-align:center;"><div style="font-size:7.5px;color:#6b7280;">${label}</div><strong style="font-size:11px;color:#25361e;">${escapeHtml(value(item))}</strong></div>`).join('')}</div></section>`
}

function disclaimerHtml() {
  return `<section style="border:1px solid #d1d5db;border-radius:8px;padding:10px;box-sizing:border-box;"><p style="font-size:9px;line-height:1.8;color:#4b5563;margin:0 0 18px;">${DISCLAIMER}</p><div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;text-align:center;font-size:9px;"><div><strong>رئيس الهيئة الامتحانية</strong><div style="height:34px;border-bottom:1px dotted #9ca3af;"></div><span style="font-size:7px;color:#9ca3af;">الاسم والتوقيع</span></div><div><strong>رئيس قسم الامتحانات</strong><div style="height:34px;border-bottom:1px dotted #9ca3af;"></div><span style="font-size:7px;color:#9ca3af;">الاسم والتوقيع</span></div><div><strong>الختم الرسمي</strong><div style="height:34px;border:1px dotted #9ca3af;border-radius:50%;width:52px;margin:5px auto 0;"></div></div></div></section>`
}

function emptyTermsHtml() {
  return '<section style="border:1px solid #dce7d7;border-radius:8px;padding:18px;text-align:center;color:#6b7280;font-size:10px;margin-bottom:10px;">لا توجد بيانات دراسية</section>'
}

function generationHtml(metadata) {
  const unit = metadata.organizationalUnit
    ? `<div><strong style="color:#417327;">الجهة التنظيمية:</strong> ${escapeHtml(metadata.organizationalUnit)}</div>`
    : ''
  return `<section style="border:1px solid #dce7d7;background:#f8faf7;border-radius:8px;padding:9px;margin-bottom:10px;display:grid;grid-template-columns:1fr 1fr${unit ? ' 1fr' : ''};gap:8px;font-size:8.5px;box-sizing:border-box;"><div><strong style="color:#417327;">تاريخ ووقت الإنشاء:</strong> ${escapeHtml(metadata.generatedAt)}</div><div><strong style="color:#417327;">تم الإنشاء بواسطة:</strong> ${escapeHtml(metadata.generatedBy)}</div>${unit}</section>`
}

export async function exportTranscriptPdf({ academicRecord }) {
  const student = academicRecord?.student
  const transcript = academicRecord?.transcript ?? {}
  const requirements = academicRecord?.requirements ?? {}
  const identity = officialIdentity(student, transcript)
  const terms = Array.isArray(transcript?.terms) ? transcript.terms : []
  const requirementPresentation = requirements?.status === 'available'
    ? academicRequirementPresentation(requirements.progress, requirements.graduation_eligibility)
    : null
  const requirementScopes = requirementPresentation?.groupedScopes ?? []
  const generation = generationHtml(transcriptGenerationMetadata(academicRecord?.generation))
  const topHtml = headingHtml(identity)
  const summary = summaryHtml(transcript?.summary)
  const disclaimer = disclaimerHtml()
  const emptyTerms = emptyTermsHtml()
  const unavailableRequirements = unavailableRequirementsHtml()
  const emptyRequirements = emptyRequirementsHtml()

  return exportPagedHtmlToPdf({
    filename: transcriptFilename(identity.studentNumber),
    pageConfig: PDF_PAGE_CONFIGS.portrait,
    requiredImages: ['/logo.png'],
    preparePages: ({ measure, contentHeightPx }) => {
      const sectionHtml = new Map([
        ['official-heading', topHtml], ['official-summary', summary], ['generation-metadata', generation],
        ['disclaimer-signatures', disclaimer], ['empty-terms', emptyTerms],
        ['requirements-unavailable', unavailableRequirements], ['requirements-empty', emptyRequirements],
      ])
      const tableData = new Map()
      const sections = [{ kind: 'atomic', id: 'official-heading', height: measure(topHtml) }]

      sections.push({ kind: 'keepTogether', id: 'official-summary', height: measure(summary) })

      if (requirements?.status !== 'available') {
        sections.push({ kind: 'atomic', id: 'requirements-unavailable', height: measure(unavailableRequirements) })
      } else if (requirementScopes.length === 0) {
        sections.push({ kind: 'atomic', id: 'requirements-empty', height: measure(emptyRequirements) })
      } else {
        requirementScopes.forEach(([scope, groups], scopeIndex) => {
          const id = `requirement-${scopeIndex}`
          const heading = requirementHeading(scope)
          const continuationHeading = requirementHeading(scope, true)
          const header = requirementTableHeader()
          const rows = groups.map((group, rowIndex) => requirementRow(group, rowIndex))
          const headerHeight = measure(`<div style="padding-bottom:9px;box-sizing:border-box;"><table style="width:100%;table-layout:fixed;border-collapse:collapse;margin:0;">${header}</table></div>`)
          tableData.set(id, { heading, continuationHeading, rows, renderRows: requirementTable })
          sections.push({
            kind: 'table', id,
            headingHeight: measure(heading), continuationHeadingHeight: measure(continuationHeading), headerHeight,
            rows: rows.map((row, rowIndex) => ({ id: String(rowIndex), height: measure(requirementTable(row)) - headerHeight })),
          })
        })
      }

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
          tableData.set(id, { heading, continuationHeading, rows, renderRows: transcriptTable })
          sections.push({
            kind: 'table', id,
            headingHeight: measure(heading), continuationHeadingHeight: measure(continuationHeading), headerHeight,
            rows: rows.map((row, rowIndex) => ({ id: String(rowIndex), height: measure(transcriptTable(row)) - headerHeight })),
          })
        })
      }

      sections.push({ kind: 'atomic', id: 'generation-metadata', height: measure(generation) })
      sections.push({ kind: 'keepTogether', id: 'disclaimer-signatures', height: measure(disclaimer) })
      const pages = paginateMeasuredSections({ sections, contentHeight: contentHeightPx })

      return pages.map(page => page.fragments.map(fragment => {
        if (fragment.kind === 'atomic') return sectionHtml.get(fragment.id)
        const data = tableData.get(fragment.id)
        const rows = fragment.rowIds.map(id => data.rows[Number(id)]).join('')
        return `${fragment.continuation ? data.continuationHeading : data.heading}${data.renderRows(rows)}`
      }).join(''))
    },
    footerHtml: ({ pageNumber, totalPages }) => `<div style="height:100%;display:grid;grid-template-columns:1fr 1fr 1fr;align-items:end;border-top:1px solid #cbd5c5;padding-top:2mm;font-size:8px;color:#6b7280;box-sizing:border-box;"><span>نسخة إلكترونية غير مصدقة</span><span style="text-align:center;">الرقم الجامعي: ${escapeHtml(value(identity.studentNumber))}</span><span style="text-align:left;">صفحة ${pageNumber} من ${totalPages}</span></div>`,
  })
}

export { DISCLAIMER as TRANSCRIPT_DISCLAIMER }
export { transcriptGenerationMetadata }
