export default function OrganizationCode({ code, dark = false }) {
  return (
    <span className={`inline-flex shrink-0 items-center gap-1 rounded-md border px-1.5 py-0.5 text-[9px] font-bold ${dark ? 'border-white/15 bg-white/5 text-primary-light' : 'border-primary/15 bg-primary/5 text-primary-dark'}`}>
      <span>رمز</span><b dir="ltr" className="text-[10px] [unicode-bidi:isolate]">{code}</b>
    </span>
  )
}
