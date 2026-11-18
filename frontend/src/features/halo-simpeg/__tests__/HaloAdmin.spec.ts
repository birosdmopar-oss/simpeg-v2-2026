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
  svc.petugas.saya.mockResolvedValue({ petugas: false, aktif: false, kategori: [], bisa_ekspor: false })
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
      // R2: alasan di keterangan item ⋮ Hapus (teks FE [V2]), bukan di kolom Status.
      expect(aksi.at(-1)?.keterangan).toBe(k.bisa_hapus ? undefined : 'Masih dipakai tiket atau petugas. Nonaktifkan bila tidak dipakai lagi.')
      expect(w.get(`[data-testid="halo-kategori-row-${k.id}"] td:nth-last-child(2)`).text()).toBe(k.status === 1 ? 'Aktif' : 'Tidak Aktif')
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
    expect((await rowMenuActions(w, `halo-petugas-actions-${terkunci.id}`)).at(-1)).toMatchObject({
      label: 'Hapus',
      disabled: true,
      keterangan: terkunci.alasan_tidak_bisa_hapus,
    })
    // R2: kolom Status hanya badge.
    expect(w.get(`[data-testid="halo-petugas-row-${terkunci.id}"] td:nth-last-child(2)`).text()).toBe(terkunci.status === 1 ? 'Aktif' : 'Tidak Aktif')
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
    // R8: pilih lewat opsi (indeks → id_user), bukan mengetik label.
    ;([...document.querySelectorAll('[role="option"]')].find((o) => o.textContent?.trim() === k.label) as HTMLElement).click()
    await flushPromises()
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
    // R4: perubahan petugas menyegarkan petugas/saya (tab Antrean akun sendiri).
    expect(svc.petugas.saya).toHaveBeenCalled()
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

describe('R8: kandidat petugas', () => {
  async function bukaForm() {
    vi.useFakeTimers()
    const w = await pasang('petugas')
    await w.get('[data-testid="halo-petugas-tambah"]').trigger('click')
    await flushPromises()
    const form = document.querySelector('[data-testid="halo-petugas-form"]') as HTMLFormElement
    const akun = form.querySelector('input[name="akun"]') as HTMLInputElement
    const ketik = async (v: string) => {
      akun.value = v
      akun.dispatchEvent(new Event('input'))
      await vi.advanceTimersByTimeAsync(300)
      await flushPromises()
    }
    const isiSisa = async () => {
      const centang = document.querySelector(`[data-testid="halo-petugas-kategori-${kategoriOpsi.data[0]!.id}"] input`) as HTMLInputElement
      centang.checked = true
      centang.dispatchEvent(new Event('change'))
      const email = form.querySelector('input[name="email"]') as HTMLInputElement
      email.value = 'a@contoh.test'
      email.dispatchEvent(new Event('input'))
      await flushPromises()
      form.dispatchEvent(new Event('submit'))
      await flushPromises()
    }
    return { w, form, ketik, isiSisa }
  }
  const opsi = () => [...document.querySelectorAll<HTMLElement>('[role="option"]')]

  it('hasil server tidak disaring ulang: kandidat yang labelnya tidak memuat teks ketikan tetap tampil dan bisa dipilih', async () => {
    svc.petugas.kandidat.mockResolvedValue({ items: [{ id_user: 31, label: 'Budi Santoso' }], peringatan: null })
    svc.petugas.tambah.mockResolvedValueOnce(petugasDaftar.data.items[0])
    const { ketik, isiSisa } = await bukaForm()
    await ketik('bsantoso')
    expect(opsi().map((o) => o.textContent?.trim())).toEqual(['Budi Santoso'])
    opsi()[0]!.click()
    await flushPromises()
    await isiSisa()
    expect(svc.petugas.tambah).toHaveBeenCalledWith(expect.objectContaining({ id_user: 31 }))
  })

  it('dua label kembar → id_user sesuai opsi yang dipilih (bukan yang pertama)', async () => {
    svc.petugas.kandidat.mockResolvedValue({ items: [{ id_user: 41, label: 'Sama' }, { id_user: 42, label: 'Sama' }], peringatan: null })
    svc.petugas.tambah.mockResolvedValueOnce(petugasDaftar.data.items[0])
    const { ketik, isiSisa } = await bukaForm()
    await ketik('sama')
    opsi()[1]!.click()
    await flushPromises()
    await isiSisa()
    expect(svc.petugas.tambah).toHaveBeenCalledWith(expect.objectContaining({ id_user: 42 }))
  })

  it('51 karakter → tanpa panggilan kandidat; mengetik tanpa memilih → "Akun petugas wajib dipilih."', async () => {
    const { ketik, isiSisa } = await bukaForm()
    await ketik('a'.repeat(51))
    expect(svc.petugas.kandidat).not.toHaveBeenCalled()
    await isiSisa()
    expect(svc.petugas.tambah).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Akun petugas wajib dipilih.')
  })
})

describe('R9: halaman kosong sesudah hapus', () => {
  it('hapus baris terakhir di halaman 2 → daftar mundur ke halaman 1', async () => {
    const k = kontakDaftar.data.items[0]!
    svc.kontak.list
      .mockResolvedValueOnce({ items: kontakDaftar.data.items, total: 21, page: 1, per_page: 20 })
      .mockResolvedValueOnce({ items: [k], total: 21, page: 2, per_page: 20 })
      .mockResolvedValueOnce({ items: [], total: 20, page: 2, per_page: 20 })
      .mockResolvedValueOnce({ items: kontakDaftar.data.items, total: 20, page: 1, per_page: 20 })
    svc.kontak.hapus.mockResolvedValueOnce(k.id)
    const w = await pasang('kontak')
    const halaman2 = [...w.findAll('nav[aria-label="Paginasi"] button')].find((b) => b.text() === '2')!
    await halaman2.trigger('click')
    await flushPromises()
    expect(svc.kontak.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
    await selectRowAction(w, `halo-kontak-actions-${k.id}`, 'delete')
    konfirmasi('Hapus')
    await flushPromises()
    await flushPromises()
    expect(svc.kontak.list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1 }))
    expect(w.find('[data-testid="halo-kontak-kosong"]').exists()).toBe(false)
  })
})

describe('R10: test admin yang mengikat perilaku', () => {
  it('filter "Tidak Aktif" → status 0 + page 1; pencarian ter-trim dengan jeda 300 ms', async () => {
    vi.useFakeTimers()
    const w = await pasang('kategori')
    // Pola spec lain (ChatbotManager): nilai UiSelect dipancarkan langsung; opsi "Tidak Aktif" bernilai '0'.
    w.findAllComponents({ name: 'UiSelect' })[0]!.vm.$emit('update:modelValue', '0')
    await flushPromises()
    expect(svc.kategori.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 0, page: 1 }))
    const cari = w.get('input[type="search"]')
    await cari.setValue('  cuti  ')
    await vi.advanceTimersByTimeAsync(299)
    expect(svc.kategori.list).not.toHaveBeenLastCalledWith(expect.objectContaining({ q: 'cuti' }))
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(svc.kategori.list).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'cuti', page: 1, status: 0 }))
  })

  it('kategori lama status 0 pada petugas: badge "(Tidak Aktif)", tercentang & berbadge di Edit, ikut PUT; dilepas → hilang dari payload', async () => {
    const p = { ...petugasDaftar.data.items[0]!, kategori: [{ id: 1, nama: 'Cuti', status: 1 }, { id: 99, nama: 'Lama', status: 0 }] }
    svc.petugas.list.mockResolvedValue({ ...petugasDaftar.data, items: [p], total: 1 })
    svc.petugas.ubah.mockResolvedValue({ ...p, peringatan: null })
    const w = await pasang('petugas')
    expect(w.get(`[data-testid="halo-petugas-row-${p.id}"] [data-status="0"]`).text()).toContain('Lama (Tidak Aktif)')
    await selectRowAction(w, `halo-petugas-actions-${p.id}`, 'edit')
    await flushPromises()
    const lama = document.querySelector('[data-testid="halo-petugas-kategori-99"]')!
    expect((lama.querySelector('input') as HTMLInputElement).checked).toBe(true)
    expect(lama.textContent).toContain('Tidak Aktif')
    const form = document.querySelector('[data-testid="halo-petugas-form"]') as HTMLFormElement
    form.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.petugas.ubah).toHaveBeenLastCalledWith(p.id, expect.objectContaining({ kategori: [1, 99] }))
    await selectRowAction(w, `halo-petugas-actions-${p.id}`, 'edit')
    await flushPromises()
    const input99 = document.querySelector('[data-testid="halo-petugas-kategori-99"] input') as HTMLInputElement
    input99.checked = false
    input99.dispatchEvent(new Event('change'))
    await flushPromises()
    ;(document.querySelector('[data-testid="halo-petugas-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.petugas.ubah).toHaveBeenLastCalledWith(p.id, expect.objectContaining({ kategori: [1] }))
  })

  it('422 kategori_dipakai saat hapus → pesan BE + daftar dimuat ulang', async () => {
    const k = kategoriDaftar.data.items.find((x) => x.bisa_hapus)!
    svc.kategori.hapus.mockRejectedValueOnce(apiError(422, { message: 'Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini.', code: 'kategori_dipakai' }))
    const w = await pasang('kategori')
    const muat0 = svc.kategori.list.mock.calls.length
    await selectRowAction(w, `halo-kategori-actions-${k.id}`, 'delete')
    konfirmasi('Hapus')
    await flushPromises()
    expect(w.get('[data-testid="halo-kategori-galat"]').text()).toBe('Kategori masih dipakai tiket atau petugas. Nonaktifkan kategori ini.')
    expect(svc.kategori.list.mock.calls.length).toBe(muat0 + 1)
  })

  it('422 petugas_duplikat (errors.id_user) → galat di kolom Akun, tanpa notice ganda', async () => {
    const bebas = petugasDaftar.data.items.find((x) => x.bisa_hapus)!
    svc.petugas.ubah.mockRejectedValueOnce(
      apiError(422, { message: 'Akun ini sudah terdaftar sebagai petugas.', code: 'petugas_duplikat', errors: { id_user: ['Akun ini sudah terdaftar sebagai petugas.'] } }),
    )
    const w = await pasang('petugas')
    await selectRowAction(w, `halo-petugas-actions-${bebas.id}`, 'edit')
    await flushPromises()
    ;(document.querySelector('[data-testid="halo-petugas-form"]') as HTMLFormElement).dispatchEvent(new Event('submit'))
    await flushPromises()
    const akun = document.querySelector('input[name="akun"]') as HTMLInputElement
    const ids = (akun.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean)
    expect(ids.map((i) => document.getElementById(i)?.textContent?.trim()).join(' ')).toContain('Akun ini sudah terdaftar sebagai petugas.')
    expect(document.querySelector('[data-testid="halo-petugas-form-galat"]')).toBeNull()
  })
})
