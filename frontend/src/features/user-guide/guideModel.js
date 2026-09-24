// Pure guide model: filtering by the viewer's real access, link resolution,
// shared troubleshooting/help content and the copyable report template.
import { canAccess } from '../auth/auth.js'
import { ROUTE_ACCESS } from './guideAccess.js'

export const NODE_KINDS = Object.freeze({
  start: 'بداية',
  action: 'إجراء المستخدم',
  review: 'مراجعة واعتماد',
  system: 'إجراء تلقائي من النظام',
  end: 'نهاية',
  return: 'إرجاع أو رفض',
})

const allows = (access, user) => !access || canAccess(access, user)

/** A link is offered only when the route exists and every guard on it passes. */
export function resolveLink(link, user) {
  if (!link?.to) return null
  const guards = ROUTE_ACCESS[link.to]
  if (!guards || !guards.every(guard => canAccess(guard, user))) return null
  return { to: link.to, label: link.label ?? 'اذهب إلى الصفحة' }
}

/** Steps, flows and notes can each carry their own `access`; hidden ones are removed. */
export function visibleTask(task, user) {
  if (!allows(task.access, user)) return null
  const steps = (task.steps ?? [])
    .filter(step => allows(step.access, user))
    .map(step => ({ ...step, link: step.link ? resolveLink(step.link, user) : null }))
  const flows = (task.flows ?? []).filter(flow => allows(flow.access, user))
  const notes = (task.notes ?? []).filter(note => allows(note.access, user))
  const link = task.link ? resolveLink(task.link, user) : null
  return { ...task, steps, flows, notes, link }
}

export function visibleSections(guide, user) {
  return (guide.sections ?? [])
    .filter(section => allows(section.access, user))
    .map(section => ({ ...section, tasks: (section.tasks ?? []).map(task => visibleTask(task, user)).filter(Boolean) }))
    .filter(section => section.tasks.length > 0)
}

export function visibleUnavailable(guide, user) {
  return (guide.unavailable ?? []).filter(item => allows(item.access, user))
}

export function visibleTroubleshooting(guide, user) {
  return [...COMMON_TROUBLESHOOTING, ...(guide.troubleshooting ?? [])].filter(item => allows(item.access, user))
}

/** A straight arrow to the next node is drawn only for a plain sequential step. */
export function flowsToNext(node) {
  return !node.terminal && !(node.branches?.length) && node.kind !== 'return' && node.kind !== 'end'
}

/** Guide heading: the first title whose access the viewer passes, else the default. */
export function guideTitle(guide, user) {
  return (guide.titles ?? []).find(item => allows(item.access, user))?.title ?? guide.title
}

/** Numbered explanation derived from the flow nodes (same order as the diagram). */
export function flowExplanation(flow) {
  const index = new Map(flow.nodes.map((node, i) => [node.id, i + 1]))
  return flow.nodes.map((node, i) => ({
    number: i + 1,
    kind: NODE_KINDS[node.kind],
    text: node.explain ?? node.label,
    branches: (node.branches ?? []).map(branch => ({ label: branch.label, target: index.get(branch.to) ?? null })),
  }))
}

// ── «عند حدوث مشكلة» — shared across every guide ────────────────────────────
// `who`: 'user' = the user can usually fix it; 'admin' = needs the responsible office.
export const COMMON_TROUBLESHOOTING = Object.freeze([
  {
    id: 'login',
    title: 'تعذّر تسجيل الدخول',
    who: 'admin',
    steps: [
      'تأكد من البريد الإلكتروني وكلمة المرور، ومن عدم تفعيل الأحرف الكبيرة.',
      'إذا ظهرت رسالة بأن الحساب معطّل أو غير نشط فلا تكرر المحاولة؛ الحساب يحتاج إلى تفعيل من المسؤول.',
      'رابط «نسيت كلمة المرور؟» غير مفعّل حاليًا في النظام؛ اطلب إعادة تعيين كلمة المرور من مسؤول الحسابات.',
    ],
  },
  {
    id: '403',
    title: 'رسالة «غير مصرح» أو الرمز 403',
    who: 'admin',
    steps: [
      'الصفحة أو الإجراء يتطلب صلاحية أو دورًا أو نطاقًا (كلية/قسم/جامعة) لا يملكه حسابك.',
      'لا تحاول الوصول عبر رابط آخر؛ الخادم يرفض الطلب مهما كان مصدره.',
      'إذا كان العمل من مسؤولياتك فعلًا فاطلب من المسؤول مراجعة أدوار حسابك ونطاقه.',
    ],
  },
  {
    id: '422',
    title: 'خطأ في البيانات المدخلة أو الرمز 422',
    who: 'user',
    steps: [
      'اقرأ الرسالة الظاهرة تحت الحقل أو في أعلى النموذج؛ فهي تحدد الحقل المطلوب تصحيحه.',
      'صحّح القيمة (حقل ناقص، تنسيق غير صحيح، قيمة مكررة، سبب قصير جدًا…) ثم أعد الحفظ.',
    ],
  },
  {
    id: '409',
    title: 'تعارض مع الحالة الحالية أو الرمز 409',
    who: 'user',
    steps: [
      'يعني أن السجل تغيّر أو انتقل إلى حالة لا تسمح بهذا الإجراء (مثلًا: اعتُمد أو أُرسل أو أُغلقت الفترة).',
      'حدّث الصفحة وراجع الحالة الحالية للسجل قبل أي محاولة أخرى.',
      'لا تُعد تنفيذ عملية أكاديمية أو مالية حساسة (اعتماد، إرسال، ترحيل، تسجيل) قبل التحقق من نتيجتها؛ قد تكون نُفذت بالفعل.',
    ],
  },
  {
    id: 'missing',
    title: 'صفحة أو زر غير ظاهر',
    who: 'admin',
    steps: [
      'القائمة تعرض فقط ما تسمح به أدوار حسابك؛ غياب الصفحة يعني غالبًا غياب الصلاحية لا خللًا في النظام.',
      'بعض الأزرار تظهر فقط في حالات معينة (مثل فتح الفترة أو وجود طلب بانتظار المراجعة).',
      'إذا كنت تحتاجها لعملك فاطلب من المسؤول مراجعة صلاحياتك.',
    ],
  },
  {
    id: 'save',
    title: 'تعذّر حفظ البيانات أو انقطاع الاتصال',
    who: 'user',
    steps: [
      'تحقق من اتصال الشبكة (يظهر مؤشر الاتصال أسفل الصفحة).',
      'حدّث الصفحة وتحقق هل حُفظت البيانات فعلًا قبل إعادة إدخالها.',
      'إذا تكرر الخطأ نفسه فسجّل نص الرسالة ووقتها وأرسل بلاغًا وفق الصيغة أدناه.',
    ],
  },
  {
    id: 'unexpected',
    title: 'بيانات غير متوقعة أو غير صحيحة',
    who: 'admin',
    steps: [
      'لا تعدّل البيانات يدويًا لتصحيح ما تراه خطأً في نتيجة رسمية أو سجل معتمد.',
      'دوّن الصفحة وما تتوقعه وما يظهر فعلًا، ثم أبلغ الجهة المسؤولة عن الإجراء.',
    ],
  },
])

// ── «التواصل وطلب المساعدة» ───────────────────────────────────────────────────
// No official e-mail, phone or help-desk address exists in the project; none is invented.
export const HELP_CHANNELS = Object.freeze([
  { id: 'account', title: 'مشكلة حساب أو صلاحية', body: 'تعذّر الدخول، حساب معطّل، أو صفحة/إجراء يحتاج صلاحية: يعالجها مسؤول الحسابات والصلاحيات (المكتب التقني أو مدير النظام).' },
  { id: 'technical', title: 'مشكلة تقنية', body: 'خطأ متكرر، صفحة لا تعمل، أو تعذّر الحفظ رغم صحة البيانات: يعالجها الدعم التقني للنظام.' },
  { id: 'academic', title: 'مشكلة في إجراء أكاديمي', body: 'اعتراض على نتيجة أو قرار أو حالة طلب: تتولاها الجهة المالكة للإجراء (المرشد أو العميد أو هيئة الامتحانات أو شؤون الطلاب أو النيابة المختصة).' },
])

export const CONTACT_NOTICE = 'لم تُعتمد بعد في النظام وسيلة تواصل رسمية (بريد أو هاتف). وسيلة التواصل الرسمية تحتاج إلى اعتماد من الجامعة؛ إلى حين ذلك استخدم صيغة البلاغ أدناه عبر القناة الإدارية المعتادة في جهتك.'

export const REPORT_PRIVACY_NOTE = 'لا تكتب في البلاغ كلمة المرور أو رمز الدخول (التوكن)، ولا بيانات طلاب أو درجات. يكفي وصف الخطوات ونص الخطأ.'

/** Plain-text report the user copies locally; nothing is sent anywhere. */
export function buildReportTemplate({ portalTitle, pagePath, time = new Date() }) {
  const stamp = time instanceof Date && !Number.isNaN(time.getTime()) ? time.toLocaleString('ar-SY') : ''
  return [
    'بلاغ مشكلة — نظام جامعة الرواد',
    `البوابة: ${portalTitle ?? ''}`,
    `الصفحة: ${pagePath ?? ''}`,
    `وقت المشكلة: ${stamp}`,
    'الخطوات التي نفذتها:',
    '1. ',
    '2. ',
    'ما توقعت حدوثه:',
    'ما حدث فعلًا (نص الخطأ أو رقمه كما ظهر):',
    'نوع المشكلة (حساب وصلاحية / تقنية / إجراء أكاديمي):',
  ].join('\n')
}
