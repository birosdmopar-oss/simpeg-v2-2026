/**
 * MAKE-083 — antrean dialog setelah login: satu dialog pada satu waktu, prioritas legacy form-digital → pengingat-cuti →
 * popup-konten → skl, dialog aktif tidak disela, selesai() idempoten, dan pemanggil yang di-unmount membebaskan giliran.
 */
import { effectScope } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'

import { PRIORITAS_DIALOG, resetAntreanDialog, useAntreanDialog, type NamaDialog } from '../antreanDialog'

const tunggu = () => new Promise<void>((r) => setTimeout(r, 0))

afterEach(() => resetAntreanDialog())

describe('useAntreanDialog', () => {
  it('urutan prioritas mengikuti legacy', () => {
    expect(PRIORITAS_DIALOG).toEqual(['form-digital', 'pengingat-cuti', 'popup-konten', 'skl'])
  })

  it('permintaan bersamaan diberi giliran sesuai prioritas, satu per satu', async () => {
    const { minta, aktif } = useAntreanDialog()
    const log: NamaDialog[] = []
    const selesai = new Map<NamaDialog, () => void>()
    for (const nama of ['skl', 'popup-konten', 'form-digital', 'pengingat-cuti'] as NamaDialog[]) {
      void minta(nama).then((s) => {
        log.push(nama)
        selesai.set(nama, s)
      })
    }

    await tunggu()
    expect(log).toEqual(['form-digital'])
    expect(aktif.value).toBe('form-digital')

    for (const nama of ['form-digital', 'pengingat-cuti', 'popup-konten'] as NamaDialog[]) {
      selesai.get(nama)?.()
      await tunggu()
    }
    expect(log).toEqual(['form-digital', 'pengingat-cuti', 'popup-konten', 'skl'])
    expect(aktif.value).toBe('skl')

    selesai.get('skl')?.()
    expect(aktif.value).toBeNull()
  })

  it('dialog aktif tidak disela permintaan berprioritas lebih tinggi yang datang belakangan', async () => {
    const { minta, aktif } = useAntreanDialog()
    const selesaiSkl = await minta('skl')
    let formDapat = false
    void minta('form-digital').then(() => (formDapat = true))

    await tunggu()
    expect([aktif.value, formDapat]).toEqual(['skl', false])

    selesaiSkl()
    await tunggu()
    expect([aktif.value, formDapat]).toEqual(['form-digital', true])
  })

  it('selesai() idempoten: dipanggil dua kali tidak melepas dialog berikutnya', async () => {
    const { minta, aktif } = useAntreanDialog()
    const s1 = await minta('popup-konten')
    const p2 = minta('skl')
    s1()
    await p2
    s1()
    expect(aktif.value).toBe('skl')
  })

  it('pemanggil yang dilepas membebaskan giliran aktif dan permintaan menunggunya', async () => {
    const scope = effectScope()
    const a = scope.run(() => useAntreanDialog())!
    const { minta, aktif } = useAntreanDialog()

    await a.minta('form-digital')
    void a.minta('pengingat-cuti') // menunggu, milik scope yang sama
    let popupDapat = false
    void minta('popup-konten').then(() => (popupDapat = true))
    await tunggu()
    expect(aktif.value).toBe('form-digital')

    scope.stop()
    await tunggu()
    expect([aktif.value, popupDapat]).toEqual(['popup-konten', true])
  })
})
