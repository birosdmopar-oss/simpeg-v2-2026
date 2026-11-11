/**
 * CR-118 (T-03 QA Lapis 1 F7) — urutan antrean dialog tidak bergantung balapan network: keempat host di shell + beranda
 * (form digital, pengingat cuti, popup konten, SKL) mendaftar sinkron sebelum fetch, jadi walau respons selesai terbalik
 * (SKL → popup → pengingat → form) urutan tetap form → pengingat → popup → SKL. Host kosong/galat tidak memblokir.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/layouts/nav.config', async (asli) => ({ ...(await asli<typeof import('@/shared/layouts/nav.config')>()), ACTIVE_PHASE: 7 }))
vi.mock('../services/formDigital.service', () => ({ formDigitalService: { saya: vi.fn(), isi: vi.fn() } }))
vi.mock('@/features/survei-layanan/services/surveiLayanan.service', () => ({ surveiLayananService: { saya: vi.fn(), kirim: vi.fn() } }))
vi.mock('@/features/layanan/services/cuti.service', () => ({ cutiService: { pengingat: vi.fn(), tunda: vi.fn() } }))
vi.mock('@/features/konten/services/konten.service', () => ({
  kontenService: { popupAktif: vi.fn(), bannerAktif: vi.fn(), beritaTerbaru: vi.fn(), gambarBlob: vi.fn(async () => new Blob(['x'])) },
}))
vi.mock('@/features/auth/services/auth.service', () => ({
  authService: { me: vi.fn(), login: vi.fn(), logout: vi.fn().mockResolvedValue(undefined), changePassword: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import PopupAktifDialog from '@/features/konten/components/PopupAktifDialog.vue'
import { kontenService } from '@/features/konten/services/konten.service'
import type { PopupAktif } from '@/features/konten/types'
import { cutiService } from '@/features/layanan/services/cuti.service'
import type { CutiItem } from '@/features/layanan/types'
import { surveiLayananService } from '@/features/survei-layanan/services/surveiLayanan.service'
import type { TagihanSkl } from '@/features/survei-layanan/types'
import { resetAntreanDialog, useAntreanDialog, type NamaDialog } from '@/shared/composables/antreanDialog'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'

import { formDigitalService } from '../services/formDigital.service'
import type { FormSaya } from '../types'

const form: FormSaya = {
  id: 1,
  judul_form: 'Form 1',
  keterangan: null,
  tgl_mulai: '2026-11-01',
  tgl_akhir: '2026-11-30',
  form: { versi: 1, pertanyaan: [{ id: 'nama', tipe: 'teks', label: 'Nama', wajib: false }] },
}
const cutiRow = { id: 5, nama: 'Pegawai Contoh', nama_jenis_cuti: 'Cuti Tahunan', tgl_mulai: '2026-11-04', tgl_akhir: '2026-11-06', lama_cuti: 3, label_status: 'Menunggu Atasan', nada_status: 'warning', tahap: [], aksi: { tunda: true } } as unknown as CutiItem
const popup: PopupAktif = { id: '1', gambar_url: '/x.png', url: null, keterangan: null }
const tagihan: TagihanSkl = { jenis_rwy: 1, id_rwy: 4, jenis_layanan: 'Cuti', info: 'Info' }

function tunda<T>(): { p: Promise<T>; beres: (v: T) => void; gagal: (e: unknown) => void } {
  let beres!: (v: T) => void
  let gagal!: (e: unknown) => void
  const p = new Promise<T>((a, b) => {
    beres = a
    gagal = b
  })
  return { p, beres, gagal }
}

async function pasang(): Promise<void> {
  loginAs(Role.PEGAWAI)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti/:id', name: 'layanan-cuti-detail', component: { render: () => null } })
  await router.push('/')
  await router.isReady()
  mount(RedesignShell, { slots: { default: PopupAktifDialog }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
}

afterEach(() => {
  resetAntreanDialog()
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

describe('antrean dialog deterministik (CR-118)', () => {
  it('respons selesai terbalik tetap menghasilkan form → pengingat → popup → SKL', async () => {
    const f = tunda<FormSaya[]>()
    const c = tunda<{ items: CutiItem[]; total: number }>()
    const k = tunda<PopupAktif[]>()
    const s = tunda<TagihanSkl[]>()
    vi.mocked(formDigitalService.saya).mockReturnValue(f.p)
    vi.mocked(cutiService.pengingat).mockReturnValue(c.p)
    vi.mocked(kontenService.popupAktif).mockReturnValue(k.p)
    vi.mocked(surveiLayananService.saya).mockReturnValue(s.p)
    const { aktif } = useAntreanDialog()

    await pasang()
    s.beres([tagihan])
    await flushPromises()
    expect(aktif.value).toBeNull()
    k.beres([popup])
    await flushPromises()
    expect(aktif.value).toBeNull()
    c.beres({ items: [cutiRow], total: 1 })
    await flushPromises()
    expect(aktif.value).toBeNull()
    f.beres([form])
    await flushPromises()
    expect(aktif.value).toBe<NamaDialog>('form-digital')
    expect(document.body.querySelector('[data-testid="form-isian-dialog"]')).not.toBeNull()
  })

  it('host kosong atau galat tidak memblokir dialog berprioritas lebih rendah', async () => {
    const f = tunda<FormSaya[]>()
    const c = tunda<{ items: CutiItem[]; total: number }>()
    vi.mocked(formDigitalService.saya).mockReturnValue(f.p)
    vi.mocked(cutiService.pengingat).mockReturnValue(c.p)
    vi.mocked(kontenService.popupAktif).mockResolvedValue([popup])
    vi.mocked(surveiLayananService.saya).mockResolvedValue([])
    const { aktif } = useAntreanDialog()

    await pasang()
    expect(aktif.value).toBeNull()
    f.beres([])
    await flushPromises()
    expect(aktif.value).toBeNull()
    c.gagal(new Error('403'))
    await flushPromises()
    expect(aktif.value).toBe<NamaDialog>('popup-konten')
  })
})
