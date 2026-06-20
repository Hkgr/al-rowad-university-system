import { useState, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, useMotionValue, useTransform, useSpring } from 'framer-motion'
import {
  FaEnvelope, FaLock, FaEye, FaEyeSlash, FaSignInAlt,
} from 'react-icons/fa'

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
  const cardRef  = useRef(null)
  const mouseX   = useMotionValue(0)
  const mouseY   = useMotionValue(0)

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
      className="[perspective:1400px] relative cursor-default"
      style={{ rotateX, rotateY, transformStyle: 'preserve-3d' }}
      onMouseMove={handleMouseMove}
      onMouseLeave={handleMouseLeave}
      initial={{ opacity: 0, y: 70, scale: 0.88 }}
      animate={{ opacity: 1, y: 0,  scale: 1 }}
      transition={{ duration: 0.9, ease: [0.16, 1, 0.3, 1] }}
    >
      {/* Glare overlay */}
      <motion.div
        className="absolute inset-[-1px] rounded-[26px] pointer-events-none z-[2]"
        style={{
          background: `radial-gradient(circle at ${glareX} ${glareY}, rgba(255,255,255,0.14) 0%, transparent 60%)`,
        }}
      />

      {/* Card */}
      <div className="w-[440px] max-w-[calc(100vw-32px)] bg-white/82 backdrop-blur-[28px] [backdrop-saturate:160%] border border-primary/22 rounded-[26px] px-9 pt-9 pb-7 relative overflow-hidden shadow-[0_24px_64px_rgba(86,153,51,0.18),0_8px_24px_rgba(65,115,39,0.12),0_2px_8px_rgba(0,0,0,0.06),inset_0_0_0_1px_rgba(255,255,255,0.85),inset_0_2px_0_rgba(255,255,255,0.9)]">

        {/* Animated top bar */}
        <div
          className="absolute top-0 left-0 right-0 h-1 rounded-t-[26px] animate-[barFlow_4s_linear_infinite]"
          style={{
            background: 'linear-gradient(90deg, #569933, #7ab356, #a8d68a, #7ab356, #417327, #569933)',
            backgroundSize: '250% 100%',
          }}
        />

        {/* Syrian flag ribbon corner */}
        <div className="absolute top-0 right-0 w-[90px] h-[90px] overflow-hidden rounded-tr-[26px] pointer-events-none z-10">
          <div
            className="absolute top-[18px] right-[-28px] w-[120px] h-[34px] flex items-center justify-center gap-1 rotate-45 shadow-[0_2px_8px_rgba(0,0,0,0.25)] animate-[ribbonGlow_2.5s_ease-in-out_infinite]"
            style={{
              background: 'linear-gradient(to bottom, #007a3d 0%, #007a3d 33%, #f5f5f5 33%, #f5f5f5 66%, #111111 66%, #111111 100%)',
            }}
          >
            <span className="text-[#ce1126] text-[8px] leading-none -rotate-45 [text-shadow:0_0_4px_rgba(206,17,38,0.6)] animate-[starPulse_2.5s_ease-in-out_infinite]">★</span>
            <span className="text-[#ce1126] text-[8px] leading-none -rotate-45 [text-shadow:0_0_4px_rgba(206,17,38,0.6)] animate-[starPulse_2.5s_ease-in-out_infinite] [animation-delay:0.2s]">★</span>
            <span className="text-[#ce1126] text-[8px] leading-none -rotate-45 [text-shadow:0_0_4px_rgba(206,17,38,0.6)] animate-[starPulse_2.5s_ease-in-out_infinite] [animation-delay:0.4s]">★</span>
          </div>
        </div>

        {/* Logo section */}
        <motion.div
          className="flex flex-col items-center gap-3.5 mb-[22px] pt-1.5"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.25, duration: 0.7 }}
        >
          <div
            className="w-24 h-24 rounded-full p-[3px] animate-[ringRotate_6s_linear_infinite] shadow-[0_0_24px_rgba(86,153,51,0.45),0_0_52px_rgba(86,153,51,0.2)]"
            style={{ background: 'conic-gradient(from 0deg, #569933, #7ab356, #a8d68a, #7ab356, #417327, #569933)' }}
          >
            <div className="w-full h-full rounded-full bg-white flex items-center justify-center p-1.5">
              <img src="/logo.png" alt="Alrowad University Logo" className="w-full h-full object-contain rounded-full" />
            </div>
          </div>
          <div className="text-center">
            <h1 className="text-[14.5px] font-extrabold text-text-dark direction-rtl leading-[1.45] tracking-[0.2px] m-0" style={{ direction: 'rtl' }}>
              جامعة الرواد للعلوم والتقانة
            </h1>
            <p className="text-[11px] font-medium text-primary-dark mt-1 tracking-[0.3px]">
              Alrowad University for Science &amp; Technology
            </p>
          </div>
        </motion.div>

        {/* Divider */}
        <motion.div
          className="flex items-center gap-3 mb-5"
          initial={{ opacity: 0, scaleX: 0 }}
          animate={{ opacity: 1, scaleX: 1 }}
          transition={{ delay: 0.4, duration: 0.6 }}
        >
          <span className="flex-1 h-px bg-gradient-to-r from-transparent via-primary/35 to-transparent" />
          <span className="text-[13px] font-bold text-primary-dark whitespace-nowrap tracking-[0.5px]" style={{ direction: 'rtl' }}>
            تسجيل الدخول
          </span>
          <span className="flex-1 h-px bg-gradient-to-r from-transparent via-primary/35 to-transparent" />
        </motion.div>

        {/* Form */}
        <motion.form
          className="flex flex-col gap-3.5"
          onSubmit={handleSubmit}
          initial={{ opacity: 0, y: 16 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.6, duration: 0.65 }}
        >
          {error && (
            <div className="bg-red-500/8 border border-red-500/30 text-red-600 rounded-[10px] px-3.5 py-2.5 text-[13px] text-center" style={{ direction: 'rtl' }}>
              {error}
            </div>
          )}

          {/* Email input */}
          <div className={`relative flex items-center rounded-[13px] border-[1.5px] overflow-hidden transition-all duration-[250ms] ${
            emailFocus || email
              ? 'border-primary bg-white/95 shadow-[0_0_0_4px_rgba(86,153,51,0.1),0_4px_16px_rgba(86,153,51,0.1)]'
              : 'border-primary/20 bg-[rgba(245,251,240,0.65)]'
          }`}>
            <FaEnvelope className={`absolute left-3.5 text-[15px] pointer-events-none transition-colors duration-[250ms] ${emailFocus || email ? 'text-primary' : 'text-primary-light'}`} />
            <input
              type="email"
              className="w-full py-3.5 pr-3.5 pl-[42px] border-none outline-none bg-transparent text-[14px] font-medium text-text-dark placeholder:text-text-light placeholder:font-normal"
              placeholder="البريد الإلكتروني · Email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              onFocus={() => setEmailFocus(true)}
              onBlur={() => setEmailFocus(false)}
              required
            />
          </div>

          {/* Password input */}
          <div className={`relative flex items-center rounded-[13px] border-[1.5px] overflow-hidden transition-all duration-[250ms] ${
            passFocus || password
              ? 'border-primary bg-white/95 shadow-[0_0_0_4px_rgba(86,153,51,0.1),0_4px_16px_rgba(86,153,51,0.1)]'
              : 'border-primary/20 bg-[rgba(245,251,240,0.65)]'
          }`}>
            <FaLock className={`absolute left-3.5 text-[15px] pointer-events-none transition-colors duration-[250ms] ${passFocus || password ? 'text-primary' : 'text-primary-light'}`} />
            <input
              type={showPass ? 'text' : 'password'}
              className="w-full py-3.5 pr-3.5 pl-[42px] border-none outline-none bg-transparent text-[14px] font-medium text-text-dark placeholder:text-text-light placeholder:font-normal"
              placeholder="كلمة المرور · Password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onFocus={() => setPassFocus(true)}
              onBlur={() => setPassFocus(false)}
              required
            />
            <button
              type="button"
              className="absolute right-3 bg-none border-none text-primary-light cursor-pointer p-1.5 flex items-center text-[15px] rounded-md transition-all duration-200 hover:text-primary hover:bg-primary/8"
              onClick={() => setShowPass((v) => !v)}
              tabIndex={-1}
            >
              {showPass ? <FaEyeSlash /> : <FaEye />}
            </button>
          </div>

          {/* Remember me + forgot password */}
          <div className="flex justify-between items-center -mt-0.5" style={{ direction: 'rtl' }}>
            <label className="flex items-center gap-2 text-[13px] font-medium text-text-gray cursor-pointer select-none">
              <input
                type="checkbox"
                checked={remember}
                onChange={(e) => setRemember(e.target.checked)}
                className="appearance-none w-[17px] h-[17px] border-[1.5px] border-primary/40 rounded-[5px] bg-[rgba(245,251,240,0.8)] cursor-pointer relative transition-all duration-200 flex-shrink-0 checked:bg-primary checked:border-primary"
              />
              <span>تذكرني</span>
            </label>
            <a href="#" className="text-[12.5px] font-semibold text-primary no-underline transition-all duration-200 hover:text-primary-dark hover:underline">
              نسيت كلمة المرور؟
            </a>
          </div>

          {/* Submit button */}
          <motion.button
            type="submit"
            className="relative w-full mt-1 py-[15px] px-5 border-none rounded-[13px] text-white text-[17px] font-extrabold cursor-pointer flex items-center justify-center gap-2.5 overflow-hidden tracking-[0.5px] transition-shadow duration-300 disabled:cursor-not-allowed disabled:opacity-[0.82]"
            style={{
              background: 'linear-gradient(135deg, #569933 0%, #4a8a2c 50%, #417327 100%)',
              boxShadow: '0 10px 30px rgba(86,153,51,0.45), 0 4px 8px rgba(65,115,39,0.3)',
              direction: 'rtl',
            }}
            disabled={loading}
            whileHover={!loading ? { scale: 1.025, y: -2 } : {}}
            whileTap={!loading  ? { scale: 0.975 }         : {}}
          >
            <span
              className="absolute top-0 left-[-120%] w-[60%] h-full pointer-events-none animate-[shimmerSlide_2.8s_ease-in-out_infinite] skew-x-[-18deg]"
              style={{ background: 'linear-gradient(110deg, transparent 20%, rgba(255,255,255,0.28) 50%, transparent 80%)' }}
            />
            {loading ? (
              <span className="w-[22px] h-[22px] border-[3px] border-white/30 border-t-white rounded-full animate-[spin_0.75s_linear_infinite] inline-block flex-shrink-0" />
            ) : (
              <>
                <FaSignInAlt className="text-[16px]" />
                <span>دخول</span>
              </>
            )}
          </motion.button>
        </motion.form>

        {/* Footer */}
        <motion.div
          className="mt-[22px] flex items-center justify-center gap-1.5 text-[11px] text-text-light"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.85, duration: 0.5 }}
        >
          <span>© 2026</span>
          <span className="text-primary/40">·</span>
          <span className="text-primary-dark font-semibold" style={{ direction: 'rtl' }}>كل الحقوق محفوظة</span>
        </motion.div>

      </div>
    </motion.div>
  )
}
