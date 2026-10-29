import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import LegendaStatusPresensi from '../components/LegendaStatusPresensi.vue'
import StatusPresensiChip from '../components/StatusPresensiChip.vue'
import tailwindRaw from '../../../../tailwind.config.js?raw'
import { akhirPekan, chipHarian, infoKode, infoStatusHarian, legendaBaris, legendaKode, NADA_STATUS, nadaBaris, type NadaStatus } from '../statusPresensi'

/** Kontras WCAG 2.x dua warna heks `#rrggbb`. */
function kontras(a: string, b: string): number {
  const lum = (h: string): number => {
    const c = [1, 3, 5].map((i) => Number.parseInt(h.slice(i, i + 2), 16) / 255).map((x) => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4))
    return 0.2126 * c[0]! + 0.7152 * c[1]! + 0.0722 * c[2]!
  }
  const [t, g] = [lum(a), lum(b)].sort((x, y) => y - x)
  return (t! + 0.05) / (g! + 0.05)
}

/** Token tema `status-*` dibaca dari `tailwind.config.js` (satu-satunya tempat nilai heks). */
function tokenStatus(): Record<string, { DEFAULT: string; soft: string; titik: string }> {
  const blok = /status:\s*\{([\s\S]*?)\n {8}\},/.exec(tailwindRaw)![1]!
  const hasil: Record<string, { DEFAULT: string; soft: string; titik: string }> = {}
  for (const m of blok.matchAll(/(\w+):\s*\{\s*DEFAULT:\s*'(#\w{6})',\s*soft:\s*'(#\w{6})',\s*titik:\s*'(#\w{6})'/g)) hasil[m[1]!] = { DEFAULT: m[2]!, soft: m[3]!, titik: m[4]! }
  return hasil
}

describe('statusPresensi (peta kode → chip)', () => {
  it('mencakup semua kode kejadian dan status harian dari API (C0)', () => {
    for (const k of ['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3', 'TPM', 'TPP', 'TK', 'CS']) expect(infoKode(k), k).not.toBeNull()
    for (const s of ['libur', 'tugas_belajar', 'cuti', 'konket', 'hadir', 'TK', 'TPM', 'TPP']) expect(infoStatusHarian(s), s).not.toBeNull()
    expect(infoStatusHarian('di_luar_masa_kerja')).not.toBeNull()
    expect(infoKode('XX')).toBeNull()
    expect(infoStatusHarian('tidak-dikenal')).toBeNull()
  })

  it('warna chip kode default Q5C-03 (TL/PSW kuning, TK/TPM/TPP merah, CS jingga); warna status ikut legacy', () => {
    const nada = (k: string): NadaStatus | undefined => (infoKode(k) ?? infoStatusHarian(k))?.nada
    expect(['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3'].map(nada)).toEqual(Array(6).fill('kuning'))
    expect(['TK', 'TPM', 'TPP'].map(nada)).toEqual(['merah', 'merah', 'merah'])
    expect(nada('CS')).toBe('jingga')
    // [K] laporan_tukin_skp.php:471-495: libur biru, konket jingga, cuti ungu, TB merah muda (bg-others), disable abu.
    expect(['libur', 'konket', 'cuti', 'tugas_belajar', 'di_luar_masa_kerja'].map(nada)).toEqual(['biru', 'jingga', 'ungu', 'pink', 'abu'])
  })

  it('kontras teks/latar setiap token status-* ≥ 4,5:1 dan semua nada punya token', () => {
    const token = tokenStatus()
    expect(Object.keys(token).sort()).toEqual(Object.keys(NADA_STATUS).sort())
    for (const [nama, t] of Object.entries(token)) expect(kontras(t.DEFAULT, t.soft), nama).toBeGreaterThanOrEqual(4.5)
  })

  it('kelas chip/baris/titik memakai token status-* (tanpa heks lepas) sesuai nadanya', () => {
    for (const [nada, k] of Object.entries(NADA_STATUS)) {
      for (const kelas of [k.chip, k.baris, k.titik]) expect(kelas, nada).not.toMatch(/\[#/)
      expect(k.chip).toContain(`status-${nada}`)
      expect(k.titik).toBe(`bg-status-${nada}-titik`)
    }
  })

  it('chipHarian: kode menang; cuti sakit CS tampil bersama cuti; tanpa kode dari status', () => {
    expect(chipHarian('hadir', ['TL2', 'PSW1']).map((c) => c.kode)).toEqual(['TL2', 'PSW1'])
    expect(chipHarian('TPM', ['TPM', 'PSW2']).map((c) => c.kode)).toEqual(['TPM', 'PSW2'])
    expect(chipHarian('cuti', ['CS']).map((c) => c.kode)).toEqual(['Cuti', 'CS'])
    expect(chipHarian('hadir', []).map((c) => c.kode)).toEqual(['Hadir'])
    expect(chipHarian('hadir').map((c) => c.kode)).toEqual(['Hadir'])
    expect(chipHarian('TK').map((c) => c.kode)).toEqual(['TK'])
    expect(chipHarian('libur', null, '2026-10-28').map((c) => c.kode)).toEqual(['Libur'])
    expect(chipHarian('libur', null, '2026-10-31').map((c) => c.kode)).toEqual(['Akhir pekan'])
    expect(chipHarian('di_luar_masa_kerja').map((c) => c.kode)).toEqual(['Di luar masa kerja'])
    expect(chipHarian('hadir', ['ZZ'])).toEqual([{ ...infoStatusHarian('hadir')! }])
  })

  it('akhirPekan: Sabtu/Minggu dari tanggal kalender; format salah = bukan akhir pekan', () => {
    expect(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'].map(akhirPekan)).toEqual([false, true, true, false])
    expect(akhirPekan('31-10-2026')).toBe(false)
  })

  it('nadaBaris [K] legacy: hanya baris non-presensi berwarna; hadir/TK/TPM/TPP tanpa warna baris', () => {
    expect(nadaBaris('libur', '2026-10-28')).toBe('biru')
    expect(nadaBaris('libur', '2026-10-31')).toBe('abu')
    expect(nadaBaris('konket')).toBe('jingga')
    expect(nadaBaris('cuti')).toBe('ungu')
    expect(nadaBaris('tugas_belajar')).toBe('pink')
    expect(nadaBaris('di_luar_masa_kerja')).toBe('abu')
    for (const st of ['hadir', 'TK', 'TPM', 'TPP', 'tidak-dikenal']) expect(nadaBaris(st), st).toBeNull()
  })

  it('legenda terpisah: Kode (kejadian) dan Warna baris (status)', () => {
    expect(legendaKode().map((e) => e.kunci)).toEqual(['TK', 'TPM', 'TPP', 'CS', 'TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3'])
    expect(legendaBaris().map((e) => e.kunci)).toEqual(['libur', 'akhir_pekan', 'konket', 'cuti', 'tugas_belajar', 'di_luar_masa_kerja', 'hadir'])
  })
})

describe('StatusPresensiChip & LegendaStatusPresensi', () => {
  it('chip menampilkan teks kode per kejadian dengan aria-label arti, tanpa atribut title', () => {
    const w = mount(StatusPresensiChip, { props: { status: 'hadir', kode: ['TL2', 'PSW1'] } })
    const chip = w.findAll('[data-kode]')
    expect(chip.map((c) => c.text())).toEqual(['TL2', 'PSW1'])
    expect(chip[0]!.attributes('aria-label')).toBe('Terlambat 31–60 menit')
    expect(chip[0]!.attributes('role')).toBe('img')
    expect(w.html()).not.toContain('title=')
  })

  it('chip tidak merender apa pun untuk status tak dikenal', () => {
    expect(mount(StatusPresensiChip, { props: { status: 'tidak-dikenal' } }).find('[data-testid="status-presensi-chip"]').exists()).toBe(false)
  })

  it('legenda: bagian Warna baris + Kode; versi ringkas hanya Kode', () => {
    const penuh = mount(LegendaStatusPresensi)
    expect(penuh.get('[data-testid="legenda-warna-baris"]').findAll('li').map((l) => l.attributes('data-kode'))).toEqual(legendaBaris().map((e) => e.kunci))
    expect(penuh.get('[data-testid="legenda-kode"]').findAll('li').map((l) => l.attributes('data-kode'))).toEqual(legendaKode().map((e) => e.kunci))
    expect(penuh.get('section').attributes('aria-label')).toBe('Legenda status presensi')
    expect(penuh.findAll('h3').map((h) => h.text())).toEqual(['Warna baris', 'Kode'])

    const ringkas = mount(LegendaStatusPresensi, { props: { ringkas: true } })
    expect(ringkas.find('[data-testid="legenda-warna-baris"]').exists()).toBe(false)
    expect(ringkas.findAll('li').map((l) => l.attributes('data-kode'))).toEqual(legendaKode().map((e) => e.kunci))
  })
})
