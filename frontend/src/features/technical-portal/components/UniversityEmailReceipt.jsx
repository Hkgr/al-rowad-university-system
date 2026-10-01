export default function UniversityEmailReceipt({ receipt, password }) {
  return <article dir="rtl" data-email-receipt style={{ width: 730, minHeight: 1000, padding: 42, background: 'white', color: '#172c24', fontFamily: 'Cairo, sans-serif', boxSizing: 'border-box', overflowWrap: 'anywhere' }}>
    <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '3px solid #176648', paddingBottom: 18 }}>
      <div><h1 style={{ fontSize: 22, margin: 0 }}>جامعة الروّاد للعلوم والتقانة</h1><p style={{ fontSize: 18 }}>{receipt.receipt_purpose === 'password_reset' ? 'إيصال إعادة تعيين كلمة مرور البريد الجامعي' : 'إيصال تسليم البريد الجامعي'}</p></div>
      <img src="/logo.png" alt="شعار الجامعة" style={{ width: 90, height: 90 }} />
    </header>
    <p style={{ fontSize: 12 }}>مرجع الإيصال: <span dir="ltr">{receipt.receipt_id}</span></p>
    <h2 style={{ fontSize: 18, marginTop: 30 }}>بيانات الطالب</h2>
    <p>الاسم: {receipt.student.full_name}</p><p>الرقم الجامعي: <span dir="ltr">{receipt.student.student_number}</span></p>
    <p>الكلية: {receipt.student.college || 'غير محدد'}</p>
    <section style={{ border: '1px solid #176648', borderRadius: 10, padding: 20, margin: '25px 0' }}>
      <p>البريد الجامعي</p><p data-receipt-address dir="ltr" style={{ fontSize: 19, overflowWrap: 'anywhere', textAlign: 'left', unicodeBidi: 'isolate', hyphens: 'none' }}>{receipt.email_address}</p>
      <p>كلمة المرور الأولية</p><p data-receipt-password dir="ltr" style={{ fontSize: 22, fontFamily: 'monospace', letterSpacing: 1, textAlign: 'left', overflowWrap: 'anywhere', whiteSpace: 'pre-wrap', unicodeBidi: 'isolate', hyphens: 'none' }}>{password}</p>
    </section>
    <h2 style={{ fontSize: 18 }}>الدخول وتغيير كلمة المرور</h2>
    <p>افتح صفحة حساب البريد وسجّل الدخول باستخدام العنوان الكامل وكلمة المرور الأولية، ثم غيّر كلمة المرور من واجهة حساب Mailcow قبل استخدام البريد.</p>
    <p dir="ltr" style={{ textAlign: 'left' }}>{receipt.account_url}</p>
    <p>بعد تغيير كلمة المرور، افتح بريد الويب:</p><p dir="ltr" style={{ textAlign: 'left' }}>{receipt.webmail_url}</p>
    <p style={{ borderRight: '4px solid #176648', padding: 12, fontWeight: 700 }}>بيانات الدخول شخصية وسرية. لا تشارك كلمة المرور مع أي شخص، وغيّر كلمة المرور الأولية بعد استلام الحساب.</p>
    <footer style={{ marginTop: 36, borderTop: '1px solid #ccd9d1', paddingTop: 16, fontSize: 12 }}>
      <p>الموظف المُصدر: {receipt.employee}</p><p>وقت الإصدار: <span dir="ltr">{receipt.issued_at}</span></p>
      <p>تنزيل هذا المستند لا يثبت استلام الطالب ولا يسجل تأكيد تسليم.</p>
    </footer>
  </article>
}
