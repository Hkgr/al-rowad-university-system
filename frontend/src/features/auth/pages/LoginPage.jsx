import { useState, useCallback } from 'react'
import AnimatedBackground from '../components/AnimatedBackground'
import FloatingIcons from '../components/FloatingIcons'
import LoginCard from '../components/LoginCard'

export default function LoginPage() {
  const [mousePos, setMousePos] = useState({ x: 0, y: 0 })

  const handleMouseMove = useCallback((e) => {
    setMousePos({
      x: e.clientX - window.innerWidth / 2,
      y: e.clientY - window.innerHeight / 2,
    })
  }, [])

  return (
    <div
      className="w-screen h-screen relative overflow-hidden flex items-center justify-center"
      onMouseMove={handleMouseMove}
    >
      <AnimatedBackground mousePos={mousePos} />
      <FloatingIcons mousePos={mousePos} />
      <div className="relative z-10 flex items-center justify-center w-full p-5">
        <LoginCard />
      </div>
    </div>
  )
}
