/**
 * CR-060 (AGENTS.md bagian 3) — kontrak CSS global: scrollbar custom ramping untuk SEMUA elemen (Firefox + WebKit) dan
 * utilitas panel gulir mandiri `.pane-scroll` yang menempel di bawah navbar sticky. jsdom tidak menghitung tata letak,
 * jadi yang dikunci adalah teks aturan di main.css.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { describe, expect, it } from 'vitest'

const css = readFileSync(resolve(__dirname, '../main.css'), 'utf-8')

describe('main.css', () => {
  it('scrollbar ramping berlaku global: Firefox (scrollbar-width/color) dan WebKit (::-webkit-scrollbar)', () => {
    expect(css).toMatch(/\*\s*\{[^}]*scrollbar-width:\s*thin;[^}]*scrollbar-color:/s)
    expect(css).toMatch(/\*::-webkit-scrollbar\s*\{[^}]*width:\s*8px/s)
    expect(css).toMatch(/\*::-webkit-scrollbar-thumb/)
    expect(css).toMatch(/\*::-webkit-scrollbar-track/)
  })

  it('.pane-scroll: sticky di bawah navbar, tinggi = viewport − navbar, gulir mandiri, hanya dari lg', () => {
    const blok = css.match(/@media \(min-width: 1024px\)\s*\{\s*\.pane-scroll\s*\{([^}]*)\}/s)
    expect(blok, '.pane-scroll di dalam media lg').not.toBeNull()
    const aturan = blok?.[1] ?? ''
    expect(aturan).toMatch(/position:\s*sticky/)
    expect(aturan).toMatch(/top:\s*var\(--sticky-top\)/)
    expect(aturan).toMatch(/max-height:\s*calc\(100vh - var\(--sticky-top\)/)
    expect(aturan).toMatch(/overflow-y:\s*auto/)
    expect(aturan).toMatch(/overscroll-behavior:\s*contain/)
  })

  it('variabel jarak shell terdefinisi (tinggi navbar dan batas atas sticky)', () => {
    expect(css).toMatch(/--topbar-h:\s*3\.5rem/)
    expect(css).toMatch(/--sticky-top:\s*calc\(/)
  })
})
