/**
 * StatusBadge (shared) — status verifikasi riwayat/usulan Modul B: 0 Menunggu (kuning), 1 Disetujui (hijau),
 * 2 Ditolak (merah), 10 Dihapus (abu-abu); 3 Diproses hanya bila flag aktif.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import StatusBadge from '../StatusBadge.vue'

describe('StatusBadge', () => {
  it.each([
    [0, 'Menunggu', 'bg-warning-soft'],
    [1, 'Disetujui', 'bg-success-soft'],
    [2, 'Ditolak', 'bg-danger-soft'],
    [10, 'Dihapus', 'bg-slate-100'],
  ])('status %i → "%s" dengan warna %s', (status, label, tone) => {
    const badge = mount(StatusBadge, { props: { status } }).get('[data-testid="status-badge"]')
    expect(badge.text()).toBe(label)
    expect(badge.classes()).toContain(tone)
    expect(badge.attributes('data-status')).toBe(String(status))
  })

  it('status 3 tampil "Diproses" hanya bila flag aktif; tanpa flag dianggap Menunggu', () => {
    const off = mount(StatusBadge, { props: { status: 3 } }).get('[data-testid="status-badge"]')
    expect(off.text()).toBe('Menunggu')
    expect(off.attributes('data-status')).toBe('0')

    const on = mount(StatusBadge, { props: { status: 3, diprosesEnabled: true } }).get('[data-testid="status-badge"]')
    expect(on.text()).toBe('Diproses')
    expect(on.classes()).toContain('bg-info-soft')
  })

  it('status tak dikenal → netral "Tidak diketahui"', () => {
    const badge = mount(StatusBadge, { props: { status: 7 } }).get('[data-testid="status-badge"]')
    expect(badge.text()).toBe('Tidak diketahui')
    expect(badge.classes()).toContain('bg-slate-100')
  })
})
