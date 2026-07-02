export default function ExamPlaceholder({ title, en }) {
  return (
    <div className="flex flex-col items-center justify-center py-24 gap-3" dir="rtl">
      <div className="text-[48px] text-primary/15 mb-2">🚧</div>
      <p className="text-[18px] font-black text-text-dark">{title}</p>
      <p className="text-[13px] text-text-light">{en} — قيد الإنشاء</p>
    </div>
  )
}
