/**
 * DetailPegawaiPage (§4.1.6, B-20) — kartu identitas, tab riwayat (+ "Semua Menu"), form Data Umum dengan validasi Zod,
 * hak ubah per role, aksi cetak/arsip/hapus yang dipisah (anotasi Gambar 23–24), dan pesan "belum tersambung backend".
 */
import { enableAutoUnmount, flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { Role, type RoleCode } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { RIWAYAT_MENUS } from '../options'
import { PEGAWAI_ROWS } from '../services/pegawai.mock'
import DetailPegawaiPage from '../views/DetailPegawaiPage.vue'

const target = PEGAWAI_ROWS[1]
const NIP = target.nip

async function mountDetail(role: RoleCode = Role.SUPER_ADMIN, path = `/pegawai/${NIP}`, user: Parameters<typeof loginAs>[1] = {}) {
  loginAs(role, user)
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(DetailPegawaiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

/** Input/select yang dilabeli `text` (label "for" → id). */
function control(wrapper: VueWrapper, text: string) {
  const label = wrapper.findAll('label').find((l) => l.text().replace(/\s*\*$/, '').trim() === text)
  if (!label) throw new Error(`label "${text}" tidak ada`)
  return wrapper.get(`#${label.attributes('for')}`)
}

/** VeeValidate menunda validasi field ±5 ms (debounce) — tunggu timer sungguhan, bukan hanya microtask. */
const settle = async (): Promise<void> => {
  await new Promise((resolve) => setTimeout(resolve, 40))
  await flushPromises()
}

const submit = async (w: VueWrapper) => {
  await settle()
  await w.get('[data-testid="data-umum-form"]').trigger('submit')
  await settle()
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('DetailPegawaiPage — kartu identitas', () => {
  it('menampilkan nama (gelar akhir terpisah), status, NIP, jenis pegawai, tanggal lahir', async () => {
    const { wrapper } = await mountDetail()
    const header = wrapper.get('[data-testid="pegawai-header"]')

    expect(wrapper.get('[data-testid="pegawai-name"]').text()).toContain(target.nama)
    expect(header.text()).toContain(target.status_pegawai)
    expect(header.text()).toContain(NIP)
    expect(header.text()).toContain(target.jenis_pegawai)
    expect(header.text()).toMatch(/\d{1,2} \w+ \d{4}/)
  })

  it('breadcrumb Home › Daftar Pegawai › Data Pegawai', async () => {
    const { wrapper } = await mountDetail()
    expect(wrapper.get('nav[aria-label="Breadcrumb"]').findAll('li').map((li) => li.text())).toEqual(['Home', 'Daftar Pegawai', 'Data Pegawai'])
  })

  it('NIP tidak dikenal → keadaan "Pegawai tidak ditemukan" dengan tautan kembali', async () => {
    const { wrapper } = await mountDetail(Role.SUPER_ADMIN, '/pegawai/000')
    const card = wrapper.get('[data-testid="pegawai-not-found"]')
    expect(card.text()).toContain('000')
    expect(card.get('a').attributes('href')).toBe('/pegawai')
    expect(wrapper.find('[data-testid="data-umum-form"]').exists()).toBe(false)
  })
})

describe('DetailPegawaiPage — tab riwayat', () => {
  it('bawaan: tab Data Umum aktif dan form terisi data pegawai', async () => {
    const { wrapper } = await mountDetail()
    expect(wrapper.get('[role="tab"][aria-selected="true"]').text()).toBe('Data Umum')
    expect((control(wrapper, 'Nama').element as HTMLInputElement).value).toBe(target.nama)
    expect((control(wrapper, 'Provinsi Lahir').element as HTMLSelectElement).value).toBe('Jawa Barat')
  })

  it('memuat 18 menu riwayat; hanya Data Umum yang siap', () => {
    expect(RIWAYAT_MENUS).toHaveLength(18)
    expect(RIWAYAT_MENUS.filter((m) => m.ready).map((m) => m.key)).toEqual(['data-umum'])
    expect(new Set(RIWAYAT_MENUS.map((m) => m.key)).size).toBe(18)
  })

  it('memilih tab lain mengganti isi dengan keadaan "menyusul" yang menyebut task-nya, dan menyimpan ?tab=', async () => {
    const { wrapper, router } = await mountDetail()
    const tab = wrapper.findAll('[role="tab"]').find((t) => t.text() === 'Riwayat Pendidikan')
    await tab?.trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query.tab).toBe('riwayat-pendidikan')
    expect(wrapper.get('[data-testid="riwayat-placeholder"]').text()).toContain('B-10')
    expect(wrapper.find('[data-testid="data-umum-form"]').exists()).toBe(false)
  })

  it('tautan langsung ?tab=riwayat-kgb membuka tab tersebut; ?tab tidak valid kembali ke Data Umum', async () => {
    const kgb = await mountDetail(Role.SUPER_ADMIN, `/pegawai/${NIP}?tab=riwayat-kgb`)
    expect(kgb.wrapper.get('[data-testid="riwayat-placeholder"]').text()).toContain('B-09')
    kgb.wrapper.unmount()

    const bad = await mountDetail(Role.SUPER_ADMIN, `/pegawai/${NIP}?tab=ngawur`)
    expect(bad.wrapper.find('[data-testid="data-umum-form"]').exists()).toBe(true)
  })

  it('"Semua Menu": pencarian menyaring daftar dan memilih item berpindah tab', async () => {
    const { wrapper, router } = await mountDetail()
    await wrapper.get('[data-testid="semua-menu"]').trigger('click')
    await flushPromises()

    const search = document.body.querySelector<HTMLInputElement>('[data-testid="semua-menu-search"]')
    if (!search) throw new Error('kotak cari menu tidak ada')
    search.value = 'hukdis'
    search.dispatchEvent(new Event('input'))
    await flushPromises()

    const options = [...document.body.querySelectorAll<HTMLElement>('[role="option"] button')]
    expect(options.map((o) => o.textContent?.trim())).toEqual(['Riwayat Hukdis'])
    options[0].click()
    await flushPromises()

    expect(router.currentRoute.value.query.tab).toBe('riwayat-hukdis')
    expect(wrapper.get('[data-testid="riwayat-placeholder"]').text()).toContain('B-14')
  })

  it('"Semua Menu" tanpa hasil menampilkan "Menu tidak ditemukan."', async () => {
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="semua-menu"]').trigger('click')
    await flushPromises()
    const search = document.body.querySelector<HTMLInputElement>('[data-testid="semua-menu-search"]')
    if (!search) throw new Error('kotak cari menu tidak ada')
    search.value = 'zzz'
    search.dispatchEvent(new Event('input'))
    await flushPromises()
    expect(document.body.textContent).toContain('Menu tidak ditemukan.')
  })
})

describe('DetailPegawaiPage — form Data Umum', () => {
  it('nama kosong → pesan "Nama wajib diisi" dan tidak ada pesan sukses', async () => {
    const { wrapper } = await mountDetail()
    await control(wrapper, 'Nama').setValue('')
    await submit(wrapper)

    expect(wrapper.text()).toContain('Nama wajib diisi')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('NIK bukan 16 digit & email salah → pesan per field', async () => {
    const { wrapper } = await mountDetail()
    await control(wrapper, 'NIK').setValue('123')
    await control(wrapper, 'Email').setValue('bukan-email')
    await submit(wrapper)

    expect(wrapper.text()).toContain('NIK harus 16 digit')
    expect(wrapper.text()).toContain('Format email tidak valid')
    expect(control(wrapper, 'NIK').attributes('aria-invalid')).toBe('true')
  })

  it('simpan valid → header ikut berubah + pesan jujur bahwa belum tersambung backend (B-03)', async () => {
    const { wrapper } = await mountDetail()
    await control(wrapper, 'Nama').setValue('Nama Baru Contoh')
    await submit(wrapper)

    expect(wrapper.get('[role="status"]').text()).toContain('B-03')
    expect(wrapper.get('[data-testid="pegawai-name"]').text()).toContain('Nama Baru Contoh')
  })

  it('"Batalkan Perubahan" mengembalikan nilai awal', async () => {
    const { wrapper } = await mountDetail()
    await control(wrapper, 'Nama').setValue('Diubah')
    await wrapper.get('[data-testid="data-umum-cancel"]').trigger('click')
    await flushPromises()
    expect((control(wrapper, 'Nama').element as HTMLInputElement).value).toBe(target.nama)
  })

  it('Kab./Kota bergantung Provinsi: opsi berganti dan nilai yang tak berlaku dikosongkan', async () => {
    const { wrapper } = await mountDetail()
    const kota = control(wrapper, 'Kab. / Kota Lahir')
    expect((kota.element as HTMLSelectElement).value).toBe('Kota Bandung')

    await control(wrapper, 'Provinsi Lahir').setValue('Bali')
    await flushPromises()
    expect(kota.findAll('option').map((o) => o.text())).toContain('Kota Denpasar')
    expect((kota.element as HTMLSelectElement).value).toBe('')
  })

  it('berkas unggahan: ekstensi terlarang & terlalu besar ditolak dengan pesan', async () => {
    const { wrapper } = await mountDetail()
    const upload = wrapper.get('input[type="file"]')
    const setFile = async (file: File) => {
      Object.defineProperty(upload.element, 'files', { value: [file], configurable: true })
      await upload.trigger('change')
    }

    await setFile(new File(['x'], 'virus.exe'))
    expect(wrapper.text()).toContain('Jenis file .exe tidak diperbolehkan.')

    await setFile(new File([new Uint8Array(6 * 1024 * 1024)], 'besar.png'))
    expect(wrapper.text()).toContain('Ukuran file melebihi 5 MB.')

    await setFile(new File(['ok'], 'bagus.png'))
    expect(wrapper.text()).toContain('bagus.png')
  })
})

describe('DetailPegawaiPage — hak akses per role', () => {
  it.each([Role.SUPER_ADMIN, Role.ADMIN_SATKER])('role %i dapat mengubah biodata', async (role) => {
    const { wrapper } = await mountDetail(role)
    expect(wrapper.find('[data-testid="data-umum-save"]').exists()).toBe(true)
    expect(control(wrapper, 'Nama').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-testid="arsip-add"]').exists()).toBe(true)
  })

  it.each([Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PIMPINAN])('role %i hanya melihat: form nonaktif, tanpa Simpan/Tambah Arsip/Aksi', async (role) => {
    const { wrapper } = await mountDetail(role)
    expect(wrapper.find('[data-testid="data-umum-save"]').exists()).toBe(false)
    expect(control(wrapper, 'Nama').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="arsip-add"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="arsip-card"]').text()).not.toContain('Aksi')
  })

  it('Pegawai (role 2) dapat mengubah HANYA NIP-nya sendiri', async () => {
    const own = await mountDetail(Role.PEGAWAI, `/pegawai/${NIP}`, { nip: NIP })
    expect(own.wrapper.find('[data-testid="data-umum-save"]').exists()).toBe(true)
    own.wrapper.unmount()

    const other = await mountDetail(Role.PEGAWAI, `/pegawai/${NIP}`, { nip: '999999999999999999' })
    expect(other.wrapper.find('[data-testid="data-umum-save"]').exists()).toBe(false)
  })

  it('Cetak: role 1,2,3,4,5 ya; PTT/PPPK/Pimpinan tidak', async () => {
    for (const role of [Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI]) {
      const { wrapper } = await mountDetail(role)
      expect(wrapper.find('[data-testid="btn-cetak"]').exists(), `role ${role}`).toBe(true)
      wrapper.unmount()
    }
    for (const role of [Role.PTT, Role.PPPK, Role.PIMPINAN]) {
      const { wrapper } = await mountDetail(role)
      expect(wrapper.find('[data-testid="btn-cetak"]').exists(), `role ${role}`).toBe(false)
      wrapper.unmount()
    }
  })

  it('menu "⋯" (Hapus Pegawai) hanya untuk Super Admin', async () => {
    const admin = await mountDetail(Role.SUPER_ADMIN)
    expect(admin.wrapper.find('[data-testid="btn-more"]').exists()).toBe(true)
    admin.wrapper.unmount()

    const satker = await mountDetail(Role.ADMIN_SATKER)
    expect(satker.wrapper.find('[data-testid="btn-more"]').exists()).toBe(false)
  })
})

describe('DetailPegawaiPage — aksi terpisah (anotasi Gambar 23–24)', () => {
  it('Cetak berisi "Cetak Data Umum" dan "Cetak DRH"; memilihnya memberi pesan B-20', async () => {
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="btn-cetak"]').trigger('click')
    await flushPromises()

    const items = [...document.body.querySelectorAll<HTMLElement>('[role="menuitem"]')]
    expect(items.map((i) => i.textContent?.trim())).toEqual(['Cetak Data Umum', 'Cetak DRH'])
    document.body.querySelector<HTMLElement>('[data-action="cetak-drh"]')?.click()
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('DRH')
    expect(wrapper.get('[role="status"]').text()).toContain('B-20')
  })

  it('"Arsip Kepegawaian" adalah tombol tersendiri (bukan bagian menu Cetak)', async () => {
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="btn-arsip"]').trigger('click')
    expect(wrapper.get('[role="status"]').text()).toContain('B-18')
  })

  it('Hapus Pegawai: ⋯ → konfirmasi → pesan B-05, data tidak hilang', async () => {
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="btn-more"]').trigger('click')
    await flushPromises()
    document.body.querySelector<HTMLElement>('[data-action="hapus-pegawai"]')?.click()
    await flushPromises()

    expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain(NIP)
    const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')
    confirm?.click()
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('B-05')
    expect(wrapper.find('[data-testid="pegawai-header"]').exists()).toBe(true)
  })
})

describe('DetailPegawaiPage — arsip', () => {
  it('menampilkan 4 arsip; pencarian menyaring; kosong → "Belum ada arsip."', async () => {
    const { wrapper } = await mountDetail()
    const card = wrapper.get('[data-testid="arsip-card"]')
    expect(card.findAll('tbody tr')).toHaveLength(4)

    await card.get('input[type="search"]').setValue('kartu')
    expect(card.findAll('tbody tr').map((r) => r.text())[0]).toContain('Kartu Keluarga')
    await card.get('input[type="search"]').setValue('zzz')
    expect(card.text()).toContain('Belum ada arsip.')
  })

  it('aksi baris lewat ⋮ berisi Edit lalu Hapus (merah); memilih memberi pesan B-18', async () => {
    const { wrapper } = await mountDetail()
    const row = wrapper.get('[data-testid^="arsip-row-"]')
    const id = row.attributes('data-testid')?.replace('arsip-row-', '')
    const testid = `arsip-actions-${id}`

    const actions = await rowMenuActions(wrapper, testid)
    expect(actions.map((a) => a.label)).toEqual(['Edit', 'Hapus'])
    expect(actions[1].danger).toBe(true)

    await selectRowAction(wrapper, testid, 'edit')
    expect(wrapper.get('[role="status"]').text()).toContain('B-18')
  })

  it('"Tambah Arsip" dan tombol folder memberi pesan belum tersambung', async () => {
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="arsip-add"]').trigger('click')
    expect(wrapper.get('[role="status"]').text()).toContain('B-18')

    await wrapper.get('button[aria-label="Buka arsip KTP"]').trigger('click')
    expect(wrapper.get('[role="status"]').text()).toContain('KTP')
  })
})
