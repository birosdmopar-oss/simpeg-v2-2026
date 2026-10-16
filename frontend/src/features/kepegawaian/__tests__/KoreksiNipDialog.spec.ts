/**
 * KoreksiNipDialog (B-06, MAKE-010/011) + foto PegawaiHeaderCard: validasi format/NIP sama di sisi klien, 422
 * `errors.nip_baru` kembali ke langkah isi, dialog tidak bisa ditutup selama proses, peringatan ganti password; foto
 * profil dari blob (object URL dibuat lalu dicabut saat dilepas).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({ pegawaiService: { koreksiNip: vi.fn(), foto: vi.fn() } }))

import KoreksiNipDialog from '../components/KoreksiNipDialog.vue'
import PegawaiHeaderCard from '../components/PegawaiHeaderCard.vue'
import { pegawaiService } from '../services/pegawai.service'

import { apiError, NIP, pegawai } from './fixtures'

const koreksi = vi.mocked(pegawaiService.koreksiNip)
const foto = vi.mocked(pegawaiService.foto)
const BARU = '199001012015011999'

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

async function isi(nilai: string): Promise<void> {
  const input = q<HTMLInputElement>('[data-testid="koreksi-nip-input"] input, input[data-testid="koreksi-nip-input"]')
  if (!input) throw new Error('isian NIP baru tidak ada')
  input.value = nilai
  input.dispatchEvent(new Event('input'))
  await flushPromises()
  q<HTMLElement>('[data-testid="koreksi-nip-lanjut"]')?.click()
  await flushPromises()
}

async function mountDialog() {
  const wrapper = mount(KoreksiNipDialog, { props: { open: true, nip: NIP, nama: 'Budi Contoh' }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  koreksi.mockReset()
  foto.mockReset()
})

describe('KoreksiNipDialog', () => {
  it.each([
    ['huruf', '19900101A'],
    ['lebih dari 18 digit', '1990010120150119999'],
    ['sama dengan NIP lama', NIP],
  ])('NIP baru %s → tetap di langkah isi dengan pesan, tanpa memanggil API', async (_, nilai) => {
    await mountDialog()
    await isi(nilai)
    expect(q('[data-testid="koreksi-nip-tinjau"]')).toBeNull()
    expect(q('[data-testid="koreksi-nip-dialog"]')?.textContent).toContain('berbeda dari NIP lama')
    expect(koreksi).not.toHaveBeenCalled()
  })

  it('tinjau memuat peringatan reset password dan permintaan segera ganti password', async () => {
    await mountDialog()
    await isi(BARU)
    const tinjau = q('[data-testid="koreksi-nip-tinjau"]')?.textContent ?? ''
    expect(tinjau).toContain(BARU)
    expect(tinjau).toContain('password akun direset ke NIP baru')
    expect(tinjau).toContain('segera mengganti password')
  })

  it('422 errors.nip_baru → kembali ke langkah isi dengan pesan server; tidak emit done', async () => {
    koreksi.mockRejectedValue(apiError(422, { nip_baru: ['NIP baru sudah terdaftar atas nama X; gunakan NIP lain.'] }))
    const wrapper = await mountDialog()
    await isi(BARU)
    q<HTMLElement>('[data-testid="koreksi-nip-konfirmasi"]')?.click()
    await vi.waitFor(() => expect(q('[data-testid="koreksi-nip-dialog"]')?.textContent).toContain('sudah terdaftar atas nama X'))
    expect(q('[data-testid="koreksi-nip-tinjau"]')).toBeNull()
    expect(wrapper.emitted('done')).toBeUndefined()
  })

  it('selama proses dialog tidak bisa ditutup; sukses → done + tutup', async () => {
    let selesai: (v: Awaited<ReturnType<typeof pegawaiService.koreksiNip>>) => void = () => {}
    koreksi.mockReturnValue(new Promise((resolve) => (selesai = resolve)))
    const wrapper = await mountDialog()
    await isi(BARU)
    q<HTMLElement>('[data-testid="koreksi-nip-konfirmasi"]')?.click()
    await flushPromises()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(wrapper.emitted('update:open')).toBeUndefined()

    selesai({ nip_lama: NIP, nip_baru: BARU, tabel: {}, dilewati: [], sesi_dicabut: 1 })
    await flushPromises()
    expect(wrapper.emitted('done')?.[0]?.[0]).toMatchObject({ nip_baru: BARU })
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
  })
})

describe('PegawaiHeaderCard — foto', () => {
  it('ada_foto → GET foto (blob) → object URL di avatar; dilepas → URL dicabut', async () => {
    const create = vi.fn(() => 'blob:foto-uji')
    const revoke = vi.fn()
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke })
    foto.mockResolvedValue(new Blob(['x'], { type: 'image/png' }))

    const wrapper = mount(PegawaiHeaderCard, { props: { detail: pegawai({ ada_foto: true }), canPrint: false, canDelete: false } })
    await flushPromises()

    expect(foto).toHaveBeenCalledWith(NIP)
    expect(wrapper.find('img[src="blob:foto-uji"]').exists()).toBe(true)
    wrapper.unmount()
    expect(revoke).toHaveBeenCalledWith('blob:foto-uji')
  })

  it('tanpa foto atau foto gagal dimuat → avatar inisial, tanpa object URL', async () => {
    const create = vi.fn(() => 'blob:x')
    Object.assign(URL, { createObjectURL: create, revokeObjectURL: vi.fn() })

    const tanpa = mount(PegawaiHeaderCard, { props: { detail: pegawai({ ada_foto: false }), canPrint: false, canDelete: false } })
    await flushPromises()
    expect(foto).not.toHaveBeenCalled()
    tanpa.unmount()

    foto.mockRejectedValue(apiError(404))
    const gagal = mount(PegawaiHeaderCard, { props: { detail: pegawai({ ada_foto: true }), canPrint: false, canDelete: false } })
    await flushPromises()
    expect(create).not.toHaveBeenCalled()
    expect(gagal.find('img').exists()).toBe(false)
  })
})
