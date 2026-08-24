import html2canvas from 'html2canvas-pro'
import jsPDF from 'jspdf'
import { assertPageFits, paginateMeasuredSections } from './pdfPagination'

export const PDF_PAGE_CONFIGS = Object.freeze({
  portrait: Object.freeze({ orientation: 'portrait', widthMm: 210, heightMm: 297, marginMm: 11, footerHeightMm: 10, footerGapMm: 3 }),
  landscape: Object.freeze({ orientation: 'landscape', widthMm: 297, heightMm: 210, marginMm: 11, footerHeightMm: 9, footerGapMm: 3 }),
})

export function escapeHtml(value, empty = '—') {
  return String(value === null || value === undefined || value === '' ? empty : value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
}

function pageGeometry(config) {
  const contentWidthMm = config.widthMm - (config.marginMm * 2)
  const contentHeightMm = config.heightMm - (config.marginMm * 2) - config.footerHeightMm - config.footerGapMm
  if (contentWidthMm <= 0 || contentHeightMm <= 0) throw new Error('pdf_invalid_page_configuration')
  return { ...config, contentWidthMm, contentHeightMm }
}

async function preloadImages(urls) {
  const images = []
  try {
    for (const url of [...new Set(urls.filter(Boolean))]) {
      const image = new Image()
      images.push(image)
      image.decoding = 'async'
      image.src = url
      if (!image.complete) {
        await new Promise((resolve, reject) => {
          image.onload = resolve
          image.onerror = () => reject(new Error('pdf_asset_load_failed'))
        })
      }
      if (!image.naturalWidth) throw new Error('pdf_asset_load_failed')
      if (typeof image.decode === 'function') await image.decode()
    }
    return images
  } catch (error) {
    images.forEach(image => {
      image.onload = null
      image.onerror = null
      image.removeAttribute('src')
    })
    throw error
  }
}

function createMeasurementWorkspace(geometry) {
  const workspace = document.createElement('div')
  workspace.dataset.pdfWorkspace = 'true'
  Object.assign(workspace.style, {
    position: 'fixed', left: '-10000px', top: '0', width: `${geometry.widthMm}mm`,
    minHeight: `${geometry.heightMm}mm`, background: '#ffffff', boxSizing: 'border-box',
    visibility: 'hidden', pointerEvents: 'none', zIndex: '-2147483647',
  })
  const measurement = document.createElement('div')
  measurement.dataset.pdfMeasurement = 'true'
  Object.assign(measurement.style, {
    width: `${geometry.contentWidthMm}mm`, boxSizing: 'border-box',
    fontFamily: "Cairo, 'Segoe UI', sans-serif", direction: 'rtl', color: '#1f2937',
  })
  workspace.appendChild(measurement)
  document.body.appendChild(workspace)
  const contentWidthPx = measurement.getBoundingClientRect().width
  const pxPerMm = contentWidthPx / geometry.contentWidthMm
  return { workspace, measurement, contentWidthPx, contentHeightPx: geometry.contentHeightMm * pxPerMm }
}

function measureHtml(measurement, html) {
  const holder = document.createElement('div')
  holder.style.width = '100%'
  holder.style.boxSizing = 'border-box'
  holder.style.display = 'flow-root'
  holder.innerHTML = html
  measurement.appendChild(holder)
  const height = holder.getBoundingClientRect().height
  holder.remove()
  return height
}

function buildFinalPage({ geometry, bodyHtml, footerHtml }) {
  const page = document.createElement('section')
  page.dataset.pdfPage = 'true'
  Object.assign(page.style, {
    position: 'relative', width: `${geometry.widthMm}mm`, height: `${geometry.heightMm}mm`,
    padding: `${geometry.marginMm}mm`, background: '#ffffff', color: '#1f2937',
    boxSizing: 'border-box', overflow: 'hidden', fontFamily: "Cairo, 'Segoe UI', sans-serif", direction: 'rtl',
  })
  const content = document.createElement('div')
  content.dataset.pdfContent = 'true'
  Object.assign(content.style, {
    width: `${geometry.contentWidthMm}mm`, height: `${geometry.contentHeightMm}mm`,
    boxSizing: 'border-box', overflow: 'visible', display: 'flow-root',
  })
  content.innerHTML = bodyHtml
  const footer = document.createElement('footer')
  Object.assign(footer.style, {
    position: 'absolute', right: `${geometry.marginMm}mm`, bottom: `${geometry.marginMm}mm`,
    width: `${geometry.contentWidthMm}mm`, height: `${geometry.footerHeightMm}mm`, boxSizing: 'border-box',
  })
  footer.innerHTML = footerHtml
  page.append(content, footer)
  return page
}

/** Pass 1 measures and paginates; Pass 2 builds final pages after totalPages is known. */
export async function exportPagedHtmlToPdf({ filename, pageConfig, requiredImages = [], preparePages, footerHtml }) {
  const geometry = pageGeometry(pageConfig)
  let loadedImages = []
  let workspace = null
  const canvases = []
  try {
    // Required order: assets, fonts, layout-active measurement DOM, measurement, pagination.
    loadedImages = await preloadImages(requiredImages)
    if (document.fonts?.ready) await document.fonts.ready
    const measuring = createMeasurementWorkspace(geometry)
    workspace = measuring.workspace

    // Pass 1: paginate the complete semantic document and determine totalPages.
    const pageBodies = await preparePages({
      measure: html => measureHtml(measuring.measurement, html),
      contentWidthPx: measuring.contentWidthPx,
      contentHeightPx: measuring.contentHeightPx,
      geometry,
    })
    if (!Array.isArray(pageBodies) || pageBodies.length === 0) throw new Error('pdf_empty_document')
    const totalPages = pageBodies.length

    // Pass 2: build every final page with the already-known totalPages.
    measuring.measurement.remove()
    const pages = pageBodies.map((bodyHtml, index) => buildFinalPage({
      geometry,
      bodyHtml,
      footerHtml: footerHtml({ pageNumber: index + 1, totalPages }),
    }))
    pages.forEach(page => workspace.appendChild(page))
    workspace.style.visibility = 'visible'

    // Never capture or save a page that would be clipped.
    pages.forEach(page => {
      assertPageFits(page)
      assertPageFits(page.querySelector('[data-pdf-content]'))
    })

    const pdf = new jsPDF({ orientation: geometry.orientation, unit: 'mm', format: 'a4', compress: true })
    for (let index = 0; index < pages.length; index += 1) {
      const canvas = await html2canvas(pages[index], {
        scale: 2, backgroundColor: '#ffffff', useCORS: true, logging: false,
      })
      canvases.push(canvas)
      if (index > 0) pdf.addPage('a4', geometry.orientation)
      pdf.addImage(canvas.toDataURL('image/jpeg', 0.94), 'JPEG', 0, 0, geometry.widthMm, geometry.heightMm)
    }
    pdf.save(filename)
  } finally {
    canvases.forEach(canvas => { canvas.width = 0; canvas.height = 0 })
    loadedImages.forEach(image => {
      image.onload = null
      image.onerror = null
      image.removeAttribute('src')
    })
    workspace?.remove()
  }
}

function reportHeading(title, subtitle, continuation = false) {
  return `<header style="display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #2f8132;padding-bottom:12px;margin-bottom:12px;box-sizing:border-box;"><div><h1 style="font-size:20px;font-weight:900;color:#1f2937;margin:0;">${escapeHtml(title)}${continuation ? ' — متابعة' : ''}</h1>${subtitle ? `<p style="font-size:11px;color:#6b7280;margin:4px 0 0;">${escapeHtml(subtitle)}</p>` : ''}</div></header>`
}

function reportTableHeader(columns) {
  return `<thead><tr style="background:#475569;color:#ffffff;">${columns.map(column => `<th style="padding:8px 10px;text-align:right;font-weight:700;">${escapeHtml(column.header)}</th>`).join('')}</tr></thead>`
}

function reportRow(columns, row, index) {
  return `<tr style="background:${index % 2 === 0 ? '#ffffff' : '#f8fafc'};border-bottom:1px solid #e2e8f0;">${columns.map(column => `<td style="padding:7px 10px;color:#334155;overflow-wrap:anywhere;">${escapeHtml(column.value(row))}</td>`).join('')}</tr>`
}

function reportTable(columns, rowHtml) {
  return `<table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;">${reportTableHeader(columns)}<tbody>${rowHtml}</tbody></table>`
}

// Public signature intentionally remains unchanged. Generic reports stay A4 landscape.
export async function exportRowsToPdf({ title, subtitle, columns, rows, filename }) {
  const renderedRows = rows.length > 0
    ? rows.map((row, index) => reportRow(columns, row, index))
    : [`<tr><td colspan="${columns.length}" style="padding:18px;text-align:center;color:#6b7280;">لا توجد بيانات</td></tr>`]
  const issuedAt = new Date().toLocaleString('ar-SY')
  return exportPagedHtmlToPdf({
    filename,
    pageConfig: PDF_PAGE_CONFIGS.landscape,
    preparePages: ({ measure, contentHeightPx }) => {
      const firstHeading = reportHeading(title, subtitle)
      const continuationHeading = reportHeading(title, subtitle, true)
      const header = reportTableHeader(columns)
      const headingHeight = measure(firstHeading)
      const continuationHeadingHeight = measure(continuationHeading)
      const headerHeight = measure(`<table style="width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;">${header}</table>`)
      const measuredRows = renderedRows.map((html, index) => ({ id: String(index), height: measure(reportTable(columns, html)) - headerHeight }))
      const pages = paginateMeasuredSections({
        contentHeight: contentHeightPx,
        sections: [{ kind: 'table', id: 'generic-report', headingHeight, continuationHeadingHeight, headerHeight, rows: measuredRows }],
      })
      return pages.map(page => {
        const fragment = page.fragments[0]
        const selectedRows = fragment.rowIds.map(id => renderedRows[Number(id)]).join('')
        return `${fragment.continuation ? continuationHeading : firstHeading}${reportTable(columns, selectedRows)}`
      })
    },
    footerHtml: ({ pageNumber, totalPages }) => `<div style="height:100%;display:flex;align-items:end;justify-content:space-between;border-top:1px solid #d1d5db;padding-top:2mm;font-size:9px;color:#6b7280;box-sizing:border-box;"><span>تاريخ الإصدار: ${escapeHtml(issuedAt)}</span><span>صفحة ${pageNumber} من ${totalPages}</span></div>`,
  })
}
