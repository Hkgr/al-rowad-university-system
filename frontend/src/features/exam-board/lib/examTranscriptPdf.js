import { escapeHtml, exportPagedHtmlToPdf, PDF_PAGE_CONFIGS } from '../../../utils/pdfExport'
import { PdfPaginationError } from '../../../utils/pdfPagination'
import { resultSymbol } from '../../academic-record/lib/resultSymbols'
import { arabicMarkWords, displayNumber, EXAM_TRANSCRIPT_TEMPLATE, pairExamTerms } from './examTranscriptModel'

const GREEN = '#ccdfb5'
const LIGHT_GREEN = '#e5efda'
const PAGE = Object.freeze({ ...PDF_PAGE_CONFIGS.portrait, marginMm: 6, footerHeightMm: 4, footerGapMm: 1 })
const CSS = '<style>' + [
  '.exam-transcript{font-family:Cairo,sans-serif;font-size:9px;line-height:1.35;color:#000;direction:rtl;font-weight:600}',
  '.exam-transcript *{box-sizing:border-box}',
  '.exam-transcript table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;border:.5px solid #111;margin:0;color:#000}',
  '.exam-transcript th,.exam-transcript td{border:0;border-left:.5px solid #111;border-bottom:.5px solid #111;padding:2px 3px;vertical-align:middle;text-align:center;overflow-wrap:anywhere}',
  '.exam-transcript tr>:last-child{border-left:0}',
  '.exam-transcript tbody:last-child>tr:last-child>*,.exam-transcript tfoot>tr:last-child>*{border-bottom:0}',
  '.exam-transcript th{font-weight:800}',
  '.exam-transcript bdi{unicode-bidi:isolate}',
  '.exam-transcript .exam-head{display:grid;grid-template-columns:32% 36% 32%;align-items:center;border-bottom:1px solid #7da456;min-height:66px;margin:0 0 3px}',
  '.exam-transcript .exam-head .official{font-size:11px;line-height:1.35;font-weight:800;text-align:right;background:linear-gradient(145deg,#e6efdf,white 65%)}',
  '.exam-transcript .exam-head h1{font-size:18px;font-weight:800;margin:0;text-align:center}',
  '.exam-transcript .exam-logo{display:flex;justify-content:flex-end;background:linear-gradient(150deg,white 35%,#e3eedb)}',
  '.exam-transcript .exam-logo img{width:95px;height:76px;object-fit:contain}',
  '.exam-transcript .exam-intro{border: .65px solid #111;padding:4px;font-size:9px;line-height:1.6;text-align:right}',
  '.exam-transcript .exam-identity th{background:' + GREEN + '}',
  '.exam-transcript .exam-identity td{height:21px;font-size:9px}',
  '.exam-transcript .exam-pair{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6mm;align-items:start;margin-bottom:2px}',
  '.exam-transcript .exam-term-title th{background:' + LIGHT_GREEN + ';font-size:8px;line-height:1.5;height:21px}',
  '.exam-transcript .exam-term-columns th{background:' + LIGHT_GREEN + ';font-size:8px;line-height:1.4;padding:2px 1px}',
  '.exam-transcript .exam-course td{font-size:8px;line-height:1.45;height:12px;padding:1px 2px}',
  '.exam-transcript .exam-course .exam-course-name{text-align:right}',
  '.exam-transcript .exam-averages td{background:' + GREEN + ';font-size:8px;line-height:1.5;height:19px}',
  '.exam-transcript .exam-request{border:.65px solid #111;border-bottom:0;padding:4px 3px;font-size:9px;line-height:1.7;text-align:right}',
  '.exam-transcript .exam-legend th{background:' + GREEN + ';font-size:7px;padding:2px 1px}',
  '.exam-transcript .exam-legend td{font-size:7px;padding:2px 1px;line-height:1.4}',
  '.exam-transcript .exam-note{border:.65px solid #111;border-top:0;color:#b71515;text-align:right;font-weight:800;padding:9px 3px 4px;font-size:10px}',
  '.exam-transcript .exam-signatures{display:grid;grid-template-columns:repeat(4,1fr);text-align:center;font-weight:800;font-size:9px;padding-top:5px;min-height:45px}',
].join('') + '</style>'

const text = item => escapeHtml(item, '')
const ltr = item => '<bdi dir="ltr">' + text(item) + '</bdi>'

function heading(student, identity) {
  const fields = [
    [['الكلية', student.college?.college_name], ['القسم', student.department?.department_name]],
    [['الاسم والكنية', student.full_name], ['اسم الأب', identity.father_name], ['اسم الأم', identity.mother_name], ['الجنس', { male: 'ذكر', female: 'أنثى' }[identity.gender] ?? identity.gender]],
    [['الرقم الجامعي', student.student_number], ['تاريخ الولادة', identity.date_of_birth], ['مكان الولادة', identity.place_of_birth], ['الجنسية', identity.nationality]],
  ]
  return '<header class="exam-head"><div class="official">الجمهورية العربية السورية<br>وزارة التعليم العالي والبحث العلمي<br><span style="color:#608d47">جامعة الرواد للعلوم والتقانة<br><bdi dir="ltr">R.U.S.T.</bdi></span><br>مسارات حضارية ورؤية مستدامة</div><h1>كشف درجات</h1><div class="exam-logo"><img src="/logo.png" alt="جامعة الرواد للعلوم والتقانة"></div></header>'
    + '<div class="exam-intro">ندرج فيما يلي كشفاً بدرجات المقررات التي درسها الطالب المذكورة بياناته في هذه الوثيقة وذلك في السنوات التي قضاها في :</div>'
    + '<table class="exam-identity"><colgroup>' + [10, 24, 8, 16, 8, 14, 8, 12].map(width => '<col style="width:' + width + '%">').join('') + '</colgroup><tbody>'
    + fields.map((row, rowIndex) => '<tr>' + row.map(([label, item]) => '<th>' + label + '</th><td' + (rowIndex === 0 ? ' colspan="3"' : '') + '>' + (label === 'الرقم الجامعي' || label === 'تاريخ الولادة' ? ltr(item) : text(item)) + '</td>').join('') + '</tr>').join('')
    + '</tbody></table><div style="height:4px"></div>'
}

function courseRow(course, rowId) {
  const symbol = resultSymbol(course)
  const mark = ['I', 'W'].includes(symbol) ? null : course.final_mark
  const points = ['I', 'W'].includes(symbol) ? null : course.grade_points
  return '<tr class="exam-course" data-exam-course-id="' + text(rowId) + '">'
    + '<td>' + ltr(course.course_code) + '</td>'
    + '<td class="exam-course-name">' + text(course.course_name) + '</td>'
    + '<td>' + ltr(displayNumber(course.credit_hours)) + '</td>'
    + '<td>' + ltr(displayNumber(mark)) + '</td>'
    + '<td>' + ltr(displayNumber(points, 2)) + '</td>'
    + '<td>' + ltr(symbol === '—' ? '' : symbol) + '</td>'
    + '<td>' + text(arabicMarkWords(mark)) + '</td></tr>'
}

function termTable(term, start = 0, count = term?.courses?.length ?? 0, minimumRows = 9) {
  if (!term) return ''
  const courses = Array.isArray(term.courses) ? term.courses : []
  const final = start + count >= courses.length
  const title = [term.historical_academic_level, term.semester?.semester_name].filter(Boolean).join(' / ')
  const key = String(term.academic_year?.academic_year_id) + '-' + String(term.semester?.semester_id)
  const selected = courses.slice(start, start + count)
  const blankRow = '<tr class="exam-course" aria-hidden="true">' + '<td>&nbsp;</td>'.repeat(7) + '</tr>'
  const rows = selected.map((course, index) => courseRow(course, course.registration_id ?? key + '-' + (start + index))).join('')
    + blankRow.repeat(Math.max(0, minimumRows - selected.length))
  return '<table data-exam-term="' + text(key) + '"><colgroup><col style="width:14%"><col style="width:26%"><col style="width:15%"><col style="width:8%"><col style="width:8%"><col style="width:7%"><col style="width:22%"></colgroup><thead>'
    + '<tr class="exam-term-title"><th colspan="5">' + text(title) + (start ? ' — متابعة' : '') + ' / العام الدراسي</th><th colspan="2">' + ltr(term.academic_year?.year_name) + '</th></tr>'
    + '<tr class="exam-term-columns"><th rowspan="2">رمز المقرر</th><th rowspan="2">اسم المقرر</th><th rowspan="2">الساعات<br>المعتمدة</th><th colspan="4">معلومات الدرجة</th></tr>'
    + '<tr class="exam-term-columns"><th>رقماً</th><th>نقطي</th><th>حرفي</th><th>كتابةً</th></tr></thead><tbody>' + rows + '</tbody>'
    + '<tfoot><tr class="exam-averages">' + (final
      ? '<td colspan="2">المعدل الفصلي</td><td>' + ltr(displayNumber(term.term_gpa, 2)) + '</td><td colspan="3">المعدل التراكمي</td><td>' + ltr(displayNumber(term.cumulative_gpa, 2)) + '</td>'
      : '<td colspan="7">يتبع — المعدلات في نهاية جدول الفصل</td>') + '</tr></tfoot></table>'
}

function pairHtml(columns) {
  return '<div class="exam-pair"><div>' + columns[0] + '</div><div>' + columns[1] + '</div></div>'
}

function ending(scale, request) {
  // The bands are supplied by OfficialGradeScale, not copied into a parallel JS scale.
  const bands = [...scale.bands, scale.failure]
  const numeric = bands.map((band, index) => ltr(String(band.minimum) + ' ≤ X' + (index ? ' < ' + bands[index - 1].minimum : ' ≤ 100')))
  const statusRows = [
    ['W', 'IP', 'Z', 'I', 'AU', 'TC', 'F'],
    ['انسحاب الطالب', 'مستمر', 'محروم / محظور', 'غير مكتمل', 'مستمع', 'مقرر مكافئ', 'راسب'],
    ['Withdraw', 'In-Progress', 'Denial', 'In-Complete', 'Audit', 'Transfer Credit', 'Failed'],
  ]
  const gradeRows = [numeric, bands.map(band => ltr(band.letter)), bands.map(band => ltr(displayNumber(band.points, 2)))]
  const rowLabels = ['المكافئ المئوي', 'المكافئ الحرفي', 'المكافئ النقطي']
  return '<div class="exam-request">أعطي/ت الطالب/ة هذا الكشف بموجب الطلب المقدم إلى شؤون الطلاب رقم : <span style="display:inline-block;min-width:55px">' + ltr(request.number) + '</span> / تاريخ : <span style="display:inline-block;min-width:75px">' + ltr(request.date) + '</span> م</div>'
    + '<div style="display:grid;grid-template-columns:64% 36%;direction:rtl"><table class="exam-legend"><tbody>'
    + gradeRows.map((cells, index) => '<tr><th>' + rowLabels[index] + '</th>' + cells.map(cell => '<td>' + cell + '</td>').join('') + '</tr>').join('')
    + '</tbody></table><table class="exam-legend"><tbody>'
    + statusRows.map((cells, index) => '<tr><th>' + (index === 0 ? 'رمز الحالة' : index === 1 ? 'بيان الرمز' : '') + '</th>' + cells.map(cell => '<td>' + (index === 1 ? text(cell) : ltr(cell)) + '</td>').join('') + '</tr>').join('')
    + '</tbody></table></div><div class="exam-note">ملاحظة : أي شطب أو حك أو تعديل في هذا الكشف يلغيه</div>'
    + '<div class="exam-signatures"><div>المسجل</div><div>المدقق</div><div>رئيس دائرة الامتحانات</div><div>رئيس الجامعة</div></div>'
}

function wrapped(html) {
  return '<div class="exam-transcript" dir="rtl">' + CSS + html + '</div>'
}

/**
 * Measure whole paired tables first. Only oversized pairs split, always at
 * course boundaries; each continuation repeats its term and column headings.
 */
export function paginateExamTermPairs({ terms, availableHeight, measure }) {
  const pages = [{ html: [], height: 0 }]
  const heightOf = html => measure(wrapped(html)) + 2
  const advance = () => {
    if (pages.at(-1).html.length) pages.push({ html: [], height: 0 })
  }
  const append = html => {
    const height = heightOf(html)
    if (height > availableHeight) throw new PdfPaginationError('pdf_atomic_block_too_tall', 'An exam transcript course cannot fit on one page.')
    if (pages.at(-1).height + height > availableHeight) advance()
    pages.at(-1).html.push(html)
    pages.at(-1).height += height
  }
  for (const pair of pairExamTerms(terms)) {
    const complete = pairHtml(pair.map(term => termTable(term)))
    if (heightOf(complete) <= availableHeight) {
      append(complete)
      continue
    }
    advance()
    const offsets = [0, 0]
    const done = pair.map(term => !term)
    while (!done.every(Boolean)) {
      const columns = pair.map((term, side) => {
        if (done[side]) return ''
        const remaining = (term.courses?.length ?? 0) - offsets[side]
        if (remaining === 0) {
          done[side] = true
          return termTable(term)
        }
        let low = 1
        let high = remaining
        let fitting = 0
        while (low <= high) {
          const count = Math.floor((low + high) / 2)
          const table = termTable(term, offsets[side], count, 1)
          const oneColumn = side === 0 ? [table, ''] : ['', table]
          if (heightOf(pairHtml(oneColumn)) <= availableHeight) {
            fitting = count
            low = count + 1
          } else high = count - 1
        }
        if (!fitting) throw new PdfPaginationError('pdf_atomic_block_too_tall', 'An exam transcript course cannot fit with its heading.')
        const html = termTable(term, offsets[side], fitting, 1)
        offsets[side] += fitting
        done[side] = offsets[side] >= (term.courses?.length ?? 0)
        return html
      })
      append(pairHtml(columns))
      if (!done.every(Boolean)) advance()
    }
  }
  return pages.map(page => page.html.join(''))
}

export async function exportExamBoardTranscriptPdf({ academicRecord, request = {} }) {
  const presentation = academicRecord?.exam_export
  if (presentation?.template !== EXAM_TRANSCRIPT_TEMPLATE || !Array.isArray(presentation.terms) || !Array.isArray(presentation.grade_scale?.bands)) {
    throw new Error('exam_transcript_snapshot_missing')
  }
  // Explicitly load the local Arabic font before the first layout measurement.
  await document.fonts?.load('400 10px Cairo', 'كشف درجات')
  await document.fonts?.load('800 12px Cairo', 'كشف درجات')
  const student = academicRecord.student ?? {}
  const top = heading(student, presentation.identity ?? {})
  const bottom = ending(presentation.grade_scale, request)
  const filename = 'exam-transcript-' + String(student.student_number || 'student').replace(/[^A-Za-z0-9._-]+/g, '-') + '.pdf'
  return exportPagedHtmlToPdf({
    filename,
    pageConfig: PAGE,
    requiredImages: ['/logo.png'],
    preparePages: ({ measure, contentHeightPx }) => {
      const topHeight = measure(wrapped(top))
      const bottomHeight = measure(wrapped(bottom))
      const availableHeight = contentHeightPx - topHeight - bottomHeight - 8
      if (availableHeight <= 0) throw new PdfPaginationError('pdf_atomic_block_too_tall', 'The exam transcript identity cannot fit on one page.')
      const bodies = paginateExamTermPairs({ terms: presentation.terms, availableHeight, measure })
      return bodies.map(body => '<div class="exam-transcript" dir="rtl" style="height:' + contentHeightPx + 'px;display:flex;flex-direction:column">' + CSS + top
        + '<main>' + (body || '<p style="text-align:center;padding:20px">لا توجد بيانات دراسية</p>') + '</main>'
        + '<div style="margin-top:auto;padding-top:4px">' + bottom + '</div></div>')
    },
    footerHtml: ({ pageNumber, totalPages }) => '<div dir="rtl" style="font:8px Cairo,sans-serif;color:#333;text-align:center">صفحة ' + pageNumber + ' من ' + totalPages + '</div>',
  })
}
