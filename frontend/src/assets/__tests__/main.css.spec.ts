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
  /** Teks CSS tanpa blok `@supports not selector(::-webkit-scrollbar) { … }` (sampai kurung penutupnya). */
  const tanpaSupports = (teks: string): string => {
    const awal = /@supports not selector\(::-webkit-scrollbar\)\s*\{/g
    let hasil = ''
    let dari = 0
    for (let m = awal.exec(teks); m; m = awal.exec(teks)) {
      hasil += teks.slice(dari, m.index)
      let kedalaman = 1
      let i = m.index + m[0].length
      while (i < teks.length && kedalaman > 0) {
        if (teks[i] === '{') kedalaman++
        else if (teks[i] === '}') kedalaman--
        i++
      }
      dari = i
      awal.lastIndex = i
    }
    return hasil + teks.slice(dari)
  }

  it('scrollbar ramping berlaku global lewat ::-webkit-scrollbar* (Chrome/Edge/Safari), thumb bulat, trek transparan', () => {
    expect(css).toMatch(/\*::-webkit-scrollbar\s*\{[^}]*width:\s*8px/s)
    expect(css).toMatch(/\*::-webkit-scrollbar-thumb/)
    expect(css).toMatch(/\*::-webkit-scrollbar-track/)
  })

  it('CR-063: scrollbar-width/color standar hanya di dalam @supports not selector(::-webkit-scrollbar), tidak di level global', () => {
    // Ada dua blok @supports (global `*` dan alias .scrollbar-slim) yang memuat properti standar.
    const supports = css.match(/@supports not selector\(::-webkit-scrollbar\)\s*\{\s*(\*|\.scrollbar-slim)\s*\{[^}]*scrollbar-width:\s*thin;[^}]*scrollbar-color:[^}]*\}\s*\}/g) ?? []
    expect(supports).toHaveLength(2)
    expect(supports.some((blok) => /\{\s*\*\s*\{/.test(blok))).toBe(true)
    expect(supports.some((blok) => /\.scrollbar-slim/.test(blok))).toBe(true)

    // Di luar blok @supports TIDAK ada scrollbar-width/scrollbar-color (Blink akan mengabaikan ::-webkit-scrollbar*).
    const luar = tanpaSupports(css).replace(/\/\*[\s\S]*?\*\//g, '')
    expect(luar).not.toMatch(/scrollbar-width\s*:/)
    expect(luar).not.toMatch(/scrollbar-color\s*:/)
  })

  it('CR-063: scrollbar menu sidebar mengikuti sudut panel — margin track bawah ≥ radius 1.75rem, khusus .sidebar-scroll (tidak global)', () => {
    const track = css.match(/\.sidebar-scroll::-webkit-scrollbar-track\s*\{([^}]*)\}/)?.[1] ?? ''
    const margin = track.match(/margin-block:\s*([\d.]+)rem\s+([\d.]+)rem/)
    expect(margin, 'margin-block atas dan bawah').not.toBeNull()
    expect(Number(margin?.[1])).toBeGreaterThan(0)
    expect(Number(margin?.[2])).toBeGreaterThanOrEqual(1.75)
    expect(css).toMatch(/\.sidebar-scroll::-webkit-scrollbar\s*\{[^}]*width:\s*10px/s)
    expect(css).toMatch(/\.sidebar-scroll::-webkit-scrollbar-thumb\s*\{[^}]*border-width:\s*3px/s)
    // Tidak bocor ke scrollbar global: aturan margin track hanya ada di bawah selektor .sidebar-scroll.
    expect(css.match(/::-webkit-scrollbar-track[^{]*\{[^}]*margin-block/g) ?? []).toHaveLength(1)
  })

  it('CR-063: tombol panah scrollbar disembunyikan (global dan alias .scrollbar-slim)', () => {
    expect(css).toMatch(/\*::-webkit-scrollbar-button\s*\{[^}]*display:\s*none[^}]*width:\s*0[^}]*height:\s*0/s)
    expect(css).toMatch(/\.scrollbar-slim::-webkit-scrollbar-button\s*\{[^}]*display:\s*none/s)
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

  it('.page-fill/.pane-fill/.table-fill: halaman isi-penuh tanpa gulir window, hanya dari lg (CR-060 Master Data)', () => {
    const media = css.match(/@media \(min-width: 1024px\)\s*\{(?:\s|\/\*[\s\S]*?\*\/)*\.page-fill\s*\{[^}]*\}[\s\S]*?\.table-fill\s*\{[^}]*\}/)
    expect(media, 'tiga utilitas di dalam satu media lg').not.toBeNull()
    const blok = media?.[0] ?? ''
    expect(blok).toMatch(/\.page-fill\s*\{[^}]*calc\(100dvh - var\(--page-top\) - var\(--shell-gap\)\)[^}]*overflow:\s*hidden/s)
    expect(blok).toMatch(/\.pane-fill\s*\{[^}]*height:\s*100%[^}]*overflow-y:\s*auto/s)
    expect(blok).toMatch(/\.table-fill\s*\{[^}]*flex:\s*1 1 0%[^}]*min-height:\s*0[^}]*overflow-y:\s*auto/s)
    expect(css).toMatch(/--page-top:\s*calc\(/)
  })

  it('utilitas isi-penuh adalah kotak penampung (position: relative) dan window tidak menggulir selama ada .page-fill', () => {
    for (const nama of ['page-fill', 'pane-fill', 'table-fill']) {
      const aturan = css.match(new RegExp(`\\.${nama}\\s*\\{([^}]*)\\}`))?.[1] ?? ''
      expect(aturan, nama).toMatch(/position:\s*relative/)
    }
    expect(css).toMatch(/html:has\(\.page-fill\)\s*\{[^}]*overflow:\s*hidden/s)
  })

  it('CR-077: page-fill menggulir sendiri bila isi melebihi viewport; table-fill bertinggi minimum, contain size, isolate', () => {
    const media = css.match(/@media \(min-width: 1024px\)\s*\{[\s\S]*?html:has\(\.page-fill\)/)?.[0] ?? ''
    expect(media).toMatch(/\.page-fill\s*\{[^}]*overflow-x:\s*hidden;[^}]*overflow-y:\s*auto;/s)
    expect(media).toMatch(/\.page-fill > :where\(\*\)\s*\{[^}]*flex-shrink:\s*0/s)
    expect(media).toMatch(/\.page-fill :has\(\.table-fill\)\s*\{[^}]*min-height:\s*min-content/s)
    expect(media).toMatch(/\.table-fill\s*\{[^}]*min-height:\s*var\(--table-fill-min, 18rem\);[^}]*contain:\s*size;[^}]*isolation:\s*isolate;/s)
    // Pengaman window tetap ada.
    expect(css).toMatch(/html:has\(\.page-fill\)\s*\{[^}]*overflow:\s*hidden/s)
  })

  it('CR-079: .pane-fill contain size (lg) supaya min-content grid isi-penuh tidak memuat seluruh daftar panel', () => {
    const media = css.match(/@media \(min-width: 1024px\)\s*\{[\s\S]*?html:has\(\.page-fill\)/)?.[0] ?? ''
    expect(media).toMatch(/\.pane-fill\s*\{\s*contain:\s*size;\s*\}/)
  })

  it('CR-077: sel sticky kiri memakai --sel-sticky (warna baris status) dengan cadangan putih/zebra; pojok header < z-20 navbar', () => {
    expect(css).toMatch(/\.col-sticky-kiri\s*\{[^}]*background-color:\s*var\(--sel-sticky, rgb\(255 255 255\)\)/s)
    expect(css).toMatch(/tbody tr:nth-child\(even\) > \.col-sticky-kiri\s*\{[^}]*var\(--sel-sticky, rgb\(248 250 252\)\)/s)
    const zPojok = Number(css.match(/thead th\.col-sticky-kiri\s*\{[^}]*z-index:\s*(\d+)/s)?.[1])
    expect(zPojok).toBeGreaterThan(10)
    expect(zPojok).toBeLessThan(20)
  })

  it('CR-069: header tabel sticky di dalam .table-fill (spesifisitas nol lewat :where), kelas utilitas Aksi tetap menang', () => {
    expect(css).toMatch(/:where\(\.table-fill\) thead th\s*\{[^}]*position: sticky;[^}]*top: 0;/)
  })

  it('CR-070: di bawah lg, .table-fill menempel di bawah navbar (--sticky-top), bertinggi maksimum, menggulir sendiri (bukan height)', () => {
    const mobile = css.match(/@media \(max-width: 1023\.98px\) \{\s*\.table-fill \{([^}]*)\}/)
    expect(mobile).not.toBeNull()
    expect(mobile?.[1]).toMatch(/position: sticky;/)
    expect(mobile?.[1]).toMatch(/top: var\(--sticky-top\);/)
    expect(mobile?.[1]).toMatch(/overflow-y: auto;/)
    expect(mobile?.[1]).toMatch(/max-height: calc\(100dvh - var\(--sticky-top\) - var\(--shell-gap\) - var\(--table-foot\)\);/)
    expect(css).toMatch(/--table-foot: 6rem;/)
    expect(mobile?.[1]).not.toMatch(/(^|[\s;])height:/)
  })

  it('variabel jarak shell terdefinisi (tinggi navbar dan batas atas sticky)', () => {
    expect(css).toMatch(/--topbar-h:\s*3\.5rem/)
    expect(css).toMatch(/--sticky-top:\s*calc\(/)
  })
})
