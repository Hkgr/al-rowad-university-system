export default function SyrianFlag() {
  return (
    <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-[1] opacity-[0.09] pointer-events-none w-[700px] h-[460px]">
      <div className="w-full h-full overflow-hidden rounded-[4px]">
        <div className="w-full h-full flex flex-col animate-[wave_2.5s_ease-in-out_infinite] origin-left">
          <div className="flex-1 bg-[#007a3d]" />
          <div className="flex-1 bg-white flex items-center justify-center gap-6">
            <span className="text-[#ce1126] text-[42px] leading-none">★</span>
            <span className="text-[#ce1126] text-[42px] leading-none">★</span>
            <span className="text-[#ce1126] text-[42px] leading-none">★</span>
          </div>
          <div className="flex-1 bg-black" />
        </div>
      </div>
    </div>
  )
}
