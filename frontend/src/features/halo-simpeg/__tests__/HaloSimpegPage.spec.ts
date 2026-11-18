/**
 * Layar pemilik Halo Simpeg (B2, MAKE-073): master-detail (AC-E08.10), ⋮ Lihat detail → Tutup → Unduh lampiran → Hapus (AGENTS.md §1),
 * ConfirmDialog teks legacy, filter baca bawaan `semua` (AC-E08.13), "Tambah Pertanyaan" hanya role 2/6/7, thread (label
 * apa adanya, teks tanpa v-html, lampiran lewat E-04), tiket kategori NULL (H8-16 (a1)), galat 422/429 per kolom
 * (AC-E08.3/15), dan tampilan < 1024 px satu panel. Layanan di-mock; bentuk data = fixture kontrak MAKE-071.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError, AxiosHeaders } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'

const desktop = vi.hoisted(() => ({ nilai: true }))
vi.mock('@/shared/composables/useIsDesktop', async () => {
  const { ref: r } = await import('vue')
  return { useIsDesktop: () => r(desktop.nilai), DESKTOP_QUERY: '(min-width: 1024px)' }
})
vi.mock('@/shared/layouts/RedesignShell.vue', () => ({
  default: defineComponent({ setup: (_p, { slots }) => () => h('div', { 'data-testid': 'shell' }, slots.default?.()) }),
}))
const svc = vi.hoisted(() => ({
  tiket: { list: vi.fn(), detail: vi.fn(), baca: vi.fn(), tutup: vi.fn(), hapus: vi.fn(), balas: vi.fn(), buat: vi.fn(), lampiran: vi.fn(), ringkasan: vi.fn(), ekspor: vi.fn() },
  kategori: { opsi: vi.fn() },
  kontak: { aktif: vi.fn() },
  petugas: { saya: vi.fn() },
}))
vi.mock('../services/tiket.service', async (asli) => ({ ...(await asli<typeof import('../services/tiket.service')>()), tiketHaloService: svc.tiket }))
vi.mock('../services/master.service', async (asli) => ({
  ...(await asli<typeof import('../services/master.service')>()),
  kategoriHaloService: svc.kategori,
  kontakHaloService: svc.kontak,
  petugasHaloService: svc.petugas,
}))
const unduhan = vi.hoisted(() => ({ simpan: vi.fn() }))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', async (asli) => ({
  ...(await asli<typeof import('@/features/kepegawaian/services/berkasUnduhan')>()),
  simpanBerkas: unduhan.simpan,
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { RUANG_LAUNCHER_CLASS } from '../constants'
import { muatRingkasan, resetHaloSimpeg, setRingkasanHalo } from '../stores/haloSimpeg'
import { bukaTiket, daftarAntrean, detailTiket } from '../stores/tiket'
import type { Halaman, TiketBaris, TiketDetail } from '../types'
import HaloSimpegPage from '../views/HaloSimpegPage.vue'
import galat422Ditutup from './fixtures/galat-422-ditutup.json'
import galat422Tiket from './fixtures/galat-422-tiket.json'
import galat429 from './fixtures/galat-429.json'
import kategoriOpsi from './fixtures/kategori-opsi.json'
import kontakAktif from './fixtures/kontak-aktif.json'
import daftarPemilik from './fixtures/tiket-daftar-pemilik.json'
import daftarPetugas from './fixtures/tiket-daftar-petugas.json'
import detailDitutup from './fixtures/tiket-detail-ditutup.json'
import detailPemilik from './fixtures/tiket-detail-pemilik.json'
import detailPetugas from './fixtures/tiket-detail-petugas.json'
import detailTanpaJabatan from './fixtures/tiket-detail-tanpa-jabatan.json'
import detailTanpaKategori from './fixtures/tiket-detail-tanpa-kategori.json'

const DAFTAR = daftarPemilik.data as Halaman<TiketBaris>
const DETAIL = detailPemilik.data as TiketDetail
const baris = (over: Partial<TiketBaris>): TiketBaris => ({ ...DAFTAR.items[0]!, ...over })

function apiError(status: number, body: { message: string; code?: string; errors?: Record<string, string[]> }) {
  const res = { status, statusText: 'x', headers: new AxiosHeaders(), config: { headers: new AxiosHeaders() }, data: { status: 'error', ...body } }
  return { status, message: body.message, errors: body.errors ?? null, isNetworkError: false, original: new AxiosError('x', 'ERR', undefined, null, res as never) }
}

const Stub = { render: () => null }
function buatRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: Stub },
      { path: '/halo-simpeg', name: 'halo-simpeg', component: Stub },
      { path: '/halo-simpeg/tiket/:id', name: 'halo-tiket', component: Stub },
    ],
  })
}

async function pasang(opsi: { role?: RoleCode; idTiket?: number | null; idTidakSah?: boolean; query?: string } = {}) {
  useAuthStore().setSession({ username: 'uji', user_level: opsi.role ?? Role.PEGAWAI, must_change_password: false } as User)
  const router = buatRouter()
  await router.push((opsi.idTiket ? `/halo-simpeg/tiket/${opsi.idTiket}` : '/halo-simpeg') + (opsi.query ?? ''))
  const props = ref({ idTiket: opsi.idTiket ?? null, idTidakSah: opsi.idTidakSah ?? false })
  const Host = defineComponent({ setup: () => () => h(HaloSimpegPage, props.value) })
  const w = mount(Host, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { w, router, props }
}

beforeEach(() => {
  setActivePinia(createPinia())
  resetHaloSimpeg()
  desktop.nilai = true
  for (const grup of Object.values(svc)) for (const f of Object.values(grup)) f.mockReset()
  unduhan.simpan.mockReset()
  svc.tiket.list.mockResolvedValue(DAFTAR)
  svc.tiket.detail.mockResolvedValue({ ...DETAIL, belum_dibaca: false })
  svc.kategori.opsi.mockResolvedValue(kategoriOpsi.data)
  svc.kontak.aktif.mockResolvedValue(kontakAktif.data)
  svc.petugas.saya.mockResolvedValue({ petugas: false, aktif: false, kategori: [], bisa_ekspor: false })
  svc.tiket.ringkasan.mockResolvedValue({ belum_dibaca_saya: 0, belum_dibaca_petugas: 0, perlu_tindak: 0, badge: 0, total: 0 })
})

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('daftar Tiket saya', () => {
  it('memuat peran=saya dengan bawaan baca "semua" (AC-E08.13); kategori NULL tampil "—"', async () => {
    svc.tiket.list.mockResolvedValueOnce({ ...DAFTAR, items: [baris({ id: 7, kategori: null })] })
    const { w } = await pasang()
    expect(svc.tiket.list).toHaveBeenCalledWith(expect.objectContaining({ peran: 'saya', baca: 'semua' }))
    expect(w.get('[data-testid="halo-tiket-row-7"] [data-kolom="kategori"]').text()).toBe('—')
  })

  it('⋮ Lihat detail → Tutup → Unduh lampiran → Hapus; hidden menurut status, lampiran, & flag BE; label + testid (P3)', async () => {
    const pt = DAFTAR.items[0]!.pesan_terakhir!
    const denganLampiran = { ...pt, lampiran: { nama: 'lampiran-3-9.pdf', tipe: 'application/pdf' } }
    svc.tiket.list.mockResolvedValueOnce({
      ...DAFTAR,
      items: [
        baris({ id: 1, subjek: 'Open', status: 'open', bisa_tutup: true, bisa_hapus: false, pesan_terakhir: { ...pt, lampiran: null } }),
        baris({ id: 2, subjek: 'Tutup', status: 'closed', bisa_tutup: false, bisa_hapus: false, pesan_terakhir: null }),
        baris({ id: 3, subjek: 'Boleh hapus', status: 'open', bisa_tutup: true, bisa_hapus: true, pesan_terakhir: denganLampiran }),
      ],
    })
    const { w } = await pasang()
    expect((await rowMenuActions(w, 'halo-tiket-actions-1')).map((a) => a.label)).toEqual(['Lihat detail', 'Tutup'])
    expect((await rowMenuActions(w, 'halo-tiket-actions-2')).map((a) => a.label)).toEqual(['Lihat detail'])
    const tiga = await rowMenuActions(w, 'halo-tiket-actions-3')
    expect(tiga.map((a) => [a.label, a.danger])).toEqual([
      ['Lihat detail', false],
      ['Tutup', false],
      ['Unduh lampiran', false],
      ['Hapus', true],
    ])
    expect(w.get('[data-testid="halo-tiket-actions-1"]').attributes('aria-label')).toBe('Aksi untuk Open')
    // Kolom Lampiran Terakhir hanya teks (tanpa tombol, AGENTS.md §1).
    expect(w.get('[data-testid="halo-tiket-lampiran-3"]').element.tagName).toBe('SPAN')
    expect(w.find('[data-testid="halo-tiket-row-3"] [data-kolom="lampiran"] button').exists()).toBe(false)
  })

  it('Lihat detail mengubah rute; klik baris tidak berbuat apa-apa; tanpa role="option" (AC-E08.10)', async () => {
    const { w, router } = await pasang()
    const id = DAFTAR.items[0]!.id
    await w.get(`[data-testid="halo-tiket-row-${id}"]`).trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('halo-simpeg')
    expect(document.querySelector('[role="option"]')).toBeNull()
    await selectRowAction(w, `halo-tiket-actions-${id}`, 'detail')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe(`/halo-simpeg/tiket/${id}`)
  })

  it('Tutup lewat ConfirmDialog teks legacy → tutup dipanggil', async () => {
    const t = baris({ id: 5, status: 'open', bisa_tutup: true })
    svc.tiket.list.mockResolvedValueOnce({ ...DAFTAR, items: [t] })
    svc.tiket.tutup.mockResolvedValueOnce({ ...t, status: 'closed', bisa_tutup: false })
    const { w } = await pasang()
    await selectRowAction(w, 'halo-tiket-actions-5', 'tutup')
    await flushPromises()
    expect(document.body.textContent).toContain('Apakah anda yakin akan menutup percakapan ini?')
    const ya = [...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Tutup') as HTMLButtonElement
    ya.click()
    await flushPromises()
    expect(svc.tiket.tutup).toHaveBeenCalledWith(5)
    expect(w.text()).toContain('Percakapan berhasil ditutup.')
  })

  it.each([Role.PEGAWAI, Role.PTT, Role.PPPK])('role %i: tombol Tambah Pertanyaan ada', async (role) => {
    const { w } = await pasang({ role })
    expect(w.find('[data-testid="halo-tiket-baru"]').exists()).toBe(true)
  })

  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI])('role %i: tombol Tambah Pertanyaan TIDAK ada di DOM', async (role) => {
    const { w } = await pasang({ role })
    expect(w.find('[data-testid="halo-tiket-baru"]').exists()).toBe(false)
  })

  it('galat daftar 500 → pesan amplop + Muat ulang; kosong → teks kosong', async () => {
    svc.tiket.list.mockRejectedValueOnce(apiError(500, { message: 'Terjadi kesalahan server.' }))
    const { w } = await pasang()
    expect(w.get('[data-testid="halo-daftar-galat"]').text()).toContain('Terjadi kesalahan server.')
    svc.tiket.list.mockResolvedValueOnce({ ...DAFTAR, items: [], total: 0 })
    await w.get('[data-testid="halo-daftar-galat"] button').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid="halo-daftar-kosong"]').text()).toBe('Belum ada percakapan.')
  })
})

describe('thread tiket', () => {
  it('pengirim_label apa adanya; isi berisi <script> tampil sebagai teks (tanpa v-html)', async () => {
    const pesan = [{ ...DETAIL.pesan[0]!, isi: '<script>alert(1)</script> halo', pengirim_label: 'Admin' }]
    svc.tiket.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: false, pesan })
    const { w } = await pasang({ idTiket: DETAIL.id })
    const isi = w.get('[data-testid="halo-pesan-isi"]')
    expect(isi.text()).toBe('<script>alert(1)</script> halo')
    expect(isi.find('script').exists()).toBe(false)
    expect(w.get('[data-testid="halo-pesan-pengirim"]').text()).toBe('Admin')
  })

  it('Closed dengan ditutup_oleh_label null → status Closed tanpa nama penutup', async () => {
    svc.tiket.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: false, status: 'closed', bisa_balas: false, ditutup_oleh_label: null })
    const { w } = await pasang({ idTiket: DETAIL.id })
    expect(w.find('[data-testid="halo-thread-ditutup"]').exists()).toBe(false)
    expect(w.get('[data-testid="halo-thread"]').text()).toContain('Closed')
  })

  it('lampiran lewat E-04 (blob) lalu simpanBerkas; bukan URL /berkas/', async () => {
    const p = DETAIL.pesan.find((x) => x.lampiran) ?? { ...DETAIL.pesan[0]!, lampiran: { nama: 'lampiran-1-1.pdf', tipe: 'application/pdf', ukuran: null } }
    svc.tiket.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: false, pesan: [p] })
    const blob = new Blob(['%PDF'])
    svc.tiket.lampiran.mockResolvedValueOnce({ blob, nama: 'lampiran-1-1.pdf' })
    const { w } = await pasang({ idTiket: DETAIL.id })
    expect(document.querySelector('a[href*="/berkas/"]')).toBeNull()
    await w.get(`[data-testid="halo-pesan-lampiran-${p.id_inbox_msg}"]`).trigger('click')
    await flushPromises()
    expect(svc.tiket.lampiran).toHaveBeenCalledWith(DETAIL.id, p.id_inbox_msg)
    expect(unduhan.simpan).toHaveBeenCalledWith({ blob, nama: 'lampiran-1-1.pdf' })
  })

  it('tiket kategori NULL: form balas diganti catatan (H8-16 (a1))', async () => {
    svc.tiket.detail.mockResolvedValueOnce({ ...(detailTanpaKategori.data as TiketDetail), belum_dibaca: false })
    const { w } = await pasang({ idTiket: detailTanpaKategori.data.id })
    expect(w.find('[data-testid="halo-balas-form"]').exists()).toBe(false)
    expect(w.get('[data-testid="halo-balas-tanpa-kategori"]').text()).toContain('tidak memiliki kategori')
    expect(w.get('[data-testid="halo-thread-kategori"]').text()).toBe('—')
  })

  it('balas kosong → galat klien; 422 tiket_ditutup → pesan BE + detail dimuat ulang', async () => {
    svc.tiket.detail.mockResolvedValue({ ...DETAIL, belum_dibaca: false, bisa_balas: true })
    const { w } = await pasang({ idTiket: DETAIL.id })
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    expect(w.text()).toContain('Pesan wajib diisi.')
    expect(svc.tiket.balas).not.toHaveBeenCalled()
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Masih ada?')
    svc.tiket.balas.mockRejectedValueOnce(apiError(422, galat422Ditutup))
    const sebelum = svc.tiket.detail.mock.calls.length
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    await flushPromises()
    expect(w.get('[data-testid="halo-thread"]').text()).toContain(galat422Ditutup.message)
    expect(svc.tiket.detail.mock.calls.length).toBe(sebelum + 1)
  })

  it('id tidak sah → "Data percakapan tidak ditemukan."; 404 thread → pesan BE', async () => {
    const a = await pasang({ idTidakSah: true })
    expect(a.w.get('[data-testid="halo-tiket-tidak-ditemukan"]').text()).toBe('Data percakapan tidak ditemukan.')
    a.w.unmount()
    svc.tiket.detail.mockRejectedValueOnce(apiError(404, { message: 'Data percakapan tidak ditemukan.' }))
    const b = await pasang({ idTiket: 99 })
    expect(b.w.get('[data-testid="halo-thread-galat"]').text()).toContain('Data percakapan tidak ditemukan.')
  })
})

describe('thread per tiket (review B2 P2)', () => {
  it('balasan A ditahan → pindah ke B → ketik draf → A tiba: draf B utuh, galat A tidak tampil', async () => {
    let tolakA: (e: unknown) => void = () => {}
    svc.tiket.balas.mockReturnValueOnce(new Promise((_r, j) => (tolakA = j)))
    svc.tiket.detail.mockImplementation(async (id: number) => ({ ...DETAIL, id, subjek: `Tiket ${id}`, belum_dibaca: false, bisa_balas: true }))
    const { w, props } = await pasang({ idTiket: 1 })
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Balasan A')
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    props.value = { idTiket: 2, idTidakSah: false }
    await flushPromises()
    expect(w.get('[data-testid="halo-thread-judul"]').text()).toBe('Tiket 2')
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Draf B')
    tolakA(apiError(422, { message: 'Pesan wajib diisi.', errors: { msg: ['Pesan wajib diisi.'] } }))
    await flushPromises()
    expect((w.get('[data-testid="halo-balas-form"] textarea').element as HTMLTextAreaElement).value).toBe('Draf B')
    expect(w.find('[data-testid="halo-balas-galat"]').exists()).toBe(false)
    expect(w.get('[data-testid="halo-thread"]').text()).not.toContain('Pesan wajib diisi.')
  })
})

describe('Q1: instance ThreadTiket yang sudah dilepas tidak memuat ulang tiket lama', () => {
  it('balas A tertahan → pindah ke B → 422 tiket_ditutup (code) untuk A: panel tetap B, idDibuka tetap B', async () => {
    let tolakA: (e: unknown) => void = () => {}
    svc.tiket.balas.mockReturnValueOnce(new Promise((_r, j) => (tolakA = j)))
    svc.tiket.detail.mockImplementation(async (id: number) => ({ ...DETAIL, id, subjek: `Tiket ${id}`, belum_dibaca: false, bisa_balas: true }))
    const { w, props } = await pasang({ idTiket: 1 })
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Balasan A')
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    props.value = { idTiket: 2, idTidakSah: false }
    await flushPromises()
    const panggilanDetail = svc.tiket.detail.mock.calls.length
    tolakA(apiError(422, galat422Ditutup))
    await flushPromises()
    expect(galat422Ditutup.code).toBe('tiket_ditutup')
    expect(svc.tiket.detail.mock.calls.length).toBe(panggilanDetail)
    expect(detailTiket.idDibuka).toBe(2)
    expect(w.get('[data-testid="halo-thread-judul"]').text()).toBe('Tiket 2')
  })
})

describe('form tiket baru', () => {
  async function bukaDialog() {
    const ctx = await pasang({ role: Role.PEGAWAI })
    await ctx.w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    const form = () => document.querySelector('[data-testid="halo-tiket-baru-form"]') as HTMLFormElement
    return { ...ctx, form }
  }

  it('batas subjek 250: 251 karakter ditolak klien tanpa request', async () => {
    const { form } = await bukaDialog()
    const subjek = form().querySelector('input[name="subjek"]') as HTMLInputElement
    subjek.value = 'a'.repeat(251)
    subjek.dispatchEvent(new Event('input'))
    form().dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(document.body.textContent).toContain('Subjek maksimal 250 karakter.')
    expect(document.body.textContent).toContain('Kategori wajib dipilih.')
    expect(svc.tiket.buat).not.toHaveBeenCalled()
  })

  it('422 BE per kolom (termasuk arsip) dan 429 throttle ditampilkan dari amplop', async () => {
    const { form } = await bukaDialog()
    svc.tiket.buat.mockRejectedValueOnce(apiError(422, galat422Tiket)).mockRejectedValueOnce(apiError(429, galat429))
    const isi = (sel: string, v: string) => {
      const el = form().querySelector(sel) as HTMLInputElement | HTMLTextAreaElement
      el.value = v
      el.dispatchEvent(new Event('input'))
    }
    isi('input[name="subjek"]', 'Cuti')
    isi('textarea', 'Berapa sisa cuti?')
    // Kategori lewat komponen pilihan custom: set nilai via opsi pertama.
    const trigger = form().querySelector('[role="combobox"]') as HTMLElement
    trigger.click()
    await flushPromises()
    ;(document.querySelector('[role="option"]') as HTMLElement | null)?.click()
    await flushPromises()
    form().dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(document.querySelector('[data-testid="halo-tiket-baru-galat-arsip"]')?.textContent).toBe(galat422Tiket.errors.arsip[0])
    expect(document.body.textContent).toContain('Subjek wajib diisi.')
    form().dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(document.querySelector('[data-testid="halo-tiket-baru-galat"]')?.textContent).toBe(galat429.message)
  })
})

describe('form tiket baru — 422 kategori (review B2 P5 (b))', () => {
  it('errors.id_question_category → galat di kolom Kategori, pilihan dikosongkan, opsi dimuat ulang', async () => {
    const { w } = await pasang({ role: Role.PEGAWAI })
    await w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="halo-tiket-baru-form"]') as HTMLFormElement
    const isi = (sel: string, v: string) => {
      const el = form.querySelector(sel) as HTMLInputElement | HTMLTextAreaElement
      el.value = v
      el.dispatchEvent(new Event('input'))
    }
    isi('input[name="subjek"]', 'Cuti')
    isi('textarea', 'Berapa sisa cuti?')
    ;(form.querySelector('[role="combobox"]') as HTMLElement).click()
    await flushPromises()
    ;(document.querySelector('[role="option"]') as HTMLElement).click()
    await flushPromises()
    const opsiSebelum = svc.kategori.opsi.mock.calls.length
    svc.tiket.buat.mockRejectedValueOnce(
      apiError(422, { message: 'Kategori tidak tersedia.', errors: { id_question_category: ['Kategori tidak tersedia.'] } }),
    )
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.kategori.opsi.mock.calls.length).toBe(opsiSebelum + 1)
    expect(document.body.textContent).toContain('Kategori tidak tersedia.')
    expect(document.querySelector('[data-testid="halo-tiket-baru-galat"]')).toBeNull()
    expect((form.querySelector('[role="combobox"]') as HTMLElement).textContent).toContain('Pilih kategori')
  })
})

describe('responsif < 1024 px', () => {
  it('rute tiket → hanya panel thread + "Kembali ke daftar"; rute daftar → hanya panel daftar', async () => {
    desktop.nilai = false
    const a = await pasang({ idTiket: DETAIL.id })
    expect(a.w.find('[data-testid="halo-panel-daftar"]').exists()).toBe(false)
    expect(a.w.find('[data-testid="halo-thread-kembali"]').exists()).toBe(true)
    await a.w.get('[data-testid="halo-thread-kembali"]').trigger('click')
    await flushPromises()
    expect(a.router.currentRoute.value.name).toBe('halo-simpeg')
    a.w.unmount()
    const b = await pasang()
    expect(b.w.find('[data-testid="halo-panel-daftar"]').exists()).toBe(true)
    expect(b.w.find('[data-testid="halo-panel-thread"]').exists()).toBe(false)
  })

  it('P4: sesudah "Kembali ke daftar" fokus ke ⋮ baris tiket tadi (bukan body); id tidak sah → UiButton kembali + fokus judul', async () => {
    desktop.nilai = false
    const id = DAFTAR.items[0]!.id
    const { w, props } = await pasang({ idTiket: id })
    props.value = { idTiket: null, idTidakSah: false }
    await flushPromises()
    await flushPromises()
    expect(document.activeElement).toBe(w.get(`[data-testid="halo-tiket-actions-${id}"]`).element)
    w.unmount()

    const b = await pasang({ idTidakSah: true })
    const tombol = b.w.get('[data-testid="halo-tidak-sah-kembali"]')
    expect(tombol.element.tagName).toBe('BUTTON')
    expect(tombol.text()).toContain('Kembali ke daftar')
    svc.tiket.list.mockResolvedValueOnce({ ...DAFTAR, items: [] , total: 0 })
    b.props.value = { idTiket: null, idTidakSah: false }
    await flushPromises()
    await flushPromises()
    expect(document.activeElement?.tagName).toMatch(/^H[23]$/)
  })

  it('Q2: GET daftar lambat → Kembali → daftar dilepas sesudahnya → fokus tetap ke ⋮ baris tadi', async () => {
    desktop.nilai = false
    const id = DAFTAR.items[0]!.id
    let lepas: (v: Halaman<TiketBaris>) => void = () => {}
    svc.tiket.list.mockReturnValueOnce(new Promise((r) => (lepas = r)))
    const { w, props } = await pasang({ idTiket: id })
    // Rute kembali ke daftar (props dari rute halo-simpeg).
    props.value = { idTiket: null, idTidakSah: false }
    await flushPromises()
    // Daftar belum datang selama 1 detik (jauh lebih lama dari 20 tick).
    await new Promise((r) => setTimeout(r, 1000))
    lepas(DAFTAR)
    await flushPromises()
    await flushPromises()
    expect(document.activeElement).toBe(w.get(`[data-testid="halo-tiket-actions-${id}"]`).element)
  })

  it('desktop → dua panel bersamaan', async () => {
    const { w } = await pasang({ idTiket: DETAIL.id })
    expect(w.find('[data-testid="halo-panel-daftar"]').exists()).toBe(true)
    expect(w.find('[data-testid="halo-panel-thread"]').exists()).toBe(true)
  })
})

describe('perilaku inti (review B2 P6)', () => {
  const judul = () => document.querySelector('[data-testid="halo-thread-judul"]')
  const detailId = (id: number, over: Partial<TiketDetail> = {}): TiketDetail => ({
    ...DETAIL,
    id,
    subjek: `Tiket ${id}`,
    belum_dibaca: false,
    bisa_balas: true,
    ...over,
  })

  async function isiDanKirimTiketBaru(): Promise<void> {
    const form = document.querySelector('[data-testid="halo-tiket-baru-form"]') as HTMLFormElement
    const subjek = form.querySelector('input[name="subjek"]') as HTMLInputElement
    subjek.value = 'Baru'
    subjek.dispatchEvent(new Event('input'))
    const pesan = form.querySelector('textarea') as HTMLTextAreaElement
    pesan.value = 'Isi'
    pesan.dispatchEvent(new Event('input'))
    ;(form.querySelector('[role="combobox"]') as HTMLElement).click()
    await flushPromises()
    ;(document.querySelector('[role="option"]') as HTMLElement).click()
    await flushPromises()
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
  }

  it('1. fokus ke judul thread: buka langsung, pindah A→B, dan sesudah tiket baru dibuat', async () => {
    svc.tiket.detail.mockImplementation(async (id: number) => detailId(id))
    const { w, props } = await pasang({ idTiket: 1 })
    expect(document.activeElement).toBe(judul())
    props.value = { idTiket: 2, idTidakSah: false }
    await flushPromises()
    expect(judul()?.textContent?.trim()).toBe('Tiket 2')
    expect(document.activeElement).toBe(judul())
    svc.tiket.buat.mockResolvedValueOnce({ tiket: detailId(9), pesan: null })
    await w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    await isiDanKirimTiketBaru()
    props.value = { idTiket: 9, idTidakSah: false }
    await flushPromises()
    expect(judul()?.textContent?.trim()).toBe('Tiket 9')
    expect(document.activeElement).toBe(judul())
  })

  it('2. POST baca di tingkat halaman: belum_dibaca → baca 1× dengan id benar; pindah ke tiket sudah dibaca → tetap 1×', async () => {
    svc.tiket.detail.mockImplementation(async (id: number) => detailId(id, { belum_dibaca: id === 3 }))
    svc.tiket.baca.mockResolvedValue({ belum_dibaca_saya: 0, belum_dibaca_petugas: 0, perlu_tindak: 0, badge: 0, total: 0 })
    const { props } = await pasang({ idTiket: 3 })
    expect(svc.tiket.baca).toHaveBeenCalledTimes(1)
    expect(svc.tiket.baca).toHaveBeenCalledWith(3)
    props.value = { idTiket: 4, idTidakSah: false }
    await flushPromises()
    expect(svc.tiket.baca).toHaveBeenCalledTimes(1)
  })

  it('4. tutup: baris jadi Closed, bisa_tutup false, ⋮ hanya item yang masih berlaku', async () => {
    const t = baris({ id: 5, status: 'open', bisa_tutup: true, bisa_hapus: false, belum_dibaca: false, pesan_terakhir: null })
    svc.tiket.list.mockResolvedValueOnce({ ...DAFTAR, items: [t] })
    svc.tiket.tutup.mockResolvedValueOnce({ ...t, status: 'closed', bisa_tutup: false })
    const { w } = await pasang()
    await selectRowAction(w, 'halo-tiket-actions-5', 'tutup')
    const ya = [...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Tutup') as HTMLButtonElement
    ya.click()
    await flushPromises()
    expect(w.get('[data-testid="halo-tiket-row-5"] [data-status]').attributes('data-status')).toBe('closed')
    expect((await rowMenuActions(w, 'halo-tiket-actions-5')).map((a) => a.label)).toEqual(['Lihat detail'])
  })

  it('5. 422 tiket_ditutup → detail dimuat ulang (fixture ditutup): form balas hilang, catatan + pesan BE tampil', async () => {
    const ditutup = detailDitutup.data as TiketDetail
    svc.tiket.detail.mockResolvedValueOnce({ ...ditutup, status: 'open', bisa_balas: true, belum_dibaca: false }).mockResolvedValueOnce(ditutup)
    const { w } = await pasang({ idTiket: ditutup.id })
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Masih?')
    svc.tiket.balas.mockRejectedValueOnce(apiError(422, galat422Ditutup))
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    await flushPromises()
    expect(w.find('[data-testid="halo-balas-form"]').exists()).toBe(false)
    const catatan = w.get('[data-testid="halo-balas-tertutup"]').text()
    expect(catatan).toContain('telah ditutup')
    expect(catatan).toContain(galat422Ditutup.message)
  })

  it('6. pindah A→B (halaman tetap terpasang): draf kosong, judul B, detail(2); idTiket null → panel petunjuk', async () => {
    svc.tiket.detail.mockImplementation(async (id: number) => detailId(id))
    const { w, props } = await pasang({ idTiket: 1 })
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Draf A')
    props.value = { idTiket: 2, idTidakSah: false }
    await flushPromises()
    expect(svc.tiket.detail).toHaveBeenLastCalledWith(2)
    expect(judul()?.textContent?.trim()).toBe('Tiket 2')
    expect((w.get('[data-testid="halo-balas-form"] textarea').element as HTMLTextAreaElement).value).toBe('')
    props.value = { idTiket: null, idTidakSah: false }
    await flushPromises()
    expect(w.find('[data-testid="halo-thread-kosong"]').exists()).toBe(true)
  })

  it('7. 422 msg → kolom Pesan pada form balas (tanpa notice ganda)', async () => {
    svc.tiket.detail.mockResolvedValue(detailId(1))
    const { w } = await pasang({ idTiket: 1 })
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('x')
    const galat = { message: 'Pesan maksimal 5.000 karakter.', errors: { msg: ['Pesan maksimal 5.000 karakter.'] } }
    svc.tiket.balas.mockRejectedValueOnce(apiError(422, galat))
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    await flushPromises()
    expect(w.get('[data-testid="halo-balas-form"]').text()).toContain('Pesan maksimal 5.000 karakter.')
    expect(w.find('[data-testid="halo-balas-galat"]').exists()).toBe(false)
  })

  it('7b. 422 msg → kolom Pesan pada form tiket baru', async () => {
    const { w } = await pasang({ role: Role.PEGAWAI })
    await w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    svc.tiket.buat.mockRejectedValueOnce(apiError(422, { message: 'Pesan wajib diisi.', errors: { msg: ['Pesan wajib diisi.'] } }))
    await isiDanKirimTiketBaru()
    const form = document.querySelector('[data-testid="halo-tiket-baru-form"]') as HTMLFormElement
    const kolomPesan = (form.querySelector('textarea') as HTMLElement).closest('label, div')!.parentElement!
    expect(kolomPesan.textContent).toContain('Pesan wajib diisi.')
    expect(document.querySelector('[data-testid="halo-tiket-baru-galat"]')).toBeNull()
  })

  it('8. sukses Tambah Pertanyaan: rute ke tiket baru, dialog tertutup, daftar dimuat ulang', async () => {
    const { w, router } = await pasang({ role: Role.PEGAWAI })
    const muatSebelum = svc.tiket.list.mock.calls.length
    svc.tiket.buat.mockResolvedValueOnce({ tiket: detailId(42), pesan: null })
    await w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    await isiDanKirimTiketBaru()
    expect(router.currentRoute.value.fullPath).toBe('/halo-simpeg/tiket/42')
    expect(document.querySelector('[data-testid="halo-tiket-baru-dialog"]')).toBeNull()
    expect(svc.tiket.list.mock.calls.length).toBe(muatSebelum + 1)
  })

  it('9. ditutup_oleh_label terisi → nama penutup tampil', async () => {
    const ditutup = detailDitutup.data as TiketDetail
    svc.tiket.detail.mockResolvedValueOnce(ditutup)
    const { w } = await pasang({ idTiket: ditutup.id })
    expect(w.get('[data-testid="halo-thread-ditutup"]').text()).toContain(ditutup.ditutup_oleh_label as string)
  })

  it('10. galat unduh lampiran thread tampil; unduh lampiran terakhir lewat ⋮ memanggil service (t.id, id_inbox_msg)', async () => {
    const p = { ...DETAIL.pesan[0]!, lampiran: { nama: 'lampiran-1-1.pdf', tipe: 'application/pdf', ukuran: null } }
    svc.tiket.detail.mockResolvedValueOnce(detailId(1, { pesan: [p] }))
    svc.tiket.lampiran.mockRejectedValueOnce(apiError(404, { message: 'Lampiran tidak ditemukan.' }))
    const pt = { ...DAFTAR.items[0]!.pesan_terakhir!, id_inbox_msg: 31, lampiran: { nama: 'lampiran-8-31.pdf', tipe: 'application/pdf' } }
    svc.tiket.list.mockResolvedValueOnce({ ...DAFTAR, items: [baris({ id: 8, pesan_terakhir: pt })] })
    const { w } = await pasang({ idTiket: 1 })
    await w.get(`[data-testid="halo-pesan-lampiran-${p.id_inbox_msg}"]`).trigger('click')
    await flushPromises()
    expect(w.get('[data-testid="halo-thread-galat-unduh"]').text()).toBe('Lampiran tidak ditemukan.')
    const blob = new Blob(['%PDF'])
    svc.tiket.lampiran.mockResolvedValueOnce({ blob, nama: 'lampiran-8-31.pdf' })
    await selectRowAction(w, 'halo-tiket-actions-8', 'unduh')
    await flushPromises()
    expect(svc.tiket.lampiran).toHaveBeenLastCalledWith(8, 31)
    expect(unduhan.simpan).toHaveBeenCalledWith({ blob, nama: 'lampiran-8-31.pdf' })
  })
})

describe('tab Antrean petugas (B3)', () => {
  const PETUGAS_AKTIF = { petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 as const }], bisa_ekspor: true }

  it('bukan petugas → tanpa tab; ?tab=antrean diabaikan (tab saya)', async () => {
    const { w } = await pasang({ query: '?tab=antrean' })
    expect(w.find('[data-testid="halo-tabs"]').exists()).toBe(false)
    expect(w.find('[data-testid="halo-daftar-saya"]').exists()).toBe(true)
    expect(svc.tiket.list).toHaveBeenCalledWith(expect.objectContaining({ peran: 'saya' }))
  })

  it('petugas aktif + ?tab=antrean: tabel antrean peran=petugas baca belum, kolom Pemilik, panel Perlu ditindak kosong tetap tampil', async () => {
    svc.petugas.saya.mockResolvedValue(PETUGAS_AKTIF)
    svc.tiket.list.mockImplementation(async (f: { perlu_tindak?: boolean }) => (f.perlu_tindak ? { ...DAFTAR, items: [], total: 0 } : DAFTAR))
    const { w } = await pasang({ role: Role.SUPER_ADMIN, query: '?tab=antrean' })
    expect(w.findAll('[data-testid="halo-tabs"] [role="tab"]').map((t) => t.text())).toEqual(['Tiket Saya', 'Antrean'])
    expect(svc.tiket.list).toHaveBeenCalledWith(expect.objectContaining({ peran: 'petugas', baca: 'belum' }))
    expect(svc.tiket.list).toHaveBeenCalledWith(expect.objectContaining({ peran: 'petugas', perlu_tindak: true, per_page: 5 }))
    expect(w.get('[data-testid="halo-perlu-tindak-kosong"]').text()).toBe('Tidak ada tiket yang perlu ditindak.')
    const id = DAFTAR.items[0]!.id
    expect(w.get(`[data-testid="halo-tiket-row-${id}"] [data-kolom="pemilik"]`).text()).toBe(DAFTAR.items[0]!.pemilik_nama)
    // Petugas 1 kategori → tanpa filter kategori [K VI:31-36].
    expect(w.find('[data-testid="halo-daftar-antrean"] [aria-label="Filter kategori"]').exists()).toBe(false)
  })

  it('petugas ≥ 2 kategori → filter kategori tampil di antrean', async () => {
    svc.petugas.saya.mockResolvedValue({ ...PETUGAS_AKTIF, kategori: [...PETUGAS_AKTIF.kategori, { id: 2, nama: 'Gaji', status: 1 as const }] })
    const { w } = await pasang({ query: '?tab=antrean' })
    expect(w.find('[data-testid="halo-daftar-antrean"] [aria-label="Filter kategori"]').exists()).toBe(true)
  })

  it('panel berisi → Lihat detail menuju tiket dengan ?tab=antrean; ⋮ antrean juga mempertahankan tab', async () => {
    svc.petugas.saya.mockResolvedValue(PETUGAS_AKTIF)
    const t = DAFTAR.items[0]!
    svc.tiket.list.mockResolvedValue({ ...DAFTAR, items: [t], total: 1 })
    const { w, router } = await pasang({ query: '?tab=antrean' })
    await w.get(`[data-testid="halo-perlu-tindak-${t.id}"] button`).trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe(`/halo-simpeg/tiket/${t.id}?tab=antrean`)
    await router.push('/halo-simpeg?tab=antrean')
    await selectRowAction(w, `halo-tiket-actions-${t.id}`, 'detail')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe(`/halo-simpeg/tiket/${t.id}?tab=antrean`)
  })
})

describe('thread sisi petugas (B3)', () => {
  it('R6: snapshot lewat tombol "Data pelapor" + aria-describedby + tooltip (role tooltip); tanpa title; pemilik tanpa snapshot → tanpa tombol', async () => {
    const sn = detailPetugas.data.snapshot
    svc.tiket.detail.mockResolvedValueOnce({ ...(detailPetugas.data as TiketDetail), belum_dibaca: false })
    const a = await pasang({ idTiket: detailPetugas.data.id })
    const tombol = a.w.get('[data-testid="halo-thread-snapshot"]')
    expect(tombol.attributes('aria-label')).toBe('Data pelapor')
    expect(tombol.attributes('title')).toBeUndefined()
    const isi = document.getElementById(tombol.attributes('aria-describedby')!)?.textContent
    expect(isi).toBe(`NIP: ${sn.nip} · Jabatan: ${sn.jabatan} · Satker: ${sn.satker}`)
    await tombol.trigger('focus')
    await flushPromises()
    await vi.waitFor(() => expect(document.querySelector('[role="tooltip"]')?.textContent).toContain(`NIP: ${sn.nip}`))
    expect(document.querySelector('[data-testid="halo-thread"] [title]')).toBeNull()
    a.w.unmount()
    svc.tiket.detail.mockResolvedValueOnce({ ...DETAIL, belum_dibaca: false, snapshot: null, pengingat: null })
    const b = await pasang({ idTiket: DETAIL.id })
    expect(b.w.find('[data-testid="halo-thread-snapshot"]').exists()).toBe(false)
  })

  it('R6: snapshot tanpa jabatan/satker → "—"; layar sempit → teks kecil (tanpa tombol tooltip)', async () => {
    svc.tiket.detail.mockResolvedValue({ ...(detailTanpaJabatan.data as TiketDetail), belum_dibaca: false })
    const a = await pasang({ idTiket: detailTanpaJabatan.data.id })
    const tombol = a.w.get('[data-testid="halo-thread-snapshot"]')
    expect(document.getElementById(tombol.attributes('aria-describedby')!)?.textContent).toContain('Jabatan: — · Satker: —')
    a.w.unmount()
    desktop.nilai = false
    const b = await pasang({ idTiket: detailTanpaJabatan.data.id })
    expect(b.w.find('[data-testid="halo-thread-snapshot"]').exists()).toBe(false)
    expect(b.w.get('[data-testid="halo-thread-snapshot-teks"]').text()).toContain('Jabatan: — · Satker: —')
  })

  it('centang "Ingatkan Saya Kembali?" hanya bila pengingat !== null; dicentang → ingatkan_24_jam true', async () => {
    svc.tiket.detail.mockResolvedValue({ ...(detailPetugas.data as TiketDetail), belum_dibaca: false, pengingat: { aktif: true, jatuh_tempo: '2026-11-19T03:00:00Z' } })
    svc.tiket.balas.mockResolvedValueOnce({ pesan: detailPetugas.data.pesan[1], tiket: { ...DAFTAR.items[0]!, id: detailPetugas.data.id } })
    const { w } = await pasang({ idTiket: detailPetugas.data.id })
    expect(w.get('[data-testid="halo-balas-ingatkan"]').text()).toContain('Ingatkan Saya Kembali?')
    expect(w.find('[data-testid="halo-pengingat-jatuh-tempo"]').exists()).toBe(true)
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Kami cek.')
    await w.get('[data-testid="halo-balas-ingatkan"] input[type="checkbox"]').setValue(true)
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    await flushPromises()
    expect(svc.tiket.balas).toHaveBeenCalledWith(detailPetugas.data.id, expect.objectContaining({ pesan: 'Kami cek.', ingatkan_24_jam: true }))
  })

  it('pemilik (pengingat null) → tanpa centang ingatkan; balasan selalu ingatkan_24_jam false', async () => {
    svc.tiket.detail.mockResolvedValue({ ...DETAIL, belum_dibaca: false, bisa_balas: true, pengingat: null })
    svc.tiket.balas.mockResolvedValueOnce({ pesan: DETAIL.pesan[0], tiket: { ...DAFTAR.items[0]!, id: DETAIL.id } })
    const { w } = await pasang({ idTiket: DETAIL.id })
    expect(w.find('[data-testid="halo-balas-ingatkan"]').exists()).toBe(false)
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Terima kasih')
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    await flushPromises()
    expect(svc.tiket.balas).toHaveBeenCalledWith(DETAIL.id, expect.objectContaining({ ingatkan_24_jam: false }))
  })
})

describe('Q3: fokus sesudah tiket baru dibuat (alur nyata lewat router)', () => {
  it('Kirim → router.push ke tiket baru → timer FocusScope dijalankan → fokus di judul tiket baru', async () => {
    useAuthStore().setSession({ username: 'uji', user_level: Role.PEGAWAI, must_change_password: false } as User)
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'home', component: Stub },
        { path: '/halo-simpeg', name: 'halo-simpeg', component: HaloSimpegPage, props: { idTiket: null, idTidakSah: false } },
        {
          path: '/halo-simpeg/tiket/:id',
          name: 'halo-tiket',
          component: HaloSimpegPage,
          props: (r) => ({ idTiket: Number(r.params.id), idTidakSah: false }),
        },
      ],
    })
    await router.push('/halo-simpeg')
    svc.tiket.buat.mockResolvedValueOnce({ tiket: { ...DETAIL, id: 9, subjek: 'Tiket 9', belum_dibaca: false }, pesan: null })
    svc.tiket.detail.mockResolvedValue({ ...DETAIL, id: 9, subjek: 'Tiket 9', belum_dibaca: false })
    const w = mount({ render: () => h(RouterView) }, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    await w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="halo-tiket-baru-form"]') as HTMLFormElement
    const subjek = form.querySelector('input[name="subjek"]') as HTMLInputElement
    subjek.value = 'Baru'
    subjek.dispatchEvent(new Event('input'))
    const pesan = form.querySelector('textarea') as HTMLTextAreaElement
    pesan.value = 'Isi'
    pesan.dispatchEvent(new Event('input'))
    ;(form.querySelector('[role="combobox"]') as HTMLElement).click()
    await flushPromises()
    ;(document.querySelector('[role="option"]') as HTMLElement).click()
    await flushPromises()
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    for (let i = 0; i < 5; i += 1) {
      await new Promise((r) => setTimeout(r, 0))
      await flushPromises()
    }
    expect(router.currentRoute.value.fullPath).toBe('/halo-simpeg/tiket/9')
    expect(document.querySelector('[data-testid="halo-thread-judul"]')?.textContent?.trim()).toBe('Tiket 9')
    expect(document.activeElement).toBe(document.querySelector('[data-testid="halo-thread-judul"]'))
  })
})

describe('Q4: Unduh lampiran lewat ⋮ saat unduhan lain berjalan', () => {
  it('unduhan baris A berjalan → item Unduh lampiran di baris B disabled; galat unduh lewat ⋮ tampil', async () => {
    const pt = DAFTAR.items[0]!.pesan_terakhir!
    const lamp = (id: number) => ({ ...pt, id_inbox_msg: id * 10, lampiran: { nama: `lampiran-${id}.pdf`, tipe: 'application/pdf' } })
    svc.tiket.list.mockResolvedValueOnce({ ...DAFTAR, items: [baris({ id: 1, pesan_terakhir: lamp(1) }), baris({ id: 2, pesan_terakhir: lamp(2) })] })
    let tolak: (e: unknown) => void = () => {}
    svc.tiket.lampiran.mockReturnValueOnce(new Promise((_r, j) => (tolak = j)))
    const { w } = await pasang()
    await selectRowAction(w, 'halo-tiket-actions-1', 'unduh')
    expect((await rowMenuActions(w, 'halo-tiket-actions-2')).find((a) => a.label === 'Unduh lampiran')?.disabled).toBe(true)
    tolak(apiError(404, { message: 'Lampiran tidak ditemukan.' }))
    await flushPromises()
    expect(w.get('[data-testid="halo-daftar-galat-unduh"]').text()).toBe('Lampiran tidak ditemukan.')
    expect((await rowMenuActions(w, 'halo-tiket-actions-2')).find((a) => a.label === 'Unduh lampiran')?.disabled).toBe(false)
  })
})

describe('Q6 (4, 5): galat ⋮ Tutup dan galat per kolom yang tepat', () => {
  /** Elemen pesan galat yang ditautkan `aria-describedby` milik kontrol. */
  const galatKontrol = (el: Element | null): string => {
    const ids = (el?.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean)
    return ids.map((i) => document.getElementById(i)?.textContent?.trim() ?? '').join(' ')
  }

  it.each([
    ['422 tiket_sudah_ditutup', () => apiError(422, { message: 'Percakapan ini telah ditutup sebelumnya.', code: 'tiket_sudah_ditutup' })],
    ['404', () => apiError(404, { message: 'Data percakapan tidak ditemukan.' })],
  ])('(4) %s dari ⋮ Tutup → daftar dimuat ulang + detail dimuat ulang bila tiket itu terbuka', async (_n, galat) => {
    const t = baris({ id: 5, status: 'open', bisa_tutup: true, belum_dibaca: false, pesan_terakhir: null })
    svc.tiket.list.mockResolvedValue({ ...DAFTAR, items: [t] })
    svc.tiket.detail.mockResolvedValue({ ...DETAIL, id: 5, belum_dibaca: false })
    svc.tiket.tutup.mockRejectedValueOnce(galat())
    const { w } = await pasang({ idTiket: 5 })
    const list0 = svc.tiket.list.mock.calls.length
    const detail0 = svc.tiket.detail.mock.calls.length
    await selectRowAction(w, 'halo-tiket-actions-5', 'tutup')
    ;([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Tutup') as HTMLButtonElement).click()
    await flushPromises()
    expect(svc.tiket.list.mock.calls.length).toBe(list0 + 1)
    expect(svc.tiket.detail.mock.calls.length).toBe(detail0 + 1)
    expect(svc.tiket.detail).toHaveBeenLastCalledWith(5)
  })

  it('(5) tiket baru: 422 msg di kolom Pesan dan id_question_category di kolom Kategori (aria-describedby kontrol)', async () => {
    const { w } = await pasang({ role: Role.PEGAWAI })
    await w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="halo-tiket-baru-form"]') as HTMLFormElement
    const subjek = form.querySelector('input[name="subjek"]') as HTMLInputElement
    subjek.value = 'Baru'
    subjek.dispatchEvent(new Event('input'))
    const pesan = form.querySelector('textarea') as HTMLTextAreaElement
    pesan.value = 'Isi'
    pesan.dispatchEvent(new Event('input'))
    ;(form.querySelector('[role="combobox"]') as HTMLElement).click()
    await flushPromises()
    ;(document.querySelector('[role="option"]') as HTMLElement).click()
    await flushPromises()
    svc.tiket.buat.mockRejectedValueOnce(
      apiError(422, {
        message: 'Pesan wajib diisi.',
        errors: { msg: ['Pesan wajib diisi.'], id_question_category: ['Kategori tidak tersedia.'] },
      }),
    )
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(galatKontrol(form.querySelector('textarea'))).toBe('Pesan wajib diisi.')
    expect(galatKontrol(form.querySelector('[role="combobox"]'))).toContain('Kategori tidak tersedia.')
    expect(document.querySelector('[data-testid="halo-tiket-baru-galat"]')).toBeNull()
  })

  it('(5) balas: 422 msg di kolom Balasan (aria-describedby textarea)', async () => {
    svc.tiket.detail.mockResolvedValue({ ...DETAIL, id: 1, belum_dibaca: false, bisa_balas: true })
    const { w } = await pasang({ idTiket: 1 })
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('x')
    svc.tiket.balas.mockRejectedValueOnce(apiError(422, { message: 'Pesan maksimal 5.000 karakter.', errors: { msg: ['Pesan maksimal 5.000 karakter.'] } }))
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    await flushPromises()
    expect(galatKontrol(w.get('[data-testid="halo-balas-form"] textarea').element)).toBe('Pesan maksimal 5.000 karakter.')
  })
})

describe('R1 (BLOK): Lihat semua Perlu ditindak', () => {
  it('Lihat semua → perlu_tindak + baca semua, status/q/kategori dikosongkan; Hapus filter → kembali baca belum', async () => {
    svc.petugas.saya.mockResolvedValue({ petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 }, { id: 2, nama: 'Gaji', status: 1 }], bisa_ekspor: true })
    const banyak = Array.from({ length: 5 }, (_, i) => baris({ id: 100 + i, pesan_terakhir: null }))
    svc.tiket.list.mockImplementation(async (f: { perlu_tindak?: boolean }) => (f.perlu_tindak ? { ...DAFTAR, items: banyak, total: 8 } : DAFTAR))
    const { w } = await pasang({ query: '?tab=antrean' })
    await daftarAntrean.ubahFilter({ status: 'open', kategori: 2 })
    await flushPromises()
    // Celah R1: "cuti" diketik di kotak cari dan jeda 300 ms dijalankan dulu, BARU "Lihat semua" diklik.
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    try {
      await w.get('[data-testid="halo-daftar-antrean"] input[type="search"]').setValue('cuti')
      await vi.advanceTimersByTimeAsync(300)
    } finally {
      vi.useRealTimers()
    }
    await flushPromises()
    expect(svc.tiket.list.mock.calls.at(-1)![0]).toMatchObject({ peran: 'petugas', q: 'cuti' })
    await w.get('[data-testid="halo-perlu-tindak-semua"]').trigger('click')
    await flushPromises()
    const akhir = svc.tiket.list.mock.calls.at(-1)![0] as Record<string, unknown>
    expect(akhir).toMatchObject({ peran: 'petugas', perlu_tindak: true, baca: 'semua' })
    expect([akhir.status ?? null, akhir.q ?? '', akhir.kategori ?? null]).toEqual([null, '', null])
    expect((w.get('[data-testid="halo-daftar-antrean"] input[type="search"]').element as HTMLInputElement).value).toBe('')
    await w.get('[data-testid="halo-hapus-filter-perlu-tindak"]').trigger('click')
    await flushPromises()
    expect(svc.tiket.list.mock.calls.at(-1)![0]).toMatchObject({ peran: 'petugas', baca: 'belum', perlu_tindak: false })
  })
})

describe('R3: panel Perlu ditindak dimuat sekali per aksi', () => {
  const R = (perlu: number) => ({ belum_dibaca_saya: 0, belum_dibaca_petugas: 0, perlu_tindak: perlu, badge: perlu, total: 0 })
  const panelDimuat = () => svc.tiket.list.mock.calls.filter((c) => (c[0] as { perlu_tindak?: boolean }).perlu_tindak === true).length

  async function siapkan() {
    svc.petugas.saya.mockResolvedValue({ petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 }], bisa_ekspor: true })
    const t = baris({ id: 5, status: 'open', bisa_tutup: true, belum_dibaca: false, pesan_terakhir: null })
    svc.tiket.list.mockResolvedValue({ ...DAFTAR, items: [t], total: 1 })
    svc.tiket.tutup.mockResolvedValue({ ...t, status: 'closed', bisa_tutup: false })
    const ctx = await pasang({ query: '?tab=antrean' })
    return ctx
  }
  async function tutupLewatMenu(w: Awaited<ReturnType<typeof pasang>>['w']) {
    await selectRowAction(w, 'halo-tiket-actions-5', 'tutup')
    ;([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Tutup') as HTMLButtonElement).click()
    await flushPromises()
    await flushPromises()
  }

  it('angka perlu_tindak berubah (1 → 0) → tepat 1 GET panel (oleh watcher)', async () => {
    setRingkasanHalo(R(1))
    svc.tiket.ringkasan.mockResolvedValue(R(0))
    const { w } = await siapkan()
    const awal = panelDimuat()
    await tutupLewatMenu(w)
    expect(panelDimuat()).toBe(awal + 1)
  })

  it('angka perlu_tindak tetap → tepat 1 GET panel (oleh store); muat ringkasan tanpa perubahan → 0 GET panel', async () => {
    setRingkasanHalo(R(2))
    svc.tiket.ringkasan.mockResolvedValue(R(2))
    const { w } = await siapkan()
    const awal = panelDimuat()
    await tutupLewatMenu(w)
    expect(panelDimuat()).toBe(awal + 1)
    await muatRingkasan()
    await flushPromises()
    expect(panelDimuat()).toBe(awal + 1)
  })
})

describe('R4/R5: petugas/saya disegarkan dan deep-link antrean', () => {
  const AKTIF = { petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 as const }], bisa_ekspor: true }

  it('R4: kunjungan berikutnya memuat ulang petugas/saya (paksa) → tab Antrean muncul sesudah admin menjadi petugas', async () => {
    const a = await pasang({ role: Role.SUPER_ADMIN })
    expect(a.w.find('[data-testid="halo-tabs"]').exists()).toBe(false)
    a.w.unmount()
    svc.petugas.saya.mockResolvedValue(AKTIF)
    const b = await pasang({ role: Role.SUPER_ADMIN })
    expect(svc.petugas.saya).toHaveBeenCalledTimes(2)
    expect(b.w.findAll('[data-testid="halo-tabs"] [role="tab"]').map((t) => t.text())).toEqual(['Tiket Saya', 'Antrean'])
  })

  it('R5: ?tab=antrean saat petugas/saya memuat → "Memuat..." (tab Saya tidak dipasang); galat → notice + Coba lagi', async () => {
    let tolak: (e: unknown) => void = () => {}
    svc.petugas.saya.mockReturnValueOnce(new Promise((_r, j) => (tolak = j)))
    const { w } = await pasang({ query: '?tab=antrean' })
    expect(w.find('[data-testid="halo-antrean-memuat"]').exists()).toBe(true)
    expect(w.find('[data-testid="halo-daftar-saya"]').exists()).toBe(false)
    tolak(apiError(500, { message: 'Terjadi kesalahan server.' }))
    await flushPromises()
    expect(w.get('[data-testid="halo-antrean-galat"]').text()).toContain('Terjadi kesalahan server.')
    svc.petugas.saya.mockResolvedValueOnce(AKTIF)
    await w.get('[data-testid="halo-antrean-coba-lagi"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="halo-daftar-antrean"]').exists()).toBe(true)
  })
})

describe('R7 (a, c): pengingat di form balas petugas', () => {
  it('jatuh tempo + hint baru tampil sesudah kirim (detail dimuat ulang); aktif=false → tanpa elemen jatuh tempo', async () => {
    const dasar = { ...(detailPetugas.data as TiketDetail), belum_dibaca: false }
    svc.tiket.detail
      .mockResolvedValueOnce({ ...dasar, pengingat: { aktif: false, jatuh_tempo: null } })
      .mockResolvedValueOnce({ ...dasar, pengingat: { aktif: true, jatuh_tempo: '2099-01-01T00:00:00Z' } })
    svc.tiket.ringkasan.mockResolvedValue({ belum_dibaca_saya: 0, belum_dibaca_petugas: 0, perlu_tindak: 0, badge: 0, total: 0 })
    svc.tiket.balas.mockResolvedValueOnce({ pesan: dasar.pesan[1], tiket: { ...DAFTAR.items[0]!, id: dasar.id, sebagai_petugas: true } })
    const { w } = await pasang({ idTiket: dasar.id })
    expect(w.find('[data-testid="halo-pengingat-jatuh-tempo"]').exists()).toBe(false)
    expect(w.find('[data-testid="halo-pengingat-hint"]').exists()).toBe(false)
    expect((w.get('[data-testid="halo-balas-ingatkan"] input[type="checkbox"]').element as HTMLInputElement).checked).toBe(false)
    await w.get('[data-testid="halo-balas-form"] textarea').setValue('Kami cek.')
    await w.get('[data-testid="halo-balas-ingatkan"] input[type="checkbox"]').setValue(true)
    await w.get('[data-testid="halo-balas-form"]').trigger('submit')
    await flushPromises()
    await flushPromises()
    expect(w.get('[data-testid="halo-pengingat-jatuh-tempo"]').text()).toContain('Pengingat aktif')
    expect(w.get('[data-testid="halo-pengingat-hint"]').text()).toBe('Kirim tanpa centang akan membatalkan pengingat.')
  })

  it('jatuh tempo sudah lewat → label "Pengingat jatuh tempo"', async () => {
    svc.tiket.detail.mockResolvedValueOnce({ ...(detailPetugas.data as TiketDetail), belum_dibaca: false, pengingat: { aktif: true, jatuh_tempo: '2020-01-01T00:00:00Z' } })
    const { w } = await pasang({ idTiket: detailPetugas.data.id })
    expect(w.get('[data-testid="halo-pengingat-jatuh-tempo"]').text()).toContain('Pengingat jatuh tempo')
  })
})

describe('R11: ⋮ Hapus di antrean petugas (fixture tiket-daftar-petugas)', () => {
  it('antrean: Hapus danger aktif → ConfirmDialog → hapus + ringkasan dimuat ulang; tab saya: Hapus hidden (T2)', async () => {
    svc.petugas.saya.mockResolvedValue({ petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 }], bisa_ekspor: true })
    const ANTREAN = daftarPetugas.data as Halaman<TiketBaris>
    svc.tiket.list.mockImplementation(async (f: { peran?: string; perlu_tindak?: boolean }) =>
      f.peran === 'petugas' ? (f.perlu_tindak ? { ...ANTREAN, items: [], total: 0 } : ANTREAN) : DAFTAR,
    )
    svc.tiket.ringkasan.mockResolvedValue({ belum_dibaca_saya: 0, belum_dibaca_petugas: 0, perlu_tindak: 0, badge: 0, total: 0 })
    const t = ANTREAN.items[0]!
    svc.tiket.hapus.mockResolvedValueOnce(t.id)
    const { w } = await pasang({ query: '?tab=antrean' })
    const aksi = await rowMenuActions(w, `halo-tiket-actions-${t.id}`)
    expect(aksi.find((a) => a.label === 'Hapus')).toMatchObject({ danger: true, disabled: false })
    await selectRowAction(w, `halo-tiket-actions-${t.id}`, 'hapus')
    expect(document.body.textContent).toContain('Apakah anda yakin akan menghapus percakapan ini?')
    ;([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === 'Hapus') as HTMLButtonElement).click()
    await flushPromises()
    expect(svc.tiket.hapus).toHaveBeenCalledWith(t.id)
    await vi.waitFor(() => expect(svc.tiket.ringkasan).toHaveBeenCalled())
    expect(w.find(`[data-testid="halo-tiket-row-${t.id}"]`).exists()).toBe(false)
  })

  it('tab saya (pemilik): item Hapus tidak ada (bisa_hapus false dari BE)', async () => {
    const { w } = await pasang()
    const id = DAFTAR.items[0]!.id
    expect((await rowMenuActions(w, `halo-tiket-actions-${id}`)).some((a) => a.label === 'Hapus')).toBe(false)
  })
})

describe('B4 (MAKE-074): tombol Ekspor Rekap di tab Antrean', () => {
  const PETUGAS = { petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 as const }], bisa_ekspor: true }

  it('bisa_ekspor → tombol hanya di tab Antrean (tidak di Tiket saya)', async () => {
    svc.petugas.saya.mockResolvedValue(PETUGAS)
    const { w, router } = await pasang({ query: '?tab=antrean' })
    expect(w.find('[data-testid="halo-ekspor-buka"]').exists()).toBe(true)
    await router.push('/halo-simpeg')
    await flushPromises()
    expect(w.find('[data-testid="halo-ekspor-buka"]').exists()).toBe(false)
  })

  it('petugas aktif tetapi bisa_ekspor false → tanpa tombol (gerbang flag BE, bukan role)', async () => {
    svc.petugas.saya.mockResolvedValue({ ...PETUGAS, bisa_ekspor: false })
    const { w } = await pasang({ role: Role.SUPER_ADMIN, query: '?tab=antrean' })
    expect(w.find('[data-testid="halo-daftar-antrean"]').exists()).toBe(true)
    expect(w.find('[data-testid="halo-ekspor-buka"]').exists()).toBe(false)
  })

  it('klik → dialog periode terbuka; hasil kosong → notice pesan BE tanpa unduhan', async () => {
    svc.petugas.saya.mockResolvedValue(PETUGAS)
    svc.tiket.ekspor.mockResolvedValue({ jenis: 'kosong', pesan: 'Tidak ada data pada periode ini.' })
    const { w } = await pasang({ query: '?tab=antrean' })
    await w.get('[data-testid="halo-ekspor-buka"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('[data-testid="halo-ekspor-dialog"]')).not.toBeNull()
    for (const [nama, teks] of [['ds', '01/11/2026'], ['de', '18/11/2026']] as const) {
      const el = document.querySelector(`input[name="${nama}"]`) as HTMLInputElement
      el.value = teks
      el.dispatchEvent(new Event('input'))
      await flushPromises()
      el.dispatchEvent(new Event('blur'))
      await flushPromises()
    }
    ;(document.querySelector('[data-testid="halo-ekspor-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.tiket.ekspor).toHaveBeenCalledWith('2026-11-01', '2026-11-18')
    expect(document.querySelector('[data-testid="halo-ekspor-kosong"]')?.textContent?.trim()).toBe('Tidak ada data pada periode ini.')
    expect(unduhan.simpan).not.toHaveBeenCalled()
  })
})

describe('celah R1: teks kosong tabel saat Lihat semua', () => {
  it('perlu_tindak tanpa baris → "Tidak ada tiket yang perlu ditindak." di halo-daftar-kosong', async () => {
    svc.petugas.saya.mockResolvedValue({ petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 }], bisa_ekspor: true })
    const lima = Array.from({ length: 5 }, (_, i) => baris({ id: 100 + i, pesan_terakhir: null }))
    // Panel (per_page 5) berisi 8 → "Lihat semua" tampil; tabel antrean perlu_tindak (halaman penuh) kosong.
    svc.tiket.list.mockImplementation(async (f: { perlu_tindak?: boolean; per_page?: number }) =>
      f.perlu_tindak ? (f.per_page === 5 ? { ...DAFTAR, items: lima, total: 8 } : { ...DAFTAR, items: [], total: 0 }) : DAFTAR,
    )
    const { w } = await pasang({ query: '?tab=antrean' })
    await w.get('[data-testid="halo-perlu-tindak-semua"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid="halo-daftar-antrean"] [data-testid="halo-daftar-kosong"]').text()).toBe('Tidak ada tiket yang perlu ditindak.')
  })
})

describe('S2: deep-link ?tab=antrean', () => {
  const AKTIF = { petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 as const }], bisa_ekspor: true }

  it('(a) non-petugas + ?tab=antrean sesudah petugas/saya siap → tab Saya + kartu Kontak Helpdesk tampil', async () => {
    const { w } = await pasang({ query: '?tab=antrean' })
    expect(w.find('[data-testid="halo-daftar-saya"]').exists()).toBe(true)
    expect(w.find('[data-testid="halo-kontak"]').exists()).toBe(true)
  })

  it('(b) galat muat paksa dengan cache petugas aktif → antrean dari cache + UiTabs tetap, tanpa notice galat', async () => {
    svc.petugas.saya.mockResolvedValue(AKTIF)
    const a = await pasang({ query: '?tab=antrean' })
    a.w.unmount()
    svc.petugas.saya.mockRejectedValue(apiError(500, { message: 'Terjadi kesalahan server.' }))
    const { w } = await pasang({ query: '?tab=antrean' })
    expect(w.find('[data-testid="halo-antrean-galat"]').exists()).toBe(false)
    expect(w.find('[data-testid="halo-daftar-antrean"]').exists()).toBe(true)
    expect(w.findAll('[data-testid="halo-tabs"] [role="tab"]').map((t) => t.text())).toEqual(['Tiket Saya', 'Antrean'])
  })

  it('(b, c) galat tanpa data petugas → notice tanpa role="alert" bersarang; tab "Tiket Saya" tetap bisa dipilih', async () => {
    svc.petugas.saya.mockRejectedValue(apiError(500, { message: 'Terjadi kesalahan server.' }))
    const { w, router } = await pasang({ query: '?tab=antrean' })
    const notice = w.get('[data-testid="halo-antrean-galat"]')
    expect(notice.text()).toContain('Terjadi kesalahan server.')
    expect(notice.findAll('[role="alert"]')).toHaveLength(0)
    expect(w.find('[data-testid="halo-kontak"]').exists()).toBe(false)
    const tabSaya = w.findAll('[data-testid="halo-tabs"] [role="tab"]').find((t) => t.text() === 'Tiket Saya')!
    await tabSaya.trigger('mousedown', { button: 0 })
    await tabSaya.trigger('click')
    await tabSaya.trigger('focus')
    await flushPromises()
    expect(router.currentRoute.value.query.tab).toBeUndefined()
    expect(w.find('[data-testid="halo-daftar-saya"]').exists()).toBe(true)
  })
})

describe('S3: panel disegarkan sesudah baca bila ringkasan belum ada', () => {
  it('ringkasan null → buka tiket petugas belum dibaca → POST baca → panel dimuat ulang tepat 1×', async () => {
    svc.petugas.saya.mockResolvedValue({ petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 }], bisa_ekspor: true })
    svc.tiket.detail.mockResolvedValue({ ...(detailPetugas.data as TiketDetail), belum_dibaca: true })
    svc.tiket.baca.mockResolvedValue({ belum_dibaca_saya: 0, belum_dibaca_petugas: 0, perlu_tindak: 2, badge: 2, total: 0 })
    const panel = () => svc.tiket.list.mock.calls.filter((c) => (c[0] as { perlu_tindak?: boolean }).perlu_tindak === true).length
    await pasang({ query: '?tab=antrean' })
    const awal = panel()
    await bukaTiket(detailPetugas.data.id)
    await flushPromises()
    expect(svc.tiket.baca).toHaveBeenCalledTimes(1)
    expect(panel()).toBe(awal + 1)
  })
})

describe('S4: tombol "Data pelapor"', () => {
  it('klik membuka lalu menutup tooltip; isi hanya lewat aria-describedby ke elemen hidden', async () => {
    svc.tiket.detail.mockResolvedValueOnce({ ...(detailPetugas.data as TiketDetail), belum_dibaca: false })
    const { w } = await pasang({ idTiket: detailPetugas.data.id })
    const tombol = w.get('[data-testid="halo-thread-snapshot"]')
    const rujukan = document.getElementById(tombol.attributes('aria-describedby')!)!
    expect(rujukan.hasAttribute('hidden')).toBe(true)
    expect([...document.querySelectorAll('[data-testid="halo-thread"] .sr-only')].some((e) => e.textContent?.includes('NIP:'))).toBe(false)
    const klik = async () => {
      tombol.element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
      await tombol.trigger('click')
      await flushPromises()
    }
    await klik()
    await vi.waitFor(() => expect(document.querySelector('[role="tooltip"]')?.textContent).toContain('NIP:'))
    await klik()
    await vi.waitFor(() => expect(document.querySelector('[role="tooltip"]')).toBeNull())
  })
})

describe('S5: label pengingat berganti saat jatuh tempo lewat', () => {
  it('"Pengingat aktif" → (60 dtk, jatuh tempo lewat) → "Pengingat jatuh tempo" tanpa muat ulang', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    try {
      vi.setSystemTime(new Date('2026-11-19T02:59:30Z'))
      svc.tiket.detail.mockResolvedValue({ ...(detailPetugas.data as TiketDetail), belum_dibaca: false, pengingat: { aktif: true, jatuh_tempo: '2026-11-19T03:00:00Z' } })
      const { w } = await pasang({ idTiket: detailPetugas.data.id })
      expect(w.get('[data-testid="halo-pengingat-jatuh-tempo"]').text()).toContain('Pengingat aktif')
      const muat = svc.tiket.detail.mock.calls.length
      vi.advanceTimersByTime(60_000)
      await flushPromises()
      expect(w.get('[data-testid="halo-pengingat-jatuh-tempo"]').text()).toContain('Pengingat jatuh tempo')
      expect(svc.tiket.detail.mock.calls.length).toBe(muat)
    } finally {
      vi.useRealTimers()
    }
  })
})

describe('B4 T1: pesan 403 bukan_petugas tetap terbaca walau bisa_ekspor jadi false', () => {
  it('ekspor 403 → petugas/saya berikutnya bisa_ekspor=false → pesan tetap di dialog; sesudah ditutup tombol hilang', async () => {
    const PETUGAS = { petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 as const }], bisa_ekspor: true }
    svc.petugas.saya.mockResolvedValueOnce(PETUGAS).mockResolvedValue({ ...PETUGAS, aktif: false, bisa_ekspor: false })
    svc.tiket.ekspor.mockRejectedValue(apiError(403, { message: 'Fitur ini hanya untuk petugas Halo Simpeg yang aktif.', code: 'bukan_petugas' }))
    const { w } = await pasang({ query: '?tab=antrean' })
    await w.get('[data-testid="halo-ekspor-buka"]').trigger('click')
    await flushPromises()
    for (const [nama, teks] of [['ds', '01/11/2026'], ['de', '18/11/2026']] as const) {
      const el = document.querySelector(`input[name="${nama}"]`) as HTMLInputElement
      el.value = teks
      el.dispatchEvent(new Event('input'))
      await flushPromises()
      el.dispatchEvent(new Event('blur'))
      await flushPromises()
    }
    ;(document.querySelector('[data-testid="halo-ekspor-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    await flushPromises()
    expect(svc.petugas.saya).toHaveBeenCalledTimes(2)
    expect(document.querySelector('[data-testid="halo-ekspor-galat"]')?.textContent?.trim()).toBe('Fitur ini hanya untuk petugas Halo Simpeg yang aktif.')
    ;(document.querySelector('[data-testid="halo-ekspor-dialog"] button[aria-label="Tutup"]') as HTMLButtonElement).click()
    await flushPromises()
    expect(document.querySelector('[data-testid="halo-ekspor-dialog"]')).toBeNull()
    expect(w.find('[data-testid="halo-ekspor-buka"]').exists()).toBe(false)
  })
})

describe('B4 K1: "Data pelapor" lewat keyboard', () => {
  it('fokus membuka tooltip; Enter (keydown + click) menutup; Enter lagi membuka', async () => {
    svc.tiket.detail.mockResolvedValueOnce({ ...(detailPetugas.data as TiketDetail), belum_dibaca: false })
    const { w } = await pasang({ idTiket: detailPetugas.data.id })
    const tombol = w.get('[data-testid="halo-thread-snapshot"]')
    await tombol.trigger('focus')
    await vi.waitFor(() => expect(document.querySelector('[role="tooltip"]')).not.toBeNull())
    const enter = async () => {
      await tombol.trigger('keydown', { key: 'Enter' })
      await tombol.trigger('click')
      await flushPromises()
    }
    await enter()
    await vi.waitFor(() => expect(document.querySelector('[role="tooltip"]')).toBeNull())
    await enter()
    await vi.waitFor(() => expect(document.querySelector('[role="tooltip"]')?.textContent).toContain('NIP:'))
  })
})

describe('CR-142 T-B3: kolom Aksi selalu terlihat (sticky kanan)', () => {
  const ukur = (el: HTMLElement, u: { scrollWidth: number; clientWidth: number; scrollLeft: number }) => {
    Object.defineProperty(el, 'scrollWidth', { configurable: true, value: u.scrollWidth })
    Object.defineProperty(el, 'clientWidth', { configurable: true, value: u.clientWidth })
    Object.defineProperty(el, 'scrollLeft', { configurable: true, writable: true, value: u.scrollLeft })
  }

  it.each([
    ['pemilik', ''],
    ['antrean', '?tab=antrean'],
  ])('tabel %s: header & sel Aksi sticky right-0 berlatar; bayangan selama tabel tergulir (1536 px + sidebar: 694 > 652)', async (_n, query) => {
    svc.petugas.saya.mockResolvedValue({ petugas: true, aktif: true, kategori: [{ id: 1, nama: 'Cuti', status: 1 }], bisa_ekspor: false })
    const id = DAFTAR.items[0]!.id
    const { w } = await pasang({ query })
    const daftar = w.get(query ? '[data-testid="halo-daftar-antrean"]' : '[data-testid="halo-daftar-saya"]')
    const th = () => daftar.get('th[data-kolom="aksi"]')
    const td = () => daftar.get(`[data-testid="halo-tiket-row-${id}"] td[data-kolom="aksi"]`)
    expect(th().classes()).toEqual(expect.arrayContaining(['sticky', 'right-0', 'z-10', 'bg-slate-50']))
    expect(td().classes()).toEqual(expect.arrayContaining(['sticky', 'right-0', 'z-10', 'bg-white']))
    expect(td().find(`[data-testid="halo-tiket-actions-${id}"]`).exists()).toBe(true)
    const scroller = daftar.get('[data-testid="halo-daftar-scroll"]')
    ukur(scroller.element as HTMLElement, { scrollWidth: 694, clientWidth: 652, scrollLeft: 0 })
    await scroller.trigger('scroll')
    expect(th().classes()).toContain('shadow-sticky-end')
    expect(td().classes()).toContain('shadow-sticky-end')
    ukur(scroller.element as HTMLElement, { scrollWidth: 694, clientWidth: 652, scrollLeft: 42 })
    await scroller.trigger('scroll')
    expect(td().classes()).not.toContain('shadow-sticky-end')
  })

  it('baris aktif: sel Aksi sticky ikut warna baris aktif (tidak menutupi dengan putih)', async () => {
    const id = DAFTAR.items[0]!.id
    svc.tiket.detail.mockResolvedValue({ ...DETAIL, id, belum_dibaca: false })
    const { w } = await pasang({ idTiket: id })
    const td = w.get(`[data-testid="halo-tiket-row-${id}"] td[data-kolom="aksi"]`)
    expect(td.classes()).toContain('bg-[#E8F1FF]')
    expect(td.classes()).not.toContain('bg-white')
  })
})

describe('CR-142 T-B4: ruang bawah untuk launcher Vanya (AC HP24)', () => {
  it.each([
    ['role 2 (widget Vanya tampil)', Role.PEGAWAI],
    ['role 1 (tanpa widget)', Role.SUPER_ADMIN],
  ])('%s: halaman memberi ruang bawah ≥ tinggi launcher 64 px + offset 24 px', async (_n, role) => {
    const { w } = await pasang({ role })
    const halaman = w.get('[data-testid="halo-halaman"]')
    expect(halaman.classes()).toContain(RUANG_LAUNCHER_CLASS)
    // pb-24 = 6rem = 96 px > 64 px launcher + 24 px offset `sm:bottom-6` (VanyaWidget).
    expect(RUANG_LAUNCHER_CLASS).toBe('pb-24')
  })
})

describe('CR-142 T-B1: notice sukses sesudah tiket baru', () => {
  async function kirimTiketBaru(w: Awaited<ReturnType<typeof pasang>>['w']) {
    await w.get('[data-testid="halo-tiket-baru"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="halo-tiket-baru-form"]') as HTMLFormElement
    const subjek = form.querySelector('input[name="subjek"]') as HTMLInputElement
    subjek.value = 'Baru'
    subjek.dispatchEvent(new Event('input'))
    const pesan = form.querySelector('textarea') as HTMLTextAreaElement
    pesan.value = 'Isi'
    pesan.dispatchEvent(new Event('input'))
    ;(form.querySelector('[role="combobox"]') as HTMLElement).click()
    await flushPromises()
    ;(document.querySelector('[role="option"]') as HTMLElement).click()
    await flushPromises()
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
  }

  it.each([
    ['message BE (CR-143)', 'Berhasil menambahkan data halo simpeg.', 'Berhasil menambahkan data halo simpeg.'],
    ['tanpa message → cadangan legacy', null, 'Berhasil menambahkan data halo simpeg.'],
    ['message BE lain dipakai apa adanya', 'Tiket terkirim.', 'Tiket terkirim.'],
  ])('%s → notice sukses; thread tiket baru dibuka; pindah tiket → notice hilang', async (_n, pesan, teks) => {
    svc.tiket.buat.mockResolvedValueOnce({ tiket: { ...DETAIL, id: 42, belum_dibaca: false }, pesan })
    svc.tiket.detail.mockResolvedValue({ ...DETAIL, id: 42, belum_dibaca: false })
    const { w, router, props } = await pasang()
    await kirimTiketBaru(w)
    expect(svc.tiket.buat).toHaveBeenCalledTimes(1)
    expect(router.currentRoute.value.fullPath).toBe('/halo-simpeg/tiket/42')
    props.value = { idTiket: 42, idTidakSah: false }
    await flushPromises()
    expect(w.get('[data-testid="halo-tiket-baru-sukses"]').text()).toBe(teks)
    props.value = { idTiket: 7, idTidakSah: false }
    await flushPromises()
    expect(w.find('[data-testid="halo-tiket-baru-sukses"]').exists()).toBe(false)
  })
})
