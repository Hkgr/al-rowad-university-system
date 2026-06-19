import { useState, useCallback } from 'react'
import AnimatedBackground from './AnimatedBackground'
import FloatingIcons from './FloatingIcons'
import LoginCard from './LoginCard'
import './LoginPage.css'

export default function LoginPage() {
  const [mousePos, setMousePos] = useState({ x: 0, y: 0 })

  const handleMouseMove = useCallback((e) => {
    setMousePos({
      x: e.clientX - window.innerWidth / 2,
      y: e.clientY - window.innerHeight / 2,
    })
  }, [])

  return (
    <div className="login-page" onMouseMove={handleMouseMove}>
      <AnimatedBackground mousePos={mousePos} />
      <FloatingIcons mousePos={mousePos} />
<div className="login-center">
        <LoginCard />
      </div>
    </div>
  )
}
