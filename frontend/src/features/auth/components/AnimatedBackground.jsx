import './AnimatedBackground.css'

const BLOBS = [
  { size: 750, x: '-18%', y: '-18%', color: 'radial-gradient(circle, rgba(86,153,51,0.18) 0%, transparent 68%)', xMult: 0.012, yMult: 0.008, cls: 'blob-0' },
  { size: 650, x: '72%',  y: '62%',  color: 'radial-gradient(circle, rgba(65,115,39,0.14) 0%, transparent 68%)',  xMult: -0.018, yMult: 0.013, cls: 'blob-1' },
  { size: 480, x: '38%',  y: '32%',  color: 'radial-gradient(circle, rgba(122,179,86,0.1) 0%, transparent 68%)',  xMult: 0.009,  yMult: -0.016, cls: 'blob-2' },
  { size: 380, x: '62%',  y: '2%',   color: 'radial-gradient(circle, rgba(86,153,51,0.09) 0%, transparent 68%)',  xMult: -0.008, yMult: 0.01,  cls: 'blob-3' },
  { size: 300, x: '5%',   y: '60%',  color: 'radial-gradient(circle, rgba(168,214,138,0.12) 0%, transparent 68%)', xMult: 0.015, yMult: -0.01, cls: 'blob-4' },
]

export default function AnimatedBackground({ mousePos }) {
  return (
    <div className="animated-bg">
      <div className="bg-mesh" />
      {BLOBS.map((blob, i) => (
        <div
          key={i}
          className={`blob ${blob.cls}`}
          style={{
            width: blob.size,
            height: blob.size,
            left: blob.x,
            top: blob.y,
            background: blob.color,
            transform: `translate(${mousePos.x * blob.xMult}px, ${mousePos.y * blob.yMult}px)`,
          }}
        />
      ))}
      <div className="dot-grid" />
      <div className="corner-accent corner-tl" />
      <div className="corner-accent corner-br" />
      <div className="vignette" />
    </div>
  )
}
