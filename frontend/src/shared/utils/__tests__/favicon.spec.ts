/**
 * Favicon tab browser (CR-061): memakai logo Kemenparekraf dari legacy, bukan ikon bawaan Vite. Test membaca
 * `index.html` dan `public/favicon.svg` langsung dari berkas (tanpa data contoh) dan memastikan ketiga link ada.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const read = (relative: string): string => readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf-8')

describe('favicon tab browser (CR-061)', () => {
  const html = read('../../../../index.html')

  it('index.html memuat link SVG, PNG 100x100, dan apple-touch-icon', () => {
    expect(html).toContain('<link rel="icon" type="image/svg+xml" href="/favicon.svg" />')
    expect(html).toContain('<link rel="icon" type="image/png" sizes="100x100" href="/favicon-100.png" />')
    expect(html).toContain('<link rel="apple-touch-icon" href="/apple-touch-icon.png" />')
  })

  it('favicon.svg berisi logo legacy (viewBox 542.21), bukan ikon bawaan Vite', () => {
    const svg = read('../../../../public/favicon.svg')
    expect(svg).toContain('viewBox="0 0 542.21 542.21"')
    expect(svg).not.toContain('#863bff') // warna ikon Vite
    expect(svg).not.toContain('logo-vector') // judul sumber dibuang
  })

  it('favicon.svg bersih: tanpa script, foreignObject, image, atau URL eksternal', () => {
    const svg = read('../../../../public/favicon.svg')
    expect(svg).not.toMatch(/<script|<foreignObject|<image|xlink:href|href=/)
    expect(svg.match(/https?:\/\/[^"\s]*/g)).toEqual(['http://www.w3.org/2000/svg'])
  })
})
