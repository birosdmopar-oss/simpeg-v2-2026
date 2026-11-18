/** Tujuan entri lonceng H8-15 (b). SEMENTARA: ringkasan sintetis bentuk PRD §6.1b sampai README MAKE-071 beku. */
import { describe, expect, it } from 'vitest'

import { tujuanEntriTiket } from '../entriTiket'
import type { Ringkasan } from '../types'

const r = (over: Partial<Ringkasan>): Ringkasan => ({ belum_dibaca_saya: 0, belum_dibaca_petugas: 0, perlu_tindak: 0, badge: 0, total: 0, ...over })

describe('tujuanEntriTiket', () => {
  it('sisi petugas > 0 (belum dibaca petugas atau perlu ditindak) → tab antrean', () => {
    expect(tujuanEntriTiket(r({ belum_dibaca_petugas: 1 }))).toEqual({ name: 'halo-simpeg', query: { tab: 'antrean' } })
    expect(tujuanEntriTiket(r({ perlu_tindak: 2 }))).toEqual({ name: 'halo-simpeg', query: { tab: 'antrean' } })
  })

  it('hanya sisi pemilik, atau ringkasan belum dimuat → /halo-simpeg', () => {
    expect(tujuanEntriTiket(r({ belum_dibaca_saya: 3 }))).toEqual({ name: 'halo-simpeg' })
    expect(tujuanEntriTiket(null)).toEqual({ name: 'halo-simpeg' })
  })
})
