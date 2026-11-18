/**
 * Admin master Halo Simpeg (B3, role 1; AC-E08.5, AC-E08.16): ⋮ Edit → Aktifkan/Nonaktifkan → Hapus, Hapus `disabled` dari
 * `bisa_hapus` + keterangan BE, `peringatan` PATCH/PUT → UiNotice peringatan, 422 `petugas_terakhir` / duplikat, form
 * kandidat ber-jeda (2..50, peringatan satker), UiPilihBanyak kategori. Layanan di-mock; bentuk data = fixture MAKE-070.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError, AxiosHeaders } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@/shared/layouts/RedesignShell.vue', () => ({
  default: defineComponent({ setup: (_p, { slots }) => () => h('div', { 'data-testid': 'shell' }, slots.default?.()) }),
}))
const svc = vi.hoisted(() => ({
  kategori: { list: vi.fn(), tambah: vi.fn(), ubah: vi.fn(), setStatus: vi.fn(), hapus: vi.fn(), opsi: vi.fn() },
  petugas: { list: vi.fn(), tambah: vi.fn(), ubah: vi.fn(), setStatus: vi.fn(), hapus: vi.fn(), kandidat: vi.fn(), saya: vi.fn() },
  kontak: { list: vi.fn(), tambah: vi.fn(), ubah: vi.fn(), setStatus: vi.fn(), hapus: vi.fn(), aktif: vi.fn() },
}))
vi.mock('../services/master.service', async (asli) => ({
  ...(await asli<typeof import('../services/master.service')>()),
  kategoriHaloService: svc.kategori,
  petugasHaloService: svc.petugas,
  kontakHaloService: svc.kontak,
}))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import type { BagianAdminHalo } from '../routes.halo'
import HaloSimpegAdminPage from '../views/HaloSimpegAdminPage.vue'
import kandidatPeringatan from './fixtures/kandidat-peringatan.json'
import kategoriDaftar from './fixtures/kategori-daftar.json'
import kategoriOpsi from './fixtures/kategori-opsi.json'
import kontakDaftar from './fixtures/kontak-daftar.json'
import petugasDaftar from './fixtures/petugas-daftar.json'
import petugasPutPeringatan from './fixtures/petugas-put-peringatan.json'
import statusPatchPeringatan from './fixtures/status-patch-peringatan.json'
import galat422Terakhir from './fixtures/galat-422-petugas-terakhir.json'


function apiError(status: number, body: { message: string; code?: string; errors?: Record<string, string[]> }) {
  const res = { status, statusText: 'x', headers: new AxiosHeaders(), config: { headers: new AxiosHeaders() }, data: { status: 'error', ...body } }
  return { status, message: body.message, errors: body.errors ?? null, isNetworkError: false, original: new AxiosError('x', 'ERR', undefined, null, res as never) }
}

async function pasang(bagian: BagianAdminHalo) {
  const w = mount(HaloSimpegAdminPage, { props: { bagian }, attachTo: document.body })
  await flushPromises()
  return w
}

const konfirmasi = (label: string) =>
  ([...document.querySelectorAll('[role="alertdialog"] button')].find((b) => b.textContent?.trim() === label) as HTMLButtonElement).click()

beforeEach(() => {
  setActivePinia(createPinia())
  for (const g of Object.values(svc)) for (const f of Object.values(g)) f.mockReset()
  svc.kategori.list.mockResolvedValue(kategoriDaftar.data)
  svc.kategori.opsi.mockResolvedValue(kategoriOpsi.data)
  svc.petugas.list.mockResolvedValue(petugasDaftar.data)
  svc.kontak.list.mockResolvedValue(kontakDaftar.data)
})

afterEach(() => {
  vi.useRealTimers()
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('admin Kategori', () => {
  it('⋮ urutan Edit → Nonaktifkan/Aktifkan → Hapus; Hapus disabled bila bisa_hapus false + keterangan', async () => {
    const w = await pasang('kategori')
    for (const k of kategoriDaftar.data.items) {
      const aksi = await rowMenuActions(w, `halo-kategori-actions-${k.id}`)
      expect(aksi.map((a) => a.label)).toEqual(['Edit', k.status === 1 ? 'Nonaktifkan' : 'Aktifkan', 'Hapus'])
      expect(aksi.at(-1)).toMatchObject({ danger: true, disabled: !k.bisa_hapus })
      expect(w.find(`[data-testid="halo-kategori-alasan-${k.id}"]`).exists()).toBe(!k.bisa_hapus)
    }
    expect(w.get(`[data-testid="halo-kategori-actions-${kategoriDaftar.data.items[0]!.id}"]`).attributes('aria-label')).toBe(
      `Aksi untuk ${kategoriDaftar.data.items[0]!.nama}`,
    )
  })

  it('Nonaktifkan → PATCH status 0, daftar dimuat ulang', async () => {
    const aktif = kategoriDaftar.data.items.find((k) => k.status === 1)!
    svc.kategori.setStatus.mockResolvedValueOnce({ ...aktif, status: 0, peringatan: null })
    const w = await pasang('kategori')
    await selectRowAction(w, `halo-kategori-actions-${aktif.id}`, 'deactivate')
    await flushPromises()
    expect(svc.kategori.setStatus).toHaveBeenCalledWith(aktif.id, 0)
    expect(svc.kategori.list).toHaveBeenCalledTimes(2)
    expect(w.text()).toContain('Kategori dinonaktifkan.')
  })

  it('form tambah: nama wajib (klien); 422 kategori_duplikat tampil di kolom Nama', async () => {
    const w = await pasang('kategori')
    await w.get('[data-testid="halo-kategori-tambah"]').trigger('click')
    await flushPromises()
    const form = () => document.querySelector('[data-testid="halo-kategori-form"]') as HTMLFormElement
    form().dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(document.body.textContent).toContain('Nama kategori wajib diisi.')
    expect(svc.kategori.tambah).not.toHaveBeenCalled()
    const nama = form().querySelector('input[name="nama"]') as HTMLInputElement
    nama.value = 'Cuti'
    nama.dispatchEvent(new Event('input'))
    svc.kategori.tambah.mockRejectedValueOnce(
      apiError(422, { message: 'Kategori sudah terdaftar.', code: 'kategori_duplikat', errors: { question_category: ['Kategori sudah terdaftar.'] } }),
    )
    form().dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.kategori.tambah).toHaveBeenCalledWith({ nama: 'Cuti', status: 1 })
    expect(document.body.textContent).toContain('Kategori sudah terdaftar.')
    expect(document.querySelector('[data-testid="halo-kategori-form-galat"]')).toBeNull()
  })
})

describe('admin Petugas (AC-E08.16)', () => {
  const terkunci = petugasDaftar.data.items.find((p) => !p.bisa_hapus)!
  const bebas = petugasDaftar.data.items.find((p) => p.bisa_hapus)!

  it('Hapus disabled bila bisa_hapus false, dengan alasan_tidak_bisa_hapus dari BE', async () => {
    const w = await pasang('petugas')
    expect((await rowMenuActions(w, `halo-petugas-actions-${terkunci.id}`)).at(-1)).toMatchObject({ label: 'Hapus', disabled: true })
    expect(w.get(`[data-testid="halo-petugas-alasan-${terkunci.id}"]`).text()).toBe(terkunci.alasan_tidak_bisa_hapus)
    expect((await rowMenuActions(w, `halo-petugas-actions-${bebas.id}`)).at(-1)).toMatchObject({ label: 'Hapus', disabled: false })
  })

  it('Nonaktifkan yang mengembalikan peringatan → UiNotice peringatan', async () => {
    const aktif = petugasDaftar.data.items.find((p) => p.status === 1)!
    svc.petugas.setStatus.mockResolvedValueOnce(statusPatchPeringatan.data)
    const w = await pasang('petugas')
    await selectRowAction(w, `halo-petugas-actions-${aktif.id}`, 'deactivate')
    await flushPromises()
    expect(w.get('[data-testid="halo-petugas-peringatan"]').text()).toBe(statusPatchPeringatan.data.peringatan)
  })

  it('Hapus → ConfirmDialog → 422 petugas_terakhir tampil sebagai pesan BE', async () => {
    svc.petugas.hapus.mockRejectedValueOnce(apiError(422, galat422Terakhir))
    const w = await pasang('petugas')
    await selectRowAction(w, `halo-petugas-actions-${bebas.id}`, 'delete')
    konfirmasi('Hapus')
    await flushPromises()
    expect(svc.petugas.hapus).toHaveBeenCalledWith(bebas.id)
    expect(w.get('[data-testid="halo-petugas-galat"]').text()).toBe(galat422Terakhir.message)
  })

  it('form: kandidat ber-jeda hanya 2..50 karakter + peringatan satker; pilih kandidat + kategori → tambah dengan id_user', async () => {
    vi.useFakeTimers()
    svc.petugas.kandidat.mockResolvedValue(kandidatPeringatan.data)
    svc.petugas.tambah.mockResolvedValueOnce(bebas)
    const w = await pasang('petugas')
    await w.get('[data-testid="halo-petugas-tambah"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="halo-petugas-form"]') as HTMLFormElement
    const akun = form.querySelector('input[name="akun"]') as HTMLInputElement
    akun.value = 'a'
    akun.dispatchEvent(new Event('input'))
    await vi.advanceTimersByTimeAsync(300)
    expect(svc.petugas.kandidat).not.toHaveBeenCalled()
    akun.value = 'adm'
    akun.dispatchEvent(new Event('input'))
    await vi.advanceTimersByTimeAsync(299)
    expect(svc.petugas.kandidat).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(svc.petugas.kandidat).toHaveBeenCalledWith('adm')
    expect(document.querySelector('[data-testid="halo-kandidat-peringatan"]')?.textContent).toBe(kandidatPeringatan.data.peringatan)
    const k = kandidatPeringatan.data.items[0]!
    akun.value = k.label
    akun.dispatchEvent(new Event('input'))
    const centang = document.querySelector(`[data-testid="halo-petugas-kategori-${kategoriOpsi.data[0]!.id}"] input`) as HTMLInputElement
    centang.checked = true
    centang.dispatchEvent(new Event('change'))
    const email = form.querySelector('input[name="email"]') as HTMLInputElement
    email.value = 'admin@contoh.test'
    email.dispatchEvent(new Event('input'))
    await flushPromises()
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.petugas.tambah).toHaveBeenCalledWith({
      id_user: k.id_user,
      kategori: [kategoriOpsi.data[0]!.id],
      email: 'admin@contoh.test',
      no_hp: '',
      status: 1,
    })
  })

  it('Edit → PUT yang mengembalikan peringatan (S8) → UiNotice peringatan', async () => {
    svc.petugas.ubah.mockResolvedValueOnce(petugasPutPeringatan.data)
    const w = await pasang('petugas')
    await selectRowAction(w, `halo-petugas-actions-${bebas.id}`, 'edit')
    await flushPromises()
    ;(document.querySelector('[data-testid="halo-petugas-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.petugas.ubah).toHaveBeenCalledWith(bebas.id, expect.objectContaining({ id_user: bebas.id_user }))
    expect(w.get('[data-testid="halo-petugas-peringatan"]').text()).toBe(petugasPutPeringatan.data.peringatan)
  })
})

describe('admin Kontak', () => {
  it('Hapus selalu tersedia (tanpa bisa_hapus); 422 kontak_duplikat di kolom Nomor Kontak', async () => {
    const w = await pasang('kontak')
    const k = kontakDaftar.data.items[0]!
    expect((await rowMenuActions(w, `halo-kontak-actions-${k.id}`)).at(-1)).toMatchObject({ label: 'Hapus', disabled: false })
    await w.get('[data-testid="halo-kontak-tambah"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="halo-kontak-form"]') as HTMLFormElement
    for (const [n, v] of [
      ['nama', 'Helpdesk'],
      ['kontak', '0812'],
    ] as const) {
      const el = form.querySelector(`input[name="${n}"]`) as HTMLInputElement
      el.value = v
      el.dispatchEvent(new Event('input'))
    }
    svc.kontak.tambah.mockRejectedValueOnce(
      apiError(422, { message: 'Nomor kontak sudah terdaftar.', code: 'kontak_duplikat', errors: { contact: ['Nomor kontak sudah terdaftar.'] } }),
    )
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.kontak.tambah).toHaveBeenCalledWith({ nama: 'Helpdesk', kontak: '0812', email: '', status: 1 })
    expect(document.body.textContent).toContain('Nomor kontak sudah terdaftar.')
  })

  it('daftar kosong → teks kosong; filter status mengirim 0/1 ke server', async () => {
    svc.kontak.list.mockResolvedValueOnce({ items: [], total: 0, page: 1, per_page: 20 })
    const w = await pasang('kontak')
    expect(w.get('[data-testid="halo-kontak-kosong"]').text()).toContain('Belum ada kontak')
    expect(svc.kontak.list).toHaveBeenCalledWith(expect.objectContaining({ status: null, page: 1 }))
  })
})
