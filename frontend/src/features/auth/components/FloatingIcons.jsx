import { motion } from 'framer-motion'
import {
  FaGraduationCap, FaBook, FaAtom, FaFlask, FaMicroscope,
  FaPencilAlt, FaCalculator, FaGlobe, FaChalkboardTeacher,
  FaLaptopCode, FaAward, FaLightbulb, FaUniversity, FaDna,
  FaBookOpen, FaBrain, FaRocket, FaLeaf, FaStar, FaCompass,
} from 'react-icons/fa'

const ICONS = [
  { Icon: FaGraduationCap,     size: 40, x: 7,  y: 10, op: 0.2,  color: '#569933', dur: 13, del: 0,   ry: 28,  rx: 22  },
  { Icon: FaBook,              size: 26, x: 86, y: 16, op: 0.15, color: '#417327', dur: 16, del: 2.5, ry: -24, rx: 30  },
  { Icon: FaAtom,              size: 46, x: 91, y: 54, op: 0.13, color: '#7ab356', dur: 20, del: 5,   ry: 32,  rx: -28 },
  { Icon: FaFlask,             size: 28, x: 11, y: 74, op: 0.16, color: '#569933', dur: 15, del: 1.5, ry: -30, rx: 26  },
  { Icon: FaMicroscope,        size: 34, x: 74, y: 84, op: 0.14, color: '#417327', dur: 17, del: 7,   ry: 22,  rx: -32 },
  { Icon: FaPencilAlt,         size: 24, x: 4,  y: 42, op: 0.17, color: '#569933', dur: 12, del: 3.5, ry: 26,  rx: 20  },
  { Icon: FaCalculator,        size: 30, x: 33, y: 6,  op: 0.14, color: '#7ab356', dur: 14, del: 6,   ry: -22, rx: -28 },
  { Icon: FaGlobe,             size: 38, x: 64, y: 4,  op: 0.12, color: '#417327', dur: 22, del: 2,   ry: 20,  rx: 25  },
  { Icon: FaLaptopCode,        size: 32, x: 93, y: 28, op: 0.15, color: '#569933', dur: 18, del: 8,   ry: -28, rx: 22  },
  { Icon: FaAward,             size: 36, x: 2,  y: 22, op: 0.13, color: '#7ab356', dur: 16, del: 4,   ry: 24,  rx: -20 },
  { Icon: FaLightbulb,         size: 28, x: 50, y: 93, op: 0.16, color: '#569933', dur: 13, del: 9,   ry: -26, rx: 24  },
  { Icon: FaUniversity,        size: 42, x: 19, y: 90, op: 0.11, color: '#417327', dur: 24, del: 1,   ry: 22,  rx: -24 },
  { Icon: FaDna,               size: 30, x: 80, y: 40, op: 0.14, color: '#7ab356', dur: 15, del: 10,  ry: 28,  rx: 18  },
  { Icon: FaBookOpen,          size: 26, x: 47, y: 3,  op: 0.17, color: '#569933', dur: 12, del: 3,   ry: -24, rx: -26 },
  { Icon: FaBrain,             size: 36, x: 21, y: 52, op: 0.12, color: '#417327', dur: 21, del: 6.5, ry: 18,  rx: 28  },
  { Icon: FaRocket,            size: 30, x: 58, y: 80, op: 0.14, color: '#7ab356', dur: 17, del: 2.5, ry: -30, rx: -18 },
  { Icon: FaLeaf,              size: 22, x: 39, y: 97, op: 0.19, color: '#569933', dur: 14, del: 5.5, ry: 24,  rx: 20  },
  { Icon: FaStar,              size: 20, x: 97, y: 68, op: 0.18, color: '#c9a227', dur: 11, del: 0.5, ry: -20, rx: 22  },
  { Icon: FaChalkboardTeacher, size: 34, x: 14, y: 32, op: 0.11, color: '#417327', dur: 23, del: 7.5, ry: 26,  rx: -22 },
  { Icon: FaCompass,           size: 28, x: 70, y: 60, op: 0.13, color: '#7ab356', dur: 16, del: 4.5, ry: -24, rx: 20  },
]

export default function FloatingIcons({ mousePos }) {
  return (
    <div style={{ position: 'absolute', inset: 0, pointerEvents: 'none', overflow: 'hidden', zIndex: 2 }}>
      {ICONS.map((item, i) => {
        const parallaxX = mousePos.x * (0.015 + (i % 4) * 0.006)
        const parallaxY = mousePos.y * (0.015 + (i % 3) * 0.007)
        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              left: `${item.x}%`,
              top: `${item.y}%`,
              transform: `translate(${parallaxX}px, ${parallaxY}px)`,
              transition: 'transform 1s cubic-bezier(0.25, 0.46, 0.45, 0.94)',
            }}
          >
            <motion.div
              initial={{ opacity: 0, scale: 0, rotate: -20 }}
              animate={{
                opacity: item.op, scale: 1, rotate: 0,
                y: [0, item.ry, item.ry * 0.3, -item.ry * 0.5, 0],
                x: [0, item.rx * 0.4, item.rx, item.rx * 0.2, 0],
              }}
              transition={{
                opacity: { duration: 1.2, delay: item.del * 0.4 },
                scale:   { duration: 0.9, delay: item.del * 0.4, ease: [0.16, 1, 0.3, 1] },
                rotate:  { duration: 0.9, delay: item.del * 0.4 },
                y: { duration: item.dur, repeat: Infinity, ease: 'easeInOut', delay: item.del },
                x: { duration: item.dur * 1.4, repeat: Infinity, ease: 'easeInOut', delay: item.del + 0.5 },
              }}
              style={{ color: item.color, display: 'flex' }}
            >
              <item.Icon size={item.size} />
            </motion.div>
          </div>
        )
      })}
    </div>
  )
}
