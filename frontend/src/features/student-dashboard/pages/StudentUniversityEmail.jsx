import { useEffect, useState } from 'react'
import { apiRequest } from '../../../services/apiClient'
import { canAccess, getIdentity } from '../../auth/auth'
import { PageHeader, Section, Notice, StatePanel, InfoGrid } from '../../ministry-portal/components/MinistryUi'

const access = { studentIdentity: true, allRoles: ['student'] }
export default function StudentUniversityEmail() {
  const [identity, setIdentity] = useState(() => JSON.stringify(getIdentity()))
  useEffect(() => {
    const check = () => setIdentity(JSON.stringify(getIdentity()))
    const timer = setInterval(check, 1000)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check) }
  }, [])
  return canAccess(access) ? <StudentEmail key={identity} identity={identity} /> : <StatePanel state="forbidden" message="هذا القسم متاح لحساب الطالب المرتبط بسجله فقط." />
}
function StudentEmail({ identity }) {
  const [data, setData] = useState(null), [error, setError] = useState(null), [retry, setRetry] = useState(0)
  useEffect(() => {
    const controller = new AbortController(); let current = true
    apiRequest('/v1/student/university-email', { signal: controller.signal, cache: 'no-store' })
      .then(json => { if (current && identity === JSON.stringify(getIdentity())) { setData(json.data); setError(null) } })
      .catch(failure => { if (failure.name !== 'AbortError' && current) { setData(null); setError(failure) } })
    return () => { current = false; controller.abort() }
  }, [retry, identity])
  return <div dir="rtl" className="space-y-4">
    <PageHeader title="بريدي الجامعي" en="University email" subtitle="عنوان البريد المُنشأ والمؤكد لحسابك فقط" />
    {error && <StatePanel state={[401, 403].includes(error.status) ? 'forbidden' : 'error'} message={error.message} onRetry={() => setRetry(r => r + 1)} />}
    {!data && !error && <StatePanel state="loading" />}
    {data?.available === false && <Notice tone="warning">قراءة البريد الجامعي غير متاحة حاليًا؛ راجع المكتب التقني.</Notice>}
    {data?.available && !data.created && <Notice>لا يوجد صندوق مُنشأ ومؤكد ظاهر لحسابك حاليًا. راجع المكتب التقني بشأن التجهيز.</Notice>}
    {data?.created && <Section title="بيانات الدخول إلى البريد">
      <InfoGrid items={[[ 'عنوان البريد الجامعي', data.email_address ]]} />
      <p className="text-[13px] mt-3">استخدم العنوان الكامل وكلمة المرور التي أعطاك إياها المكتب التقني. لا تعرض البوابة كلمة المرور ولا تحفظها. غيّر الكلمة الأولية من صفحة حساب البريد.</p>
      <div className="mt-4 flex flex-wrap gap-3"><a href={data.account_url} target="_blank" rel="noreferrer" className="text-primary font-bold">صفحة حساب البريد</a><a href={data.webmail_url} target="_blank" rel="noreferrer" className="text-primary font-bold">فتح بريد الويب</a></div>
    </Section>}
  </div>
}
