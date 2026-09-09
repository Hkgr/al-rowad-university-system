import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { createHash } from 'node:crypto'

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8')
const hashes = {
  arabic: '748022f50c427456ad66031e855349be3448ee1f05a5f1eb59574ebe0c686749',
  'latin-ext': 'da6ada83c3a87c2f0211325dc0a123702e1d4a940958b3e943ac4f37b31d5371',
  latin: '82c13cbd1352d76e97c3d335caa912ae68fecdc3949a43cf8d149eb892384c1b',
}
test('original Cairo WOFF2 subsets are pinned, licensed and served locally', () => {
  for (const [subset, hash] of Object.entries(hashes)) {
    const bytes = readFileSync(new URL(`../src/assets/fonts/cairo/cairo-${subset}-wght.woff2`, import.meta.url))
    assert.equal(bytes.toString('ascii', 0, 4), 'wOF2')
    assert.equal(bytes.readUInt32BE(8), bytes.length)
    assert.equal(createHash('sha256').update(bytes).digest('hex'), hash)
  }
  const license = read('../public/licenses/cairo/OFL.txt')
  assert.match(license, /Copyright 2009 The Cairo Project Authors/)
  assert.match(license, /SIL OPEN FONT LICENSE Version 1.1/)
  assert.match(license, /OTHER DEALINGS IN THE FONT SOFTWARE/)
})
test('central variable faces and Tailwind sans agree without overriding mono or icons', () => {
  const font = read('../src/styles/cairo.css'), global = read('../src/styles/global.css')
  assert.equal((font.match(/@font-face/g) || []).length, 3)
  assert.equal((font.match(/font-weight: 200 1000/g) || []).length, 3)
  assert.equal((font.match(/font-display: swap/g) || []).length, 3)
  assert.equal((font.match(/format\('woff2'\)/g) || []).length, 3)
  assert.doesNotMatch(font, /local\(|https?:|!important/)
  for (const range of ['U+0600-06FF', 'U+0000-00FF', 'U+0100-02BA']) assert.ok(font.includes(range))
  assert.match(global, /@import "\.\/cairo.css"/)
  assert.match(global, /--font-sans: 'Cairo', 'Segoe UI', sans-serif/)
  assert.match(global, /font-family: var\(--font-sans\)/)
  assert.doesNotMatch(global, /--font-mono:|font-family:[^;]*!important/)
})

// Opt-in build check: CAIRO_BUILD_DIR may also target a --base /subpath/ build.
if (process.env.CAIRO_BUILD_DIR) test('production CSS references three emitted unmodified fonts and ships the license', () => {
  const directory = process.env.CAIRO_BUILD_DIR.replaceAll('\\', '/')
  const files = readdirSync(`${directory}/assets`)
  const css = files.filter(f => f.endsWith('.css')).map(f => readFileSync(`${directory}/assets/${f}`, 'utf8')).join('\n')
  const urls = [...css.matchAll(/url\(["']?([^\s)"']+\.woff2)["']?\)/g)].map(m => m[1])
  assert.equal(urls.length, 3)
  for (const [subset, hash] of Object.entries(hashes)) {
    const filename = files.find(f => f.startsWith(`cairo-${subset}-wght-`) && f.endsWith('.woff2'))
    assert.ok(filename && urls.some(u => u.endsWith(`/assets/${filename}`)))
    assert.equal(createHash('sha256').update(readFileSync(`${directory}/assets/${filename}`)).digest('hex'), hash)
  }
  assert.doesNotMatch(css, /fonts\.(?:googleapis|gstatic)\.com/)
  assert.equal(readFileSync(`${directory}/licenses/cairo/OFL.txt`, 'utf8'), read('../public/licenses/cairo/OFL.txt'))
})
