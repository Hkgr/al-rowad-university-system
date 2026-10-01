import html2canvas from 'html2canvas-pro'
import { jsPDF } from 'jspdf'

// Explicit browser download only; no upload, public URL, server file or automatic printing.
export async function downloadEmailReceipt(element, isCurrent) {
  await document.fonts.ready
  const canvas = await html2canvas(element, { scale: 2, backgroundColor: '#fff', logging: false })
  try {
    if (!isCurrent()) return false
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
    const height = Math.min(277, 190 * canvas.height / canvas.width)
    const width = height * canvas.width / canvas.height
    pdf.addImage(canvas.toDataURL('image/png'), 'PNG', (210 - width) / 2, 10, width, height)
    if (!isCurrent()) return false
    pdf.save('university-email-receipt.pdf')
    return true
  } finally { canvas.width = 0; canvas.height = 0 }
}
