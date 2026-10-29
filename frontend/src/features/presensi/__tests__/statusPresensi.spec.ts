import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import LegendaStatusPresensi from '../components/LegendaStatusPresensi.vue'
import StatusPresensiChip from '../components/StatusPresensiChip.vue'
import { chipHarian, entriLegenda, infoKode, infoStatusHarian, NADA_STATUS, nadaBaris, URUTAN_LEGENDA, type NadaStatus } from '../statusPresensi'

/** Kontras WCAG 2.x dua warna heks `#rrggbb`. */
function kontras(a: string, b: string): number {
  const lum = (h: string): number => {
    const c = [1, 3, 5].map((i) => Number.parseInt(h.slice(i, i + 2), 16) / 255).map((x) => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4))
    return 0.2126 * c[0]! + 0.7152 * c[1]! + 0.0722 * c[2]!
  }
  const [t, g] = [lum(a), lum(b)].sort((x, y) => y - x)
  return (t! + 0.05) / (g! + 0.05)
}

describe('statusPresensi (peta kode → chip)', () => {
  it('mencakup semua kode kejadian dan status harian dari API (C0)', () => {
    for (const k of ['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3', 'TPM', 'TPP', 'TK', 'CS']) expect(infoKode(k), k).not.toBeNull()
    for (const s of ['libur', 'tugas_belajar', 'cuti', 'konket', 'hadir', 'TK', 'TPM', 'TPP']) expect(infoStatusHarian(s), s).not.toBeNull()
    expect(infoStatusHarian('di_luar_masa_kerja')).toBeNull()
    expect(infoKode('XX')).toBeNull()
    expect(infoStatusHarian('tidak-dikenal')).toBeNull()
  })

  it('warna default Q5C-03: TL/PSW kuning, TK/TPM/TPP merah, CS jingga, cuti/TB biru, libur abu', () => {
    const nada = (k: string): NadaStatus | undefined => (infoKode(k) ?? infoStatusHarian(k))?.nada
    expect(['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3'].map(nada)).toEqual(Array(6).fill('kuning'))
    expect(['TK', 'TPM', 'TPP'].map(nada)).toEqual(['merah', 'merah', 'merah'])
    expect(nada('CS')).toBe('jingga')
    expect(['cuti', 'tugas_belajar', 'konket'].map(nada)).toEqual(['biru', 'biru', 'biru'])
    expect(nada('libur')).toBe('abu')
  })

  it('kontras teks/latar setiap nada ≥ 4,5:1', () => {
    for (const [nama, n] of Object.entries(NADA_STATUS)) expect(kontras(n.teks, n.latar), nama).toBeGreaterThanOrEqual(4.5)
  })

  it('chipHarian: kode menang; cuti sakit CS tampil bersama cuti; tanpa kode dari status', () => {
    expect(chipHarian('hadir', ['TL2', 'PSW1']).map((c) => c.kode)).toEqual(['TL2', 'PSW1'])
    expect(chipHarian('TPM', ['TPM', 'PSW2']).map((c) => c.kode)).toEqual(['TPM', 'PSW2'])
    expect(chipHarian('cuti', ['CS']).map((c) => c.kode)).toEqual(['Cuti', 'CS'])
    expect(chipHarian('hadir', []).map((c) => c.kode)).toEqual(['Hadir'])
    expect(chipHarian('hadir').map((c) => c.kode)).toEqual(['Hadir'])
    expect(chipHarian('TK').map((c) => c.kode)).toEqual(['TK'])
    expect(chipHarian('libur', null).map((c) => c.kode)).toEqual(['Libur'])
    expect(chipHarian('di_luar_masa_kerja')).toEqual([])
    expect(chipHarian('hadir', ['ZZ'])).toEqual([{ ...infoStatusHarian('hadir')! }])
  })

  it('nadaBaris: terberat menang; hadir bersih dan di luar masa kerja tanpa warna', () => {
    expect(nadaBaris('hadir', ['TL1', 'PSW3'])).toBe('kuning')
    expect(nadaBaris('TPM', ['TPM', 'PSW1'])).toBe('merah')
    expect(nadaBaris('cuti', ['CS'])).toBe('jingga')
    expect(nadaBaris('cuti', [])).toBe('biru')
    expect(nadaBaris('libur')).toBe('abu')
    expect(nadaBaris('hadir')).toBeNull()
    expect(nadaBaris('di_luar_masa_kerja')).toBeNull()
  })

  it('legenda memuat semua entri berurutan', () => {
    expect(entriLegenda().map((e) => e.kunci)).toEqual([...URUTAN_LEGENDA])
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

  it('chip tidak merender apa pun untuk hari di luar masa kerja', () => {
    expect(mount(StatusPresensiChip, { props: { status: 'di_luar_masa_kerja' } }).find('[data-testid="status-presensi-chip"]').exists()).toBe(false)
  })

  it('legenda lengkap dan versi ringkas hanya kode potongan', () => {
    const penuh = mount(LegendaStatusPresensi)
    expect(penuh.findAll('li').map((l) => l.attributes('data-kode'))).toEqual([...URUTAN_LEGENDA])
    expect(penuh.get('section').attributes('aria-label')).toBe('Legenda status presensi')

    const ringkas = mount(LegendaStatusPresensi, { props: { ringkas: true } })
    expect(ringkas.findAll('li').map((l) => l.attributes('data-kode'))).toEqual(['TK', 'TPM', 'TPP', 'CS', 'TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3'])
  })
})
