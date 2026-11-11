/**
 * MAKE-084 — rekap SKL admin: kartu ringkasan per jenis (rata-rata 1 desimal, "—" bila kosong), tabel tanpa kolom Aksi,
 * kritik/saran sebagai teks, filter jenis memuat ulang.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const s = vi.hoisted(() => ({ rekap: vi.fn(), ringkasan: vi.fn(), unduhXlsx: vi.fn() }))
vi.mock('../services/surveiLayanan.service', () => ({ surveiLayananService: s }))

import SurveiLayananRekapView from '../views/SurveiLayananRekapView.vue'

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

beforeEach(() => {
  document.body.innerHTML = ''
  s.rekap.mockResolvedValue({
    items: [{ id: 1, jenis_rwy: 1, jenis_layanan: 'Cuti', nip: '1', nama: 'Ani', unit: 'U', satker: 'S', kritik_saran: '<img src=x>', dinilai_at: '2026-11-11 08:00:00', rate: 5 }],
    total: 1,
    page: 1,
    per_page: 20,
  })
  s.ringkasan.mockResolvedValue({
    periode: { dari: '2025-11-12', sampai: '2026-11-11' },
    per_jenis: [
      { jenis_rwy: 1, jenis_layanan: 'Cuti', jumlah: 3, rata_rata: 4.3, sebaran: {} },
      { jenis_rwy: 2, jenis_layanan: 'KARISKARSU', jumlah: 0, rata_rata: null, sebaran: {} },
    ],
    total: { jumlah: 3, rata_rata: 4.3 },
  })
})

describe('SurveiLayananRekapView', () => {
  it('ringkasan, tabel tanpa Aksi, kritik sebagai teks', async () => {
    mount(SurveiLayananRekapView, { attachTo: document.body })
    await flushPromises()

    expect(q('[data-testid="skl-ringkasan-1"]')!.textContent).toContain('4.3')
    expect(q('[data-testid="skl-ringkasan-2"]')!.textContent).toContain('—')
    expect(q('[data-testid="skl-rekap"] thead')!.textContent).not.toContain('Aksi')
    expect(q('[data-testid="skl-row-1"] img')).toBeNull()
    expect(q('[data-testid="skl-row-1"]')!.textContent).toContain('<img src=x>')
    expect(q('[data-testid="skl-row-1"]')!.textContent).toContain('5 — Sangat Memuaskan')
  })
})
