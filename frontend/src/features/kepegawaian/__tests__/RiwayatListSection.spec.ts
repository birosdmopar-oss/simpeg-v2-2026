/**
 * RiwayatListSection / RiwayatTabHost — tab riwayat engine dari API dengan hak aksi HANYA dari descriptor:
 * tombol tambah (can_create), menu ⋮ Edit → Setujui/Tolak (can_process, baris Menunggu) → Lampiran → Hapus
 * (can_delete), proses lewat ApprovalDialog, galat 422 (errors.<kolom>, errors["berkas.<id>"]) di form, lampiran wajib
 * saat tambah dan saat ubah bila baris belum punya lampiran, isian bersyarat & berjenjang, isian status untuk role
 * pemilih status, dan keadaan galat 403/501. Tab non-engine (lkh) → "belum tersedia".
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../riwayat/riwayat.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../riwayat/riwayat.service')>()
  return {
    ...actual,
    riwayatService: { tabs: vi.fn(), list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn() },
  }
})
vi.mock('../services/lampiran.service', () => ({
  lampiranService: { list: vi.fn(), upload: vi.fn(), download: vi.fn(), remove: vi.fn() },
}))
vi.mock('@/features/master-data/services/master.service', () => ({
  masterService: { options: vi.fn() },
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type RoleCode, type User } from '@/features/auth/types'
import { masterService } from '@/features/master-data/services/master.service'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import alamat from '../riwayat/jenis/alamat'
import diklat from '../riwayat/jenis/diklat'
import hukdis from '../riwayat/jenis/hukdis'
import keluarga from '../riwayat/jenis/keluarga'
import seminar from '../riwayat/jenis/seminar'
import kp from '../riwayat/jenis/kp'
import lkh from '../riwayat/jenis/lkh'
import pendidikan from '../riwayat/jenis/pendidikan'
import { D, R, T, type RiwayatJenisConfig } from '../riwayat/riwayat.config'
import RiwayatTabHost from '../riwayat/RiwayatTabHost.vue'
import { riwayatService } from '../riwayat/riwayat.service'
import { lampiranService } from '../services/lampiran.service'
import type { RiwayatTabDescriptor } from '../types'

import { apiError, descriptor, NIP, pendidikanRow } from './fixtures'

const svc = vi.mocked(riwayatService)
const lampiranSvc = vi.mocked(lampiranService)
const options = vi.mocked(masterService.options)

/**
 * Konfigurasi KHUSUS TEST untuk perilaku generik mesin (isian sedikit, kolom = fixture pendidikanRow); aturan nyata per
 * jenis diuji lewat berkas `jenis/<slug>.ts` di bawah.
 */
const uji: RiwayatJenisConfig = {
  ...pendidikan,
  fields: [R('id_jenjang_pendidikan', 'Jenjang Pendidikan', 'jenjang-pendidikan', { required: true }), T('no_ijazah', 'Nomor Ijazah'), D('tgl_lulus', 'Tanggal Lulus', { required: true })],
  lampiran: [],
  pilihStatusSaatUbah: undefined,
}
const ujiBerlampiran: RiwayatJenisConfig = { ...uji, lampiran: [{ id_riwayat: 14, label: 'Ijazah', wajib: true, batas_mb: 5, ekstensi: ['pdf'] }] }

function akun(level: RoleCode): User {
  return {
    id_pengguna: 1,
    nip: null,
    username: 'akun',
    name: 'Akun Uji',
    email: null,
    user_level: level,
    id_unit: null,
    id_satker: null,
    status: '1',
    last_login_at: null,
    created_at: null,
    updated_at: null,
  }
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  setActivePinia(createPinia())
  useAuthStore().setSession(akun(Role.SUPER_ADMIN))
  for (const fn of Object.values(svc)) fn.mockReset()
  for (const fn of Object.values(lampiranSvc)) fn.mockReset()
  options.mockReset()
  options.mockImplementation(async (entity: string, parent?: string | null) => [{ id: parent ? `${parent}1` : '7', nama: `${entity}${parent ? `-${parent}` : ''}`, parent: parent ?? null }])
  lampiranSvc.list.mockResolvedValue([])
  svc.list.mockResolvedValue([pendidikanRow(1, { status: 1 }), pendidikanRow(2, { status: 0 }), pendidikanRow(3, { status: 2, reason_note: 'Ijazah buram' })])
})

async function mountTab(desc: Partial<RiwayatTabDescriptor> = {}, config: RiwayatJenisConfig = uji) {
  const wrapper = mount(RiwayatTabHost, {
    props: { nip: NIP, descriptor: descriptor(config.jenis, config.title, desc), config },
    attachTo: document.body,
  })
  await flushPromises()
  return wrapper
}

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const field = (name: string) => q<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(`[data-field="${name}"] select, [data-field="${name}"] input, [data-field="${name}"] textarea, select[data-field="${name}"], input[data-field="${name}"]`)

async function setInput(name: string, value: string): Promise<void> {
  const el = field(name)
  if (!el) throw new Error(`isian ${name} tidak ada`)
  el.value = value
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await flushPromises()
}

async function chooseFile(idRiwayat: number, file: File): Promise<void> {
  const input = q<HTMLInputElement>(`[data-testid="riwayat-berkas-${idRiwayat}"] input[type="file"]`)
  if (!input) throw new Error(`isian berkas ${idRiwayat} tidak ada`)
  Object.defineProperty(input, 'files', { value: [file], configurable: true })
  input.dispatchEvent(new Event('change'))
  await flushPromises()
}

async function submitForm(): Promise<void> {
  q('[data-testid="riwayat-form"]')?.dispatchEvent(new Event('submit'))
  await flushPromises()
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

async function openCreate(wrapper: Awaited<ReturnType<typeof mountTab>>): Promise<void> {
  await wrapper.get('[data-testid="riwayat-add"]').trigger('click')
  await flushPromises()
}

describe('RiwayatListSection — data & tampilan', () => {
  it('memuat GET riwayat/{jenis}: kolom konfigurasi + Status + Aksi; badge status; alasan tolak tampil', async () => {
    const wrapper = await mountTab()
    expect(svc.list).toHaveBeenCalledWith(NIP, 'pendidikan')
    expect(wrapper.findAll('thead th').map((th) => th.text())).toEqual(['Jenjang', 'Jurusan', 'Institusi', 'Tanggal Lulus', 'Status', 'Aksi'])
    expect(wrapper.get('[data-testid="riwayat-row-1"]').text()).toContain('30 Agustus 2012')
    expect(wrapper.get('[data-testid="riwayat-row-1"] [data-testid="status-badge"]').text()).toBe('Disetujui')
    expect(wrapper.get('[data-testid="riwayat-row-2"] [data-testid="status-badge"]').text()).toBe('Menunggu')
    expect(wrapper.get('[data-testid="riwayat-row-3"]').text()).toContain('Ijazah buram')
  })

  it('501 (stub) → keterangan "belum tersedia", bukan tabel kosong', async () => {
    svc.list.mockRejectedValue(apiError(501))
    const wrapper = await mountTab()
    expect(wrapper.get('[data-testid="riwayat-failure"]').text()).toBe('Fitur ini belum tersedia di server.')
    expect(wrapper.find('[data-testid="riwayat-table"]').exists()).toBe(false)
  })

  it('403 → pesan tidak berhak', async () => {
    svc.list.mockRejectedValue(apiError(403))
    const wrapper = await mountTab()
    expect(wrapper.get('[data-testid="riwayat-failure"]').text()).toBe('Anda tidak berhak mengakses data ini.')
  })

  it('tab non-engine (lkh) → "belum tersedia" tanpa memanggil API riwayat', async () => {
    const wrapper = await mountTab({}, lkh)
    expect(wrapper.get('[data-testid="riwayat-belum-tersedia"]').text()).toContain('Laporan Kerja Harian')
    expect(svc.list).not.toHaveBeenCalled()
  })

  it('jenis tanpa isian: Tambah/Edit tidak tampil walau descriptor mengizinkan', async () => {
    const wrapper = await mountTab({ can_process: true }, { ...uji, fields: [] })
    expect(wrapper.find('[data-testid="riwayat-add"]').exists()).toBe(false)
    expect((await rowMenuActions(wrapper, 'riwayat-actions-2')).map((i) => i.key)).toEqual(['setujui', 'tolak', 'hapus'])
  })
})

describe('RiwayatListSection — hak dari descriptor & menu ⋮', () => {
  it('semua hak: menu ⋮ Edit → Setujui → Tolak → Hapus pada baris Menunggu; label aksesibel memuat nama baris', async () => {
    const wrapper = await mountTab({ can_process: true })
    expect(wrapper.find('[data-testid="riwayat-add"]').exists()).toBe(true)
    const items = await rowMenuActions(wrapper, 'riwayat-actions-2')
    expect(items.map((i) => i.label)).toEqual(['Edit', 'Setujui', 'Tolak', 'Hapus'])
    expect(items.at(-1)?.danger).toBe(true)
    expect(wrapper.get('[data-testid="riwayat-actions-2"]').attributes('aria-label')).toBe('Aksi untuk S1')
  })

  it('jenis berlampiran: item Lampiran sesudah Setujui/Tolak, sebelum Hapus', async () => {
    const wrapper = await mountTab({ can_process: true }, ujiBerlampiran)
    expect((await rowMenuActions(wrapper, 'riwayat-actions-2')).map((i) => i.label)).toEqual(['Edit', 'Setujui', 'Tolak', 'Lampiran', 'Hapus'])
  })

  it('baris Disetujui: Setujui/Tolak tidak tampil', async () => {
    const wrapper = await mountTab({ can_process: true })
    expect((await rowMenuActions(wrapper, 'riwayat-actions-1')).map((i) => i.key)).toEqual(['edit', 'hapus'])
  })

  it('tanpa hak apa pun: tanpa tombol tambah dan tanpa kolom Aksi (jenis tanpa lampiran)', async () => {
    const wrapper = await mountTab({ can_create: false, can_edit: false, can_delete: false, can_process: false })
    expect(wrapper.find('[data-testid="riwayat-add"]').exists()).toBe(false)
    expect(wrapper.findAll('thead th').map((th) => th.text())).not.toContain('Aksi')
    expect(wrapper.find('[data-testid="riwayat-row-1"]').exists()).toBe(true)
  })

  it('lihat saja pada jenis berlampiran: menu hanya Lampiran (unduh arsip)', async () => {
    const wrapper = await mountTab({ can_create: false, can_edit: false, can_delete: false, can_process: false }, ujiBerlampiran)
    expect((await rowMenuActions(wrapper, 'riwayat-actions-1')).map((i) => i.key)).toEqual(['lampiran'])
  })

  it('hanya can_process: menu berisi Setujui/Tolak saja', async () => {
    const wrapper = await mountTab({ can_create: false, can_edit: false, can_delete: false, can_process: true })
    expect((await rowMenuActions(wrapper, 'riwayat-actions-2')).map((i) => i.key)).toEqual(['setujui', 'tolak'])
  })
})

describe('RiwayatListSection — proses (Setujui/Tolak)', () => {
  it('Tolak: alasan wajib; terisi → POST process { aksi: tolak, reason_note } lalu muat ulang', async () => {
    svc.process.mockResolvedValue({})
    const wrapper = await mountTab({ can_process: true })
    await selectRowAction(wrapper, 'riwayat-actions-2', 'tolak')
    expect(q('[data-testid="approval-dialog"]')?.getAttribute('data-mode')).toBe('tolak')

    q('[data-testid="approval-confirm"]')?.click()
    await flushPromises()
    expect(svc.process).not.toHaveBeenCalled()

    const textarea = q<HTMLTextAreaElement>('[data-testid="approval-reason"]')!
    textarea.value = 'Ijazah tidak terbaca'
    textarea.dispatchEvent(new Event('input'))
    q('[data-testid="approval-confirm"]')?.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(NIP, 'pendidikan', '2', { aksi: 'tolak', reason_note: 'Ijazah tidak terbaca' })
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[data-testid="riwayat-notice"]').text()).toContain('ditolak')
  })

  it('galat server saat proses (422 reason_note) tampil di dialog', async () => {
    svc.process.mockRejectedValue(apiError(422, { reason_note: ['Alasan wajib diisi saat menolak.'] }))
    const wrapper = await mountTab({ can_process: true })
    await selectRowAction(wrapper, 'riwayat-actions-2', 'setujui')
    q('[data-testid="approval-confirm"]')?.click()
    await flushPromises()
    expect(q('[data-testid="approval-error"]')?.textContent).toContain('Alasan wajib diisi saat menolak.')
  })
})

describe('RiwayatListSection — tambah/ubah/hapus', () => {
  const pdf = new File(['%PDF'], 'ijazah.pdf', { type: 'application/pdf' })

  it('tambah tanpa lampiran wajib (kode 14) → pesan, tidak memanggil API', async () => {
    const wrapper = await mountTab({}, ujiBerlampiran)
    await openCreate(wrapper)
    await setInput('id_jenjang_pendidikan', '7')
    await setInput('tgl_lulus', '2012-08-30')
    await submitForm()
    // Validasi VeeValidate/Zod asinkron: tunggu hasilnya, bukan jumlah tick tetap (runner CI lebih lambat).
    await vi.waitFor(() => expect(q('[data-testid="riwayat-berkas-error-14"]')?.textContent).toContain('Ijazah wajib diunggah.'))
    expect(svc.create).not.toHaveBeenCalled()
  })

  it('tambah lengkap → create(nip, jenis, fields, values, { 14: File }); galat 422 dipetakan ke kolom & berkas', async () => {
    svc.create.mockRejectedValueOnce(apiError(422, { tgl_lulus: ['Tanggal lulus wajib diisi.'], 'berkas.14': ['Berkas harus pdf.'] }))
    const wrapper = await mountTab({}, ujiBerlampiran)
    await openCreate(wrapper)
    await setInput('id_jenjang_pendidikan', '7')
    await setInput('tgl_lulus', '2012-08-30')
    await chooseFile(14, pdf)
    await submitForm()

    await vi.waitFor(() => expect(svc.create).toHaveBeenCalledTimes(1))
    await flushPromises()
    const [nip, jenis, fields, values, berkas] = svc.create.mock.calls[0]
    expect([nip, jenis, fields]).toEqual([NIP, 'pendidikan', uji.fields])
    expect(values).toMatchObject({ id_jenjang_pendidikan: '7', tgl_lulus: '2012-08-30' })
    expect(berkas).toEqual({ 14: pdf })
    expect(q('[data-testid="riwayat-dialog"]')?.textContent).toContain('Tanggal lulus wajib diisi.')
    expect(q('[data-testid="riwayat-berkas-error-14"]')?.textContent).toContain('Berkas harus pdf.')
  })

  it('422 berkas.<id> untuk kode yang tidak ada di form → tampil di notice form, dialog tetap terbuka', async () => {
    svc.create.mockRejectedValueOnce(apiError(422, { 'berkas.14': ['Lampiran wajib diunggah.'] }))
    const wrapper = await mountTab()
    await openCreate(wrapper)
    expect(q('[data-testid="riwayat-berkas-14"]')).toBeNull()
    await setInput('id_jenjang_pendidikan', '7')
    await setInput('tgl_lulus', '2012-08-30')
    await submitForm()

    await vi.waitFor(() => expect(svc.create).toHaveBeenCalledTimes(1))
    await vi.waitFor(() => expect(q('[data-testid="riwayat-general-error"]')?.textContent).toContain('Lampiran wajib diunggah.'))
    expect(svc.create.mock.calls[0][4]).toEqual({}) // tanpa berkas → JSON
    expect(q('[data-testid="riwayat-dialog"]')).not.toBeNull()
  })

  it('edit: dialog terisi nilai baris (kolom DDL); simpan → update(…, id, …); lampiran wajib yang sudah ada tidak diwajibkan lagi', async () => {
    svc.update.mockResolvedValue({})
    lampiranSvc.list.mockResolvedValue([{ id_attachment: 5 } as never])
    const wrapper = await mountTab({}, ujiBerlampiran)
    await selectRowAction(wrapper, 'riwayat-actions-1', 'edit')
    await flushPromises()
    expect(lampiranSvc.list).toHaveBeenCalledWith(NIP, { id_riwayat: 14, id_entri: '1' })
    expect(field('no_ijazah')?.value).toBe('IJZ-1')
    expect(field('status')).toBeNull() // jenis tanpa pilihStatusSaatUbah
    await submitForm()
    await vi.waitFor(() => expect(svc.update).toHaveBeenCalled())
    await flushPromises()
    expect(svc.update).toHaveBeenCalledWith(NIP, 'pendidikan', '1', uji.fields, expect.objectContaining({ no_ijazah: 'IJZ-1' }), {})
    expect(wrapper.get('[data-testid="riwayat-notice"]').text()).toContain('tersimpan')
  })

  it('edit baris tanpa lampiran wajib (data impor) → berkas wajib diunggah (legacy vp())', async () => {
    lampiranSvc.list.mockResolvedValue([])
    const wrapper = await mountTab({}, ujiBerlampiran)
    await selectRowAction(wrapper, 'riwayat-actions-1', 'edit')
    await flushPromises()
    await submitForm()
    await vi.waitFor(() => expect(q('[data-testid="riwayat-berkas-error-14"]')?.textContent).toContain('Ijazah wajib diunggah.'))
    expect(svc.update).not.toHaveBeenCalled()
  })

  it('hapus: konfirmasi → DELETE; galat 403 tampil sebagai pesan', async () => {
    svc.remove.mockRejectedValue(apiError(403))
    const wrapper = await mountTab()
    await selectRowAction(wrapper, 'riwayat-actions-3', 'hapus')
    const confirm = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')
    confirm?.click()
    await flushPromises()
    expect(svc.remove).toHaveBeenCalledWith(NIP, 'pendidikan', '3')
    expect(wrapper.get('[data-testid="riwayat-notice"]').text()).toBe('Anda tidak berhak mengakses data ini.')
  })

  it('Lampiran: dialog berisi ArsipTable per kode (GET lampiran baris itu)', async () => {
    const wrapper = await mountTab({}, pendidikan)
    await selectRowAction(wrapper, 'riwayat-actions-2', 'lampiran')
    await flushPromises()
    expect(q('[data-testid="riwayat-lampiran-dialog"]')?.textContent).toContain('Lampiran S1')
    for (const kode of [14, 39, 40]) {
      expect(q(`[data-testid="arsip-${kode}-2"]`)).not.toBeNull()
      expect(lampiranSvc.list).toHaveBeenCalledWith(NIP, { id_riwayat: kode, id_entri: '2' })
    }
  })
})

describe('RiwayatListSection — isian berjenjang, bersyarat, dan status', () => {
  it('ref berjenjang (hukdis): jenis memuat opsi ?parent=tingkat; ganti tingkat mengosongkan jenis', async () => {
    svc.list.mockResolvedValue([])
    const wrapper = await mountTab({}, hukdis)
    await openCreate(wrapper)
    expect(options).toHaveBeenCalledWith('tingkat-hukdis', null)
    expect(options).not.toHaveBeenCalledWith('jenis-hukdis', expect.anything())

    await setInput('id_tingkat_hukdis', '7')
    expect(options).toHaveBeenCalledWith('jenis-hukdis', '7')
    await setInput('id_jenis_hukdis', '71')
    expect(field('id_jenis_hukdis')?.value).toBe('71')

    await setInput('id_tingkat_hukdis', '')
    expect(field('id_jenis_hukdis')?.value).toBe('')
    expect(q('[data-field="akhir_hukdis"]')?.textContent).toContain('Kosongkan untuk dihitung otomatis')
  })

  it('isian bersyarat (pendidikan): bidang/jurusan hanya untuk jenjang di atas SMA; tersembunyi dikirim kosong', async () => {
    options.mockImplementation(async (entity: string, parent?: string | null) =>
      entity === 'jenjang-pendidikan'
        ? [{ id: '3', nama: 'SMA', parent: null }, { id: '7', nama: 'S1', parent: null }]
        : [{ id: parent ? `${parent}1` : '7', nama: entity, parent: parent ?? null }],
    )
    const wrapper = await mountTab({}, pendidikan)
    await openCreate(wrapper)
    expect(field('id_bidang_pendidikan')).toBeNull() // jenjang belum dipilih

    await setInput('id_jenjang_pendidikan', '7')
    expect(field('id_bidang_pendidikan')).not.toBeNull()
    expect(field('nem')).toBeNull()
    await submitForm()
    await vi.waitFor(() => expect(q('[data-field="id_bidang_pendidikan"]')?.textContent).toContain('Bidang Pendidikan wajib diisi'))

    await setInput('id_bidang_pendidikan', '7')
    await setInput('id_jurusan_pendidikan', '71')
    await setInput('ipk', '3.5')
    // Jenjang SMA (kode 3): bidang/jurusan/ipk tersembunyi; NEM tampil.
    await setInput('id_jenjang_pendidikan', '3')
    expect(field('id_bidang_pendidikan')).toBeNull()
    expect(field('nem')).not.toBeNull()

    for (const [name, value] of [['institusi_pendidikan', 'SMA 1'], ['no_ijazah', 'IJZ'], ['tgl_lulus', '2008-06-30']]) await setInput(name, value)
    await chooseFile(14, new File(['%PDF'], 'ijazah.pdf', { type: 'application/pdf' }))
    await submitForm()
    await vi.waitFor(() => expect(svc.create).toHaveBeenCalledTimes(1))
    expect(svc.create.mock.calls[0][3]).toMatchObject({ id_jenjang_pendidikan: '3', id_bidang_pendidikan: '', id_jurusan_pendidikan: '', ipk: '' })
  })

  it('role 1 edit KP: isian status (bawaan Disetujui), alasan wajib bila Ditolak; 422 errors.status dipetakan ke isian', async () => {
    svc.list.mockResolvedValue([{ id_riwayat_kp: 9, status: 0, id_jenis_kp: 7, id_pangkat: 7, tmtsk: '2015-04-01', no_sk: 'SK', tgl_sk: '2015-04-01', gaji_pokok: 1, mker_th: 1, mker_bl: 1, gol_ruang: 'III/a' }])
    lampiranSvc.list.mockResolvedValue([{ id_attachment: 1 } as never])
    svc.update.mockRejectedValueOnce(apiError(422, { status: ['Status tidak valid.'] }))
    const wrapper = await mountTab({}, kp)
    await selectRowAction(wrapper, 'riwayat-actions-9', 'edit')
    await flushPromises()
    expect(field('status')?.value).toBe('1')
    expect([...(field('status') as HTMLSelectElement).options].map((o) => o.value)).toEqual(['', '1', '2', '0'])

    expect(field('reason_note')).toBeNull() // alasan hanya untuk Ditolak
    await setInput('status', '2')
    expect(field('reason_note')).not.toBeNull()
    await submitForm()
    await vi.waitFor(() => expect(q('[data-field="reason_note"]')?.textContent).toContain('Alasan Penolakan wajib diisi'))
    expect(svc.update).not.toHaveBeenCalled()

    await setInput('reason_note', 'SK keliru')
    await submitForm()
    await vi.waitFor(() => expect(svc.update).toHaveBeenCalledTimes(1))
    const [, , , fields, values] = svc.update.mock.calls[0]
    expect(fields.map((f) => f.name)).toEqual(expect.arrayContaining(['status', 'reason_note']))
    expect(values).toMatchObject({ status: '2', reason_note: 'SK keliru', no_sk: 'SK' })
    await vi.waitFor(() => expect(q('[data-field="status"]')?.textContent).toContain('Status tidak valid.'))
  })

  it('role 3 edit KP: tanpa isian status', async () => {
    useAuthStore().setSession(akun(Role.ADMIN_SATKER))
    svc.list.mockResolvedValue([{ id_riwayat_kp: 9, status: 0, tmtsk: '2015-04-01' }])
    const wrapper = await mountTab({}, kp)
    await selectRowAction(wrapper, 'riwayat-actions-9', 'edit')
    await flushPromises()
    expect(field('status')).toBeNull()
    expect(field('no_sk')).not.toBeNull()
  })
})

describe('RiwayatListSection — kunci baris Disetujui & isian hitung', () => {
  it('role 3 pada KP: Hapus baris Disetujui tampil nonaktif; baris Menunggu tetap bisa dihapus (kunciHapusDisetujuiRole)', async () => {
    useAuthStore().setSession(akun(Role.ADMIN_SATKER))
    svc.list.mockResolvedValue([{ id_riwayat_kp: 1, status: 1, tmtsk: '2015-04-01' }, { id_riwayat_kp: 2, status: 0, tmtsk: '2016-04-01' }])
    const wrapper = await mountTab({ can_process: true }, kp)
    const disetujui = await rowMenuActions(wrapper, 'riwayat-actions-1')
    expect(disetujui.find((i) => i.key === 'hapus')?.disabled).toBe(true)
    expect(disetujui.find((i) => i.key === 'edit')?.disabled).toBe(false)
    expect((await rowMenuActions(wrapper, 'riwayat-actions-2')).find((i) => i.key === 'hapus')?.disabled).toBe(false)
  })

  it('role 1 pada KP: Hapus baris Disetujui aktif', async () => {
    svc.list.mockResolvedValue([{ id_riwayat_kp: 1, status: 1, tmtsk: '2015-04-01' }])
    const wrapper = await mountTab({}, kp)
    expect((await rowMenuActions(wrapper, 'riwayat-actions-1')).find((i) => i.key === 'hapus')?.disabled).toBe(false)
  })

  it('pegawai (role 2) pada pendidikan: Edit & Hapus baris Disetujui nonaktif (kunciBarisDisetujui)', async () => {
    useAuthStore().setSession(akun(Role.PEGAWAI))
    const wrapper = await mountTab({}, pendidikan)
    const items = await rowMenuActions(wrapper, 'riwayat-actions-1')
    expect(items.filter((i) => ['edit', 'hapus'].includes(i.key)).map((i) => i.disabled)).toEqual([true, true])
    expect((await rowMenuActions(wrapper, 'riwayat-actions-2')).find((i) => i.key === 'edit')?.disabled).toBe(false)
  })

  it('hukdis edit: mengubah TMT atau jenis mengosongkan akhir & masa (dihitung ulang server), kecuali diisi lagi', async () => {
    svc.update.mockResolvedValue({})
    lampiranSvc.list.mockResolvedValue([{ id_attachment: 1 } as never])
    svc.list.mockResolvedValue([
      { id_riwayat_hukdis: 4, status: 1, id_tingkat_hukdis: 7, id_jenis_hukdis: 71, id_pangkat: 7, tmtsk: '2026-01-01', tgl_sk: '2026-01-01', no_sk: 'SK', masa_hukuman: '1 Tahun', akhir_hukdis: '2027-01-01', aturan_dilanggar: 'P', alasan_hukuman: 'A' },
    ])
    const wrapper = await mountTab({}, hukdis)
    await selectRowAction(wrapper, 'riwayat-actions-4', 'edit')
    await flushPromises()
    expect([field('akhir_hukdis')?.value, field('masa_hukuman')?.value]).toEqual(['2027-01-01', '1 Tahun'])

    await setInput('tmtsk', '2026-02-01')
    expect([field('akhir_hukdis')?.value, field('masa_hukuman')?.value]).toEqual(['', ''])

    await setInput('akhir_hukdis', '2026-08-01') // diisi lagi manual → dipertahankan
    await submitForm()
    await vi.waitFor(() => expect(svc.update).toHaveBeenCalledTimes(1))
    expect(svc.update.mock.calls[0][4]).toMatchObject({ tmtsk: '2026-02-01', akhir_hukdis: '2026-08-01', masa_hukuman: '' })
  })

  it('hukdis edit: mengganti jenis (atau tingkat yang mengosongkan jenis) juga mengosongkan akhir', async () => {
    lampiranSvc.list.mockResolvedValue([{ id_attachment: 1 } as never])
    options.mockImplementation(async (entity: string, parent?: string | null) =>
      entity === 'jenis-hukdis' ? [{ id: '71', nama: 'J1', parent: '7' }, { id: '72', nama: 'J2', parent: '7' }] : [{ id: '7', nama: entity, parent: parent ?? null }],
    )
    svc.list.mockResolvedValue([{ id_riwayat_hukdis: 4, status: 1, id_tingkat_hukdis: 7, id_jenis_hukdis: 71, tmtsk: '2026-01-01', akhir_hukdis: '2027-01-01' }])
    const wrapper = await mountTab({}, hukdis)
    await selectRowAction(wrapper, 'riwayat-actions-4', 'edit')
    await flushPromises()
    await setInput('id_jenis_hukdis', '72')
    expect(field('akhir_hukdis')?.value).toBe('')
  })
})

describe('RiwayatListSection — opsi ref MAKE-007 (filter, induk tetap, LAIN-LAIN, saran)', () => {
  beforeEach(() => svc.list.mockResolvedValue([]))

  it('diklat: opsi nama diklat disaring jenis_diklat dan dimuat ulang + dikosongkan saat jenis berganti; sub-group = group 2', async () => {
    options.mockImplementation(async (entity: string, parent?: string | null, filters?: Record<string, string>) =>
      entity === 'diklat' ? [{ id: `d${filters?.jenis_diklat}`, nama: `Diklat ${filters?.jenis_diklat}`, parent: null }] : [{ id: 's1', nama: `${entity}-${parent}`, parent: parent ?? null }],
    )
    const wrapper = await mountTab({}, diklat)
    await openCreate(wrapper)
    expect(field('id_diklat')).toBeNull() // jenis belum dipilih

    await setInput('jenis_diklat', '1')
    await vi.waitFor(() => expect(options).toHaveBeenCalledWith('diklat', null, { jenis_diklat: '1' }))
    await flushPromises()
    await setInput('id_diklat', 'd1')
    expect(field('id_diklat')?.value).toBe('d1')

    await setInput('jenis_diklat', '2')
    await vi.waitFor(() => expect(options).toHaveBeenCalledWith('diklat', null, { jenis_diklat: '2' }))
    await flushPromises()
    expect(field('id_diklat')?.value).toBe('')

    await setInput('jenis_diklat', '3')
    expect(options).toHaveBeenCalledWith('sub-group-jabatan', '2', undefined)
    expect(field('id_sub_group_jabatan')).not.toBeNull()
    expect(field('id_diklat')).toBeNull()
  })

  it('alamat: opsi LAIN-LAIN ditambahkan; memilihnya memunculkan isian lainnya; KTP tidak wajib untuk kantor', async () => {
    const wrapper = await mountTab({}, alamat)
    await openCreate(wrapper)
    const opsiProvinsi = [...(field('id_provinsi') as HTMLSelectElement).options].map((o) => o.value)
    expect(opsiProvinsi).toEqual(['', '7', '99'])

    await setInput('id_provinsi', '99')
    expect(field('provinsi_lain')).not.toBeNull()

    await setInput('jenis_alamat', '1')
    expect(q('[data-testid="riwayat-berkas-1"]')?.textContent).toContain('KTP *')
    await setInput('jenis_alamat', '3')
    expect(q('[data-testid="riwayat-berkas-1"]')?.textContent).not.toContain('KTP *')
    expect(field('nama_kantor')).not.toBeNull()
    expect(field('alamat_utama')).toBeNull()
  })

  it('seminar: bidang & instansi = teks bebas dengan saran nama master (datalist)', async () => {
    options.mockImplementation(async (entity: string) => [{ id: '1', nama: entity === 'bidang-kursem' ? 'Pariwisata' : 'Kemenparekraf', parent: null }])
    const wrapper = await mountTab({}, seminar)
    await openCreate(wrapper)
    await flushPromises()
    expect(field('bidang_seminar')?.getAttribute('list')).toBe('riwayat-saran-bidang_seminar')
    expect([...document.querySelectorAll('#riwayat-saran-bidang_seminar option')].map((o) => o.getAttribute('value'))).toEqual(['Pariwisata'])
    expect([...document.querySelectorAll('#riwayat-saran-instansi_penyelenggara option')].map((o) => o.getAttribute('value'))).toEqual(['Kemenparekraf'])
  })

  it('keluarga: item ⋮ Anak (sesudah Lampiran) membuka dialog anak', async () => {
    svc.list.mockResolvedValue([{ id_riwayat_keluarga: 5, status: 0, nama_pasangan: 'Pasangan', urutan_perkawinan: 1 }])
    const wrapper = await mountTab({ can_process: true }, keluarga)
    expect((await rowMenuActions(wrapper, 'riwayat-actions-5')).map((i) => i.key)).toEqual(['edit', 'setujui', 'tolak', 'lampiran', 'anak', 'hapus'])
  })
})
