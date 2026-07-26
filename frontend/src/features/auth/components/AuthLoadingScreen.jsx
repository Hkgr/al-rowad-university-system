export default function AuthLoadingScreen() {
  return (
    <div className="min-h-screen flex items-center justify-center bg-[#f0f5ec]" dir="rtl">
      <div className="flex flex-col items-center gap-4">
        <div className="w-12 h-12 rounded-full border-4 border-primary/20 border-t-primary animate-spin" />
        <p className="text-sm font-bold text-primary-dark">جارٍ التحقق من صلاحية الجلسة...</p>
      </div>
    </div>
  )
}
