const BLOBS = [
  { size: 750, x: '-18%', y: '-18%', color: 'radial-gradient(circle, rgba(86,153,51,0.18) 0%, transparent 68%)',   xMult: 0.012,  yMult: 0.008,  delay: '0s',   dur: '24s' },
  { size: 650, x: '72%',  y: '62%',  color: 'radial-gradient(circle, rgba(65,115,39,0.14) 0%, transparent 68%)',    xMult: -0.018, yMult: 0.013,  delay: '-10s', dur: '30s' },
  { size: 480, x: '38%',  y: '32%',  color: 'radial-gradient(circle, rgba(122,179,86,0.1) 0%, transparent 68%)',    xMult: 0.009,  yMult: -0.016, delay: '-16s', dur: '20s' },
  { size: 380, x: '62%',  y: '2%',   color: 'radial-gradient(circle, rgba(86,153,51,0.09) 0%, transparent 68%)',   xMult: -0.008, yMult: 0.01,   delay: '-6s',  dur: '26s' },
  { size: 300, x: '5%',   y: '60%',  color: 'radial-gradient(circle, rgba(168,214,138,0.12) 0%, transparent 68%)', xMult: 0.015,  yMult: -0.01,  delay: '-3s',  dur: '22s' },
]

export default function AnimatedBackground({ mousePos }) {
  return (
    <div className="absolute inset-0 overflow-hidden z-0">

      {/* Mesh gradient base */}
      <div
        className="absolute inset-0 animate-[meshPulse_18s_ease-in-out_infinite]"
        style={{
          background: `
            radial-gradient(ellipse at 15% 15%, rgba(86,153,51,0.13) 0%, transparent 48%),
            radial-gradient(ellipse at 85% 85%, rgba(65,115,39,0.11) 0%, transparent 48%),
            radial-gradient(ellipse at 50% 50%, rgba(122,179,86,0.07) 0%, transparent 60%),
            linear-gradient(150deg, #f4fbee 0%, #eaf6e0 25%, #f6fbf2 55%, #ecf8e3 80%, #f0faea 100%)
          `,
        }}
      />

      {/* Floating blobs */}
      {BLOBS.map((blob, i) => (
        <div
          key={i}
          className="absolute rounded-full blur-[70px] pointer-events-none transition-transform duration-[900ms] [cubic-bezier(0.25,0.46,0.45,0.94)]"
          style={{
            width: blob.size,
            height: blob.size,
            left: blob.x,
            top: blob.y,
            background: blob.color,
            transform: `translate(${mousePos.x * blob.xMult}px, ${mousePos.y * blob.yMult}px)`,
            animation: `blobDrift ${blob.dur} ease-in-out infinite ${blob.delay}`,
          }}
        />
      ))}

      {/* Dot grid */}
      <div
        className="absolute inset-0 pointer-events-none opacity-[0.55]"
        style={{
          backgroundImage: 'radial-gradient(circle, rgba(86,153,51,0.18) 1px, transparent 1px)',
          backgroundSize: '36px 36px',
        }}
      />

      {/* Corner accents */}
      <div
        className="absolute w-[260px] h-[260px] rounded-full pointer-events-none top-[-80px] left-[-80px] animate-[cornerPulse_6s_ease-in-out_infinite]"
        style={{ background: 'radial-gradient(circle, rgba(86,153,51,0.12) 0%, transparent 70%)' }}
      />
      <div
        className="absolute w-[260px] h-[260px] rounded-full pointer-events-none bottom-[-80px] right-[-80px] animate-[cornerPulse_6s_ease-in-out_infinite] [animation-delay:-3s]"
        style={{ background: 'radial-gradient(circle, rgba(65,115,39,0.1) 0%, transparent 70%)' }}
      />

      {/* Vignette */}
      <div
        className="absolute inset-0 pointer-events-none"
        style={{ background: 'radial-gradient(ellipse at center, transparent 55%, rgba(42,80,26,0.06) 100%)' }}
      />

    </div>
  )
}
