import html2canvas from 'html2canvas-pro'
import jsPDF from 'jspdf'

function escapeHtml(value) {
  const div = document.createElement('div')
  div.textContent = value === null || value === undefined || value === '' ? '—' : String(value)
  return div.innerHTML
}

// columns: [{ header, value: (row) => string }]
export async function exportRowsToPdf({ title, subtitle, columns, rows, filename }) {
  const wrapper = document.createElement('div')
  wrapper.style.position = 'fixed'
  wrapper.style.left = '-10000px'
  wrapper.style.top = '0'
  wrapper.style.width = '1122px'
  wrapper.style.zIndex = '-1'
  document.body.appendChild(wrapper)

  wrapper.innerHTML = `
    <div dir="rtl" style="background:#ffffff;padding:32px;font-family:Tahoma, Arial, sans-serif;">
      <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #2f8132;padding-bottom:16px;margin-bottom:20px;">
        <div>
          <h1 style="font-size:20px;font-weight:900;color:#1f2937;margin:0;">${escapeHtml(title)}</h1>
          ${subtitle ? `<p style="font-size:11px;color:#9ca3af;margin:4px 0 0;">${escapeHtml(subtitle)}</p>` : ''}
        </div>
        <p style="font-size:10px;color:#9ca3af;margin:0;">تاريخ الإصدار: ${new Date().toLocaleDateString('ar-SY')}</p>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:11px;">
        <thead>
          <tr style="background:#475569;color:#ffffff;">
            ${columns.map(c => `<th style="padding:8px 10px;text-align:right;font-weight:700;white-space:nowrap;">${escapeHtml(c.header)}</th>`).join('')}
          </tr>
        </thead>
        <tbody>
          ${rows.map((row, i) => `
            <tr style="background:${i % 2 === 0 ? '#ffffff' : '#f8fafc'};border-bottom:1px solid #e2e8f0;">
              ${columns.map(c => `<td style="padding:7px 10px;color:#334155;">${escapeHtml(c.value(row))}</td>`).join('')}
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `

  try {
    const canvas = await html2canvas(wrapper.firstElementChild, { scale: 1.5, backgroundColor: '#ffffff', useCORS: true })
    const imgData = canvas.toDataURL('image/jpeg', 0.92)
    const pdf = new jsPDF({ orientation: 'l', unit: 'pt', format: 'a4', compress: true })
    const pageWidth  = pdf.internal.pageSize.getWidth()
    const pageHeight = pdf.internal.pageSize.getHeight()
    const imgWidth  = pageWidth
    const imgHeight = (canvas.height * imgWidth) / canvas.width

    let heightLeft = imgHeight
    let position = 0
    pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight)
    heightLeft -= pageHeight
    while (heightLeft > 0) {
      position -= pageHeight
      pdf.addPage()
      pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight)
      heightLeft -= pageHeight
    }

    pdf.save(filename)
  } finally {
    document.body.removeChild(wrapper)
  }
}
