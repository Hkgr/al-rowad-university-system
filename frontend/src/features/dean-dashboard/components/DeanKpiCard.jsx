export default function DeanKpiCard({
  label,
  value,
  hint,
  loading = false,
  accent = '#569933',
  unavailable = false,
}) {
  return (
    <article
      className="bg-white border border-primary/12 rounded-[16px] px-[18px] py-4 min-h-[108px] relative overflow-hidden shadow-[0_2px_12px_rgba(26,46,16,0.05)]"
      dir="rtl"
    >
      <div className="absolute left-0 top-0 bottom-0 w-1" style={{ background: accent }} aria-hidden="true" />
      <p className="text-[12px] font-semibold text-text-light mb-2">{label}</p>
      {loading ? (
        <div className="h-8 w-20 rounded-md bg-primary/10 animate-pulse" aria-hidden="true" />
      ) : (
        <p className="text-[26px] font-black text-text-dark leading-none tabular-nums">
          {unavailable ? 'غير متاح' : value}
        </p>
      )}
      {hint ? (
        <p className="mt-2 text-[11px] text-text-light leading-5">{hint}</p>
      ) : null}
    </article>
  )
}
