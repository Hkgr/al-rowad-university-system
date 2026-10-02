const green = '#176648', muted = '#66776d'
const isolated = { direction: 'ltr', unicodeBidi: 'isolate', textAlign: 'left', overflowWrap: 'anywhere', whiteSpace: 'pre-wrap', hyphens: 'none' }

/** Print document only: no business decisions, signatures, stamps, or browser-issued time. */
export default function UniversityEmailReceipt({ receipt, password }) {
  const reset = receipt.receipt_purpose === 'password_reset'
  const officer = reset ? 'مسؤول إعادة تعيين كلمة المرور' : 'مسؤول إنشاء البريد'
  return <article dir="rtl" data-email-receipt style={{ width: 794, height: 1123, padding: '38px 44px', display: 'flex', flexDirection: 'column', gap: 16,
    background: 'white', color: '#172c24', fontFamily: 'Cairo, sans-serif', fontSize: 12, lineHeight: 1.65, boxSizing: 'border-box', overflowWrap: 'anywhere' }}>
    <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 20, borderBottom: `2px solid ${green}`, paddingBottom: 16 }}>
      <div><h1 style={{ fontSize: 20, margin: '0 0 8px', color: green }}>جامعة الروّاد للعلوم والتقانة</h1><h2 style={{ fontSize: 17, margin: 0 }}>{reset ? 'إيصال إعادة تعيين كلمة مرور البريد الجامعي' : 'إيصال بيانات البريد الجامعي'}</h2></div>
      <img src="/logo.png" alt="شعار الجامعة" style={{ width: 84, height: 84, objectFit: 'contain', flexShrink: 0 }} />
    </header>
    <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'space-between', gap: 8, color: muted, fontSize: 10 }}>
      <span>مرجع الإيصال: <span dir="ltr">{receipt.receipt_id}</span></span><span>وقت الإصدار: <span dir="ltr">{receipt.issued_at}</span></span>
    </div>
    <section aria-label="بيانات الطالب" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px 28px' }}>
      {[[ 'الاسم الكامل', receipt.student.full_name ], ['الرقم الجامعي', receipt.student.student_number ], ['الكلية', receipt.student.college || 'غير محدد'], ['البرنامج', receipt.student.program || 'غير محدد']].map(([label, value]) => <div key={label}><div style={{ color: muted, fontSize: 10 }}>{label}</div><div style={{ fontSize: 13, fontWeight: 700 }}>{value}</div></div>)}
    </section>
    <section style={{ background: '#f3f8f5', borderRight: `3px solid ${green}`, padding: '16px 20px' }}>
      <div style={{ color: muted, fontSize: 11 }}>البريد الجامعي</div><p data-receipt-address dir="ltr" style={{ ...isolated, fontSize: 15, fontFamily: 'monospace', margin: '6px 0 12px' }}>{receipt.email_address}</p>
      <div style={{ color: muted, fontSize: 11 }}>{reset ? 'كلمة المرور الجديدة' : 'كلمة المرور الأولية'}</div><p data-receipt-password dir="ltr" style={{ ...isolated, fontSize: 18, fontFamily: 'monospace', margin: '6px 0 0', lineHeight: 1.8 }}>{password}</p>
    </section>
    <section><h3 style={{ color: green, fontSize: 13, margin: '0 0 6px' }}>الدخول والمحافظة على سرية الحساب</h3>
      <p style={{ margin: '0 0 8px' }}>يرجى تغيير كلمة المرور عند تسجيل الدخول للمرة الأولى والمحافظة على سرية بيانات الحساب. استخدم عنوان البريد الكامل للدخول، ولا تشارك كلمة المرور مع أي شخص.</p>
      <div style={{ color: muted, fontSize: 10 }}>رابط حساب البريد</div><p dir="ltr" style={{ ...isolated, fontSize: 11, margin: '2px 0 8px' }}>{receipt.account_url}</p>
      <div style={{ color: muted, fontSize: 10 }}>رابط بريد الويب</div><p dir="ltr" style={{ ...isolated, fontSize: 11, margin: '2px 0 0' }}>{receipt.webmail_url}</p>
    </section>
    <section style={{ borderTop: '1px solid #d9e3dc', paddingTop: 12 }}><span style={{ color: muted }}>{officer}: </span><strong>{receipt.employee}</strong></section>
    <section aria-label="التوقيعات اليدوية" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 36, marginTop: 'auto', paddingTop: 12 }}>
      <div><strong>توقيع {officer}</strong><div style={{ height: 42, borderBottom: '1px solid #97ab9d' }} /><p style={{ margin: '6px 0' }}>الاسم: {receipt.employee}</p></div>
      <div><strong>توقيع الطالب</strong><div style={{ height: 42, borderBottom: '1px solid #97ab9d' }} /><p style={{ margin: '6px 0' }}>الاسم: {receipt.student.full_name}</p></div>
    </section>
    <div style={{ minHeight: 38, color: muted, textAlign: 'center', fontSize: 10 }}>ختم الجهة</div>
    <footer style={{ borderTop: '1px solid #d9e3dc', paddingTop: 10, fontSize: 10, color: muted }}>
      <p style={{ margin: '0 0 4px' }}>هذا الإيصال يحتوي بيانات دخول سرية ويجب تسليمه مباشرة إلى صاحب الحساب.</p>
      <p style={{ margin: '0 0 4px' }}>تنزيل الإيصال لا يعني تسجيل الاستلام إلكترونيًا.</p><span dir="ltr">{receipt.receipt_id}</span>
    </footer>
  </article>
}
