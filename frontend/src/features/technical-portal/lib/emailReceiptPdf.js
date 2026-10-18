import html2canvas from 'html2canvas-pro'
import { jsPDF } from 'jspdf'

// Client-side download following explicit credential action/re-download; never automatic printing.
export async function downloadEmailReceipt(element, isCurrent) {
  const documentElement = element?.querySelector('[data-email-receipt]')
  if (!documentElement) throw new Error('مستند الإيصال غير جاهز.')
  const fonts = await document.fonts.load('400 14px Cairo', 'البريد الجامعي')
  const boldFonts = await document.fonts.load('700 14px Cairo', 'جامعة الروّاد')
  await document.fonts.ready
  if (!fonts.length || !boldFonts.length) throw new Error('تعذر تحميل خط الإيصال.')
  await Promise.all([...documentElement.querySelectorAll('img')].map(image => image.decode()))
  if (!isCurrent()) return false
  if (documentElement.scrollWidth > documentElement.clientWidth + 1 || documentElement.scrollHeight > documentElement.clientHeight + 1) throw new Error('محتوى الإيصال يتجاوز الصفحة؛ لم يتم قصه أو تصغيره.')
  const canvas = await html2canvas(documentElement, { scale: 2, backgroundColor: '#fff', logging: false })
  try {
    if (!isCurrent()) return false
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
    const width = 190, height = width * canvas.height / canvas.width
    if (height > 277) throw new Error('محتوى الإيصال يتجاوز A4.')
    pdf.addImage(canvas.toDataURL('image/png'), 'PNG', (210 - width) / 2, 10, width, height)
    if (!isCurrent()) return false
    await pdf.save('university-email-receipt.pdf', { returnPromise: true })
    return true
  } finally { canvas.width = 0; canvas.height = 0 }
}
