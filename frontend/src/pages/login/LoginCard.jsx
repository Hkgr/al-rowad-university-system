import { useState, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, useMotionValue, useTransform, useSpring } from 'framer-motion'
import {
  FaEnvelope, FaLock, FaEye, FaEyeSlash, FaSignInAlt,
} from 'react-icons/fa'
import './LoginCard.css'

export default function LoginCard() {
  const [showPass, setShowPass]       = useState(false)
  const [email, setEmail]             = useState('')
  const [password, setPassword]       = useState('')
  const [remember, setRemember]       = useState(false)
  const [loading, setLoading]         = useState(false)
  const [error, setError]             = useState('')
  const [emailFocus, setEmailFocus]   = useState(false)
  const [passFocus, setPassFocus]     = useState(false)

  const navigate = useNavigate()
  const cardRef = useRef(null)
  const mouseX  = useMotionValue(0)
  const mouseY  = useMotionValue(0)

  const rotateX = useSpring(useTransform(mouseY, [-180, 180], [9, -9]),  { stiffness: 180, damping: 28 })
  const rotateY = useSpring(useTransform(mouseX, [-180, 180], [-9, 9]),  { stiffness: 180, damping: 28 })
  const glareX  = useTransform(mouseX, [-180, 180], ['0%', '100%'])
  const glareY  = useTransform(mouseY, [-180, 180], ['0%', '100%'])

  const handleMouseMove = (e) => {
    const rect = cardRef.current.getBoundingClientRect()
    mouseX.set(e.clientX - rect.left - rect.width  / 2)
    mouseY.set(e.clientY - rect.top  - rect.height / 2)
  }

  const handleMouseLeave = () => {
    mouseX.set(0)
    mouseY.set(0)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError('')

    try {
      const response = await fetch('http://127.0.0.1:8000/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ email, password }),
      })

      const data = await response.json()

      if (data.success) {
        localStorage.setItem('token', data.data.token)
        localStorage.setItem('user', JSON.stringify(data.data.user))
        navigate('/dashboard')
      } else {
        setError(data.message || 'Invalid email or password')
      }
    } catch {
      setError('Could not connect to the server. Make sure php artisan serve is running.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <motion.div
      ref={cardRef}
      className="card-wrapper"
      style={{ rotateX, rotateY, transformStyle: 'preserve-3d' }}
      onMouseMove={handleMouseMove}
      onMouseLeave={handleMouseLeave}
      initial={{ opacity: 0, y: 70, scale: 0.88 }}
      animate={{ opacity: 1, y: 0,  scale: 1 }}
      transition={{ duration: 0.9, ease: [0.16, 1, 0.3, 1] }}
    >
      <motion.div
        className="card-glare"
        style={{
          background: `radial-gradient(circle at ${glareX} ${glareY}, rgba(255,255,255,0.14) 0%, transparent 60%)`,
        }}
      />

      <div className="login-card">
        <div className="card-top-bar" />

        <div className="ribbon-corner">
          <div className="ribbon-band">
            <span className="ribbon-star">★</span>
            <span className="ribbon-star">★</span>
            <span className="ribbon-star">★</span>
          </div>
        </div>

        <motion.div
          className="logo-section"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1,  y: 0 }}
          transition={{ delay: 0.25, duration: 0.7 }}
        >
          <div className="logo-glow-ring">
            <div className="logo-ring-inner">
              <img src="/logo.png" alt="Alrowad University Logo" className="logo-img" />
            </div>
          </div>
          <div className="univ-name-block">
            <h1 className="name-arabic">جامعة الرواد للعلوم والتقانة</h1>
            <p className="name-english">Alrowad University for Science & Technology</p>
          </div>
        </motion.div>

        <motion.div
          className="divider"
          initial={{ opacity: 0, scaleX: 0 }}
          animate={{ opacity: 1,  scaleX: 1 }}
          transition={{ delay: 0.4, duration: 0.6 }}
        >
          <span className="divider-line" />
          <span className="divider-label">تسجيل الدخول</span>
          <span className="divider-line" />
        </motion.div>

        <motion.form
          className="login-form"
          onSubmit={handleSubmit}
          initial={{ opacity: 0, y: 16 }}
          animate={{ opacity: 1,  y: 0 }}
          transition={{ delay: 0.6, duration: 0.65 }}
        >
          {error && <div className="error-msg">{error}</div>}

          <div className={`input-wrap ${emailFocus || email ? 'focused' : ''}`}>
            <FaEnvelope className="input-icon" />
            <input
              type="email"
              className="input-field"
              placeholder="البريد الإلكتروني · Email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              onFocus={() => setEmailFocus(true)}
              onBlur={() => setEmailFocus(false)}
              required
            />
          </div>

          <div className={`input-wrap ${passFocus || password ? 'focused' : ''}`}>
            <FaLock className="input-icon" />
            <input
              type={showPass ? 'text' : 'password'}
              className="input-field"
              placeholder="كلمة المرور · Password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onFocus={() => setPassFocus(true)}
              onBlur={() => setPassFocus(false)}
              required
            />
            <button type="button" className="eye-btn" onClick={() => setShowPass((v) => !v)} tabIndex={-1}>
              {showPass ? <FaEyeSlash /> : <FaEye />}
            </button>
          </div>

          <div className="form-row">
            <label className="remember-label">
              <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="checkbox" />
              <span>تذكرني</span>
            </label>
            <a href="#" className="forgot-link">نسيت كلمة المرور؟</a>
          </div>

          <motion.button
            type="submit"
            className="submit-btn"
            disabled={loading}
            whileHover={!loading ? { scale: 1.025, y: -2 } : {}}
            whileTap={!loading  ? { scale: 0.975 }         : {}}
          >
            <span className="btn-shimmer" />
            {loading ? (
              <span className="spinner" />
            ) : (
              <>
                <FaSignInAlt className="btn-icon" />
                <span>دخول</span>
              </>
            )}
          </motion.button>
        </motion.form>

        <motion.div
          className="card-footer"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.85, duration: 0.5 }}
        >
          <span>© 2026</span>
          <span className="footer-sep">·</span>
          <span className="footer-brand">كل الحقوق محفوظة</span>
        </motion.div>
      </div>
    </motion.div>
  )
}
