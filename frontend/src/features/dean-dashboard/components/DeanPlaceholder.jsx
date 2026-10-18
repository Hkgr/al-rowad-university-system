export default function DeanPlaceholder({ title, description, notice }) {
  return (
    <section
      className="min-h-[420px] flex items-center justify-center px-4 py-12"
      dir="rtl"
      aria-labelledby="dean-placeholder-title"
    >
      <div className="w-full max-w-[680px] bg-white border border-primary/15 rounded-[18px] px-7 py-12 text-center shadow-[0_4px_24px_rgba(86,153,51,0.08)] relative overflow-hidden">
        <div
          className="absolute top-0 left-0 right-0 h-1"
          style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327)' }}
        />
        <div className="w-16 h-16 mx-auto mb-5 rounded-[16px] bg-primary/10 text-primary flex items-center justify-center text-[30px]" aria-hidden="true">
          ◈
        </div>
        <h2 id="dean-placeholder-title" className="text-[22px] font-black text-text-dark mb-2">
          {title}
        </h2>
        <p className="text-[13.5px] leading-7 text-text-light max-w-[500px] mx-auto">
          {description}
        </p>
        {notice && (
          <p className="mt-5 px-4 py-3 rounded-[12px] bg-primary/7 border border-primary/15 text-[13px] font-semibold text-primary-dark">
            {notice}
          </p>
        )}
      </div>
    </section>
  )
}
