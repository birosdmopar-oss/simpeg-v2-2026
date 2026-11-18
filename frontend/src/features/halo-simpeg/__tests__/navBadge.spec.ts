/**
 * `sisipBadgeHalo` (AC-E08.12). SEMENTARA: ringkasan sintetis berbentuk `Ringkasan` PRD v1.3 §6.1b; dicocokkan ulang dengan
 * fixture `tiket-ringkasan` saat README MAKE-071 beku.
 */
import { MessageCircle } from 'lucide-vue-next'
import { describe, expect, it } from 'vitest'

import type { NavGroup } from '@/shared/layouts/nav.config'

import { sisipBadgeHalo } from '../navBadge'
import type { Ringkasan } from '../types'

const groups = (): NavGroup[] => [
  { key: 'utama', items: [{ key: 'home', label: 'Beranda', icon: MessageCircle, to: '/', phase: 1 }] },
  {
    key: 'bantuan',
    title: 'Pusat Bantuan',
    items: [
      { key: 'halo', label: 'Halo Simpeg', icon: MessageCircle, to: '/halo-simpeg', phase: 8 },
      { key: 'faq', label: 'FAQ', icon: MessageCircle, to: '/faq', phase: 2 },
    ],
  },
]

/** Tiket sudah dibaca tetapi pengingat 24 jam lewat: badge > total (R5). */
const RINGKASAN: Ringkasan = { belum_dibaca_saya: 1, belum_dibaca_petugas: 2, perlu_tindak: 2, badge: 4, total: 3 }

const halo = (g: NavGroup[]) => g.flatMap((x) => x.items).find((i) => i.key === 'halo')

describe('sisipBadgeHalo', () => {
  it('memakai ringkasan.badge (bukan total) di item halo; masukan tidak dimutasi', () => {
    const asal = groups()
    const hasil = sisipBadgeHalo(asal, RINGKASAN.badge)
    expect(halo(hasil)?.badge).toBe(4)
    expect(halo(asal)?.badge).toBeUndefined()
    expect(hasil[0]).toBe(asal[0])
    expect(hasil[1]?.items[1]).toBe(asal[1]?.items[1])
  })

  it.each([0, -1, null, undefined, Number.NaN])('badge %s → tanpa badge (badge lama dihapus)', (nilai) => {
    const asal = sisipBadgeHalo(groups(), 5)
    const hasil = sisipBadgeHalo(asal, nilai)
    expect(halo(hasil)).toBeDefined()
    expect('badge' in (halo(hasil) ?? {})).toBe(false)
  })

  it('item halo tidak ada (role/fase) → groups apa adanya', () => {
    const tanpaHalo: NavGroup[] = [groups()[0]!]
    expect(sisipBadgeHalo(tanpaHalo, 3)).toBe(tanpaHalo)
  })
})
