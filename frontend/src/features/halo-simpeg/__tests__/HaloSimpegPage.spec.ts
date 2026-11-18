/**
 * Layar pemilik Halo Simpeg (B2, MAKE-073): master-detail (AC-E08.10), ⋮ Lihat detail → Tutup → Hapus (AGENTS.md §1),
 * ConfirmDialog teks legacy, filter baca bawaan `semua` (AC-E08.13), "Tambah Pertanyaan" hanya role 2/6/7, thread (label
 * apa adanya, teks tanpa v-html, lampiran lewat E-04), tiket kategori NULL (H8-16 (a1)), galat 422/429 per kolom
 * (AC-E08.3/15), dan tampilan < 1024 px satu panel. Layanan di-mock; bentuk data = fixture kontrak MAKE-071.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError, AxiosHeaders } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'

const desktop = vi.hoisted(() => ({ nilai: true }))
vi.mock('@/shared/composables/useIsDesktop', async () => {
  const { ref: r } = await import('vue')
  return { useIsDesktop: () => r(desktop.nilai), DESKTOP_QUERY: '(min-width: 1024px)' }
})
vi.mock('@/shared/layouts/RedesignShell.vue', () => ({
  default: defineComponent({ setup: (_p, { slots }) => () => h('div', { 'data-testid': 'shell' }, slots.default?.()) }),
}))
const svc = vi.hoisted(() => ({
  tiket: { list: vi.fn(), detail: vi.fn(), baca: vi.fn(), tutup: vi.fn(), hapus: vi.fn(), balas: vi.fn(), buat: vi.fn(), lampiran: vi.fn(), ringkasan: vi.fn() },
  kategori: { opsi: vi.fn() },
  kontak: { aktif: vi.fn() },
}))
vi.mock('../services/tiket.service', async (asli) => ({ ...(await asli<typeof import('../services/tiket.service')>()), tiketHaloService: svc.tiket }))
vi.mock('../services/master.service', async (asli) => ({
  ...(await asli<typeof import('../services/master.service')>()),
  kategoriHaloService: svc.kategori,
  kontakHaloService: svc.kontak,
}))
const unduhan = vi.hoisted(() => ({ simpan: vi.fn() }))
vi.mock('@/features/kepegawaian/services/berkasUnduhan', async (asli) => ({
  ...(await asli<typeof import('@/features/kepegawaian/services/berkasUnduhan')>()),
  simpanBerkas: unduhan.simpan,
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import { resetHaloSimpeg } from '../stores/haloSimpeg'
import type { Halaman, TiketBaris, TiketDetail } from '../types'
import HaloSimpegPage from '../views/HaloSimpegPage.vue'
import galat422Ditutup from './fixtures/galat-422-ditutup.json'
import galat422Tiket from './fixtures/galat-422-tiket.json'
import galat429 from './fixtures/galat-429.json'
import kategoriOpsi from './fixtures/kategori-opsi.json'
import kontakAktif from './fixtures/kontak-aktif.json'
import daftarPemilik from './fixtures/tiket-daftar-pemilik.json'
import detailPemilik from './fixtures/tiket-detail-pemilik.json'
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

async function pasang(opsi: { role?: RoleCode; idTiket?: number | null; idTidakSah?: boolean } = {}) {
  useAuthStore().setSession({ username: 'uji', user_level: opsi.role ?? Role.PEGAWAI, must_change_password: false } as User)
  const router = buatRouter()
  await router.push(opsi.idTiket ? `/halo-simpeg/tiket/${opsi.idTiket}` : '/halo-simpeg')
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

  it('desktop → dua panel bersamaan', async () => {
    const { w } = await pasang({ idTiket: DETAIL.id })
    expect(w.find('[data-testid="halo-panel-daftar"]').exists()).toBe(true)
    expect(w.find('[data-testid="halo-panel-thread"]').exists()).toBe(true)
  })
})
