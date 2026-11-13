/**
 * Dialog aksi Arsip Tahap 2 (MAKE-096): ArsipEditDialog (Ubah Nama; AC2-08) dan ArsipHubungkanDialog (dua langkah; AC2-05/06/13,
 * F10-28/31/38): validasi, pemanggilan API, pesan 422/403/404 server tampil di dialog, kode 38 tanpa pilihan data.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/arsip.service', () => ({
  arsipService: { ubahNama: vi.fn(), hubungkan: vi.fn(), opsiHubungkan: vi.fn(), hapus: vi.fn(), folder: vi.fn(), berkas: vi.fn(), ekspor: vi.fn() },
}))

import type { ArsipBerkas } from '../arsip.types'
import ArsipEditDialog from '../components/arsip/ArsipEditDialog.vue'
import ArsipHubungkanDialog from '../components/arsip/ArsipHubungkanDialog.vue'
import { arsipService } from '../services/arsip.service'

import { NIP } from './fixtures'

const ubahNama = vi.mocked(arsipService.ubahNama)
const hubungkan = vi.mocked(arsipService.hubungkan)
const opsi = vi.mocked(arsipService.opsiHubungkan)

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

function galat(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: { response: { status, data: { message, errors } } } }
}

function berkas(overrides: Partial<ArsipBerkas> = {}): ArsipBerkas {
  return {
    id_attachment: 77,
    nama_arsip: 'SK CPNS',
    tag: 'SK CPNS',
    display_name: null,
    id_riwayat: 0,
    jenis_riwayat: 'Belum Terhubung',
    id_entri: null,
    ringkasan_riwayat: '—',
    file_type: 'application/pdf',
    created_at: '2026-10-08 03:15:00',
    berkas_ada: true,
    status_berkas: 'ada',
    akses_berkas: 'privat',
    url_berkas: `pegawai/${NIP}/arsip/berkas/77/buka`,
    jenis_buka: 'pratinjau',
    aksi: { edit: true, hubungkan: true, hapus: true },
    ...overrides,
  }
}

async function ketik(sel: string, nilai: string): Promise<void> {
  const input = q<HTMLInputElement>(`${sel} input, input${sel}`)
  if (!input) throw new Error(`isian ${sel} tidak ada`)
  input.value = nilai
  input.dispatchEvent(new Event('input'))
  await flushPromises()
}

async function pilih(sel: string, nilai: string): Promise<void> {
  const select = q<HTMLSelectElement>(`${sel} select`)
  if (!select) throw new Error(`select ${sel} tidak ada`)
  select.value = nilai
  select.dispatchEvent(new Event('change'))
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  ubahNama.mockReset()
  hubungkan.mockReset()
  opsi.mockReset()
})

describe('ArsipEditDialog (Ubah Nama)', () => {
  async function pasang(item: ArsipBerkas = berkas()) {
    const wrapper = mount(ArsipEditDialog, { props: { open: true, nip: NIP, item }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  it('terisi nama sekarang (tag); simpan memanggil PATCH dengan nama dipangkas, menutup dialog, emit done', async () => {
    ubahNama.mockResolvedValue(undefined)
    const wrapper = await pasang()
    expect(q<HTMLInputElement>('[data-testid="arsip-edit-nama"] input')?.value).toBe('SK CPNS')
    await ketik('[data-testid="arsip-edit-nama"]', '  SK CPNS Baru  ')
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(ubahNama).toHaveBeenCalledWith(NIP, 77, 'SK CPNS Baru')
    expect(wrapper.emitted('done')?.[0]).toEqual(['Nama arsip diubah.'])
    expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
  })

  it('tag kosong → memakai nama_arsip sebagai nilai awal', async () => {
    await pasang(berkas({ tag: null, nama_arsip: 'Berkas 77' }))
    expect(q<HTMLInputElement>('[data-testid="arsip-edit-nama"] input')?.value).toBe('Berkas 77')
  })

  it('kosong / hanya spasi → pesan wajib diisi, tanpa memanggil API', async () => {
    await pasang()
    await ketik('[data-testid="arsip-edit-nama"]', '   ')
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-edit-dialog"]')?.textContent).toContain('Nama arsip wajib diisi.')
    expect(ubahNama).not.toHaveBeenCalled()
  })

  it('256 karakter → ditolak di klien; 255 karakter diterima', async () => {
    ubahNama.mockResolvedValue(undefined)
    await pasang()
    await ketik('[data-testid="arsip-edit-nama"]', 'a'.repeat(256))
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-edit-dialog"]')?.textContent).toContain('maksimal 255 karakter')
    expect(ubahNama).not.toHaveBeenCalled()
    await ketik('[data-testid="arsip-edit-nama"]', 'a'.repeat(255))
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(ubahNama).toHaveBeenCalledWith(NIP, 77, 'a'.repeat(255))
  })

  it('422 errors.tag → pesan server di isian, dialog tetap terbuka, tanpa done', async () => {
    ubahNama.mockRejectedValue(galat(422, 'Periksa kembali isian yang ditandai.', { tag: ['Nama arsip tidak valid.'] }))
    const wrapper = await pasang()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-edit-dialog"]')?.textContent).toContain('Nama arsip tidak valid.')
    expect(wrapper.emitted('done')).toBeUndefined()
    expect(wrapper.emitted('update:open')).toBeUndefined()
  })

  it('403 (mis. kode 37 bukan role 1) → pesan server tampil sebagai notice', async () => {
    ubahNama.mockRejectedValue(galat(403, 'Anda tidak berhak mengubah arsip data umum pegawai ini.'))
    await pasang()
    q<HTMLElement>('[data-testid="arsip-edit-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-edit-galat"]')?.textContent).toContain('Anda tidak berhak mengubah arsip data umum pegawai ini.')
  })

  it('deskripsi dialog diinterpolasi sebagai teks (tanpa HTML)', async () => {
    await pasang(berkas({ jenis_riwayat: '<img src=x onerror=alert(1)>' }))
    expect(q('[data-testid="arsip-edit-dialog"] img')).toBeNull()
  })
})

describe('ArsipHubungkanDialog (dua langkah)', () => {
  const LABEL: Record<number, string> = { 9: 'Jabatan', 1: 'Pendidikan', 38: 'Data Umum' }

  async function pasang(jenisHubungkan: number[]) {
    const wrapper = mount(ArsipHubungkanDialog, {
      props: { open: true, nip: NIP, item: berkas(), jenisHubungkan: jenisHubungkan.map((k) => ({ id_jenis_rwy: k, label: LABEL[k] ?? '' })) },
      attachTo: document.body,
    })
    await flushPromises()
    return wrapper
  }

  it('hanya menawarkan jenis dari aksi.jenis_hubungkan (label dari server); tombol nonaktif sampai lengkap', async () => {
    await pasang([9, 38])
    const labels = [...document.body.querySelectorAll('[data-testid="arsip-hubungkan-jenis"] option')].map((o) => o.textContent?.trim())
    expect(labels).toEqual(expect.arrayContaining(['Jabatan', 'Data Umum']))
    expect(labels).not.toContain('Pendidikan')
    expect(q<HTMLButtonElement>('[data-testid="arsip-hubungkan-simpan"]')?.disabled).toBe(true)
  })

  it('jenis dengan data riwayat: memuat opsi-hubungkan, pilih data, POST { id_riwayat, id_entri }', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis (Satker A)' }, { id_entri: '457', ringkasan_riwayat: 'Kepala (Satker B)' }] })
    hubungkan.mockResolvedValue(undefined)
    const wrapper = await pasang([9])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    expect(opsi).toHaveBeenCalledWith(NIP, 9)
    expect(q<HTMLButtonElement>('[data-testid="arsip-hubungkan-simpan"]')?.disabled).toBe(true)
    await pilih('[data-testid="arsip-hubungkan-entri"]', '457')
    expect(q<HTMLButtonElement>('[data-testid="arsip-hubungkan-simpan"]')?.disabled).toBe(false)
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(hubungkan).toHaveBeenCalledWith(NIP, 77, { id_riwayat: 9, id_entri: '457' })
    expect(wrapper.emitted('done')?.[0]).toEqual(['Arsip dihubungkan.'])
    expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
  })

  it('kode 38: tanpa pemanggilan opsi-hubungkan dan tanpa id_entri; langsung bisa dikirim', async () => {
    hubungkan.mockResolvedValue(undefined)
    await pasang([38])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '38')
    expect(opsi).not.toHaveBeenCalled()
    expect(q('[data-testid="arsip-hubungkan-tanpa-data"]')).not.toBeNull()
    expect(q('[data-testid="arsip-hubungkan-entri"]')).toBeNull()
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(hubungkan).toHaveBeenCalledWith(NIP, 77, { id_riwayat: 38 })
  })

  it('tidak ada data riwayat Disetujui → keterangan kosong, tombol tetap nonaktif', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [] })
    await pasang([9])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    expect(q('[data-testid="arsip-hubungkan-kosong"]')?.textContent).toContain('Tidak ada data riwayat berstatus Disetujui')
    expect(q<HTMLButtonElement>('[data-testid="arsip-hubungkan-simpan"]')?.disabled).toBe(true)
  })

  it('opsi-hubungkan 403 (kunci Disetujui role 2/6/7) → pesan server di dialog', async () => {
    opsi.mockRejectedValue(galat(403, 'Data yang sudah disetujui tidak dapat diubah.'))
    await pasang([9])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    expect(q('[data-testid="arsip-hubungkan-galat"]')?.textContent).toContain('Data yang sudah disetujui tidak dapat diubah.')
  })

  it('POST 403 kunci → pesan server tampil, dialog tetap terbuka (AC2-13)', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis' }] })
    hubungkan.mockRejectedValue(galat(403, 'Data yang sudah disetujui tidak dapat diubah.'))
    const wrapper = await pasang([9])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    await pilih('[data-testid="arsip-hubungkan-entri"]', '456')
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-hubungkan-galat"]')?.textContent).toContain('Data yang sudah disetujui tidak dapat diubah.')
    expect(wrapper.emitted('done')).toBeUndefined()
    expect(wrapper.emitted('update:open')).toBeUndefined()
  })

  it('POST 422 errors.id_attachment (sudah terhubung) → pesan legacy apa adanya', async () => {
    const pesan = 'Data arsip ini telah terhubung ke data riwayat pegawai. Silahkan hubungkan data arsip lain yang belum terhubung ke data riwayat.'
    hubungkan.mockRejectedValue(galat(422, 'Periksa kembali isian yang ditandai.', { id_attachment: [pesan] }))
    await pasang([38])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '38')
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-hubungkan-galat"]')?.textContent).toContain(pesan)
  })

  it('POST 422 errors.id_entri (data riwayat kosong untuk kode bukan 38) → pesan server di dialog', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis' }] })
    hubungkan.mockRejectedValue(galat(422, 'Periksa kembali isian yang ditandai.', { id_entri: ['Data riwayat wajib dipilih.'] }))
    await pasang([9])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    await pilih('[data-testid="arsip-hubungkan-entri"]', '456')
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-hubungkan-galat"]')?.textContent).toContain('Data riwayat wajib dipilih.')
  })

  it('POST 404 (riwayat tujuan tidak ada / bukan Disetujui) → pesan server', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis' }] })
    hubungkan.mockRejectedValue(galat(404, 'Data riwayat tidak ditemukan.'))
    await pasang([9])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    await pilih('[data-testid="arsip-hubungkan-entri"]', '456')
    q<HTMLElement>('[data-testid="arsip-hubungkan-simpan"]')?.click()
    await flushPromises()
    expect(q('[data-testid="arsip-hubungkan-galat"]')?.textContent).toContain('Data riwayat tidak ditemukan.')
  })

  it('ganti jenis membuang pilihan data lama dan memuat ulang opsi', async () => {
    opsi.mockResolvedValue({ id_riwayat: 9, butuh_data: true, items: [{ id_entri: '456', ringkasan_riwayat: 'Analis' }] })
    await pasang([9, 1])
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '9')
    await pilih('[data-testid="arsip-hubungkan-entri"]', '456')
    await pilih('[data-testid="arsip-hubungkan-jenis"]', '1')
    expect(opsi).toHaveBeenLastCalledWith(NIP, 1)
    expect(q<HTMLButtonElement>('[data-testid="arsip-hubungkan-simpan"]')?.disabled).toBe(true)
  })
})
