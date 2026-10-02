// Real React server rendering, NOT browser layout or actual PDF download verification.
import assert from 'node:assert/strict'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { createServer } from 'vite'

const server = await createServer({ server: { middlewareMode: true }, appType: 'custom' })
try {
  const { default: Receipt } = await server.ssrLoadModule('/src/features/technical-portal/components/UniversityEmailReceipt.jsx')
  const receipt = { receipt_id: 'synthetic-reference', email_address: 'a'.repeat(48)+'.synthetic123@alrowaduni.edu.sy',
    student: { full_name: 'عبد الرحمن محمد أحمد الاختبار الاصطناعي الطويل '.repeat(4), student_number: 'SYNTHETIC123', college: 'كلية اختبار اصطناعي', program: 'برنامج اختبار اصطناعي' },
    employee: 'الموظف الاصطناعي', issued_at: '2026-10-01T12:00:00Z', account_url: 'https://mail.alrowaduni.edu.sy/', webmail_url: 'https://mail.alrowaduni.edu.sy/SOGo/' }
  for (const purpose of ['initial_credentials', 'password_reset']) for (const length of [24, 64]) {
    const password = ('Ab!234XYZ?=abcdefghjkmnpqrstuvwxyz'.repeat(3)).slice(0, length)
    const html = renderToStaticMarkup(createElement(Receipt, { receipt: { ...receipt, receipt_purpose: purpose }, password }))
    const value = html.match(/data-receipt-password="[^"]*"[^>]*>([^<]*)<\/p>/)?.[1]
    assert.equal(value, password, 'Exact characters, not inserted spaces/truncated strings')
    assert.ok(html.includes(receipt.email_address))
    assert.ok(html.includes(receipt.student.full_name))
    assert.ok(html.includes(receipt.employee) && html.includes(receipt.issued_at))
    assert.ok(html.includes(receipt.student.program) && html.includes('توقيع') && html.includes('ختم الجهة'))
    assert.ok(html.includes('dir="ltr"') && html.includes('overflow-wrap:anywhere') && html.includes('white-space:pre-wrap'))
    assert.ok(html.includes('لا تشارك كلمة المرور') && html.includes('شعار الجامعة'))
    assert.ok(!html.includes('تأكيد استلام'))
    if (purpose === 'password_reset') assert.ok(html.includes('إيصال إعادة تعيين كلمة مرور البريد الجامعي'))
  }
  console.log('React SSR initial/reset receipt 24/64 + long Arabic name/address passed; browser/PDF layout NOT verified')
} finally { await server.close() }
