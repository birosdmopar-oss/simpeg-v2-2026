/**
 * Paket 7 M7 (MAKE-026) — PmkFormDialog + PmkDetailPage: kalkulator masa kerja dari server (debounce 300 ms, tanpa hitung
 * di FE), mker dikirim apa adanya, 4 berkas (2 MB) dengan galat 422 per kolom, role 1 memilih pegawai (pencarian server),
 * ubah (prefill, berkas yang sudah ada tidak wajib), dan halaman detail (data, stepper server, berkas, menu ⋮, galat).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pmk.service', () => ({
  KOLOM_BERKAS_PMK: ['file_kontrak', 'file_paklaring', 'file_surat_phk', 'file_slip_gaji'],
  pmkService: {
    list: vi.fn(),
    detail: vi.fn(),
    opsi: vi.fn(),
    hitungMker: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    process: vi.fn(),
    berkas: vi.fn(),
    riwayat: vi.fn(),
    arsip: vi.fn(),
    tambahArsip: vi.fn(),
    hapusRiwayat: vi.fn(),
    hapusArsip: vi.fn(),
  },
}))
vi.mock('@/features/kepegawaian/services/pegawai.service', () => ({ pegawaiService: { list: vi.fn() } }))

import { Role } from '@/features/auth/types'
import { pegawaiService } from '@/features/kepegawaian/services/pegawai.service'
import type { ApiError } from '@/lib/axios'
import { rowMenuActions } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import PmkFormDialog from '../components/PmkFormDialog.vue'
import { pmkService } from '../services/pmk.service'
import type { PmkItem, PmkOpsi } from '../types'
import PmkDetailPage from '../views/PmkDetailPage.vue'

const svc = vi.mocked(pmkService)
const pegawaiList = vi.mocked(pegawaiService.list)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const apiError = (status: number, errors: Record<string, string[]> | null = null): ApiError =>
  ({ status, message: `HTTP ${status}`, errors, isNetworkError: false, original: new Error('x') }) as ApiError

const OPSI: PmkOpsi = {
  jenis_pmk: ['Swasta', 'Negeri'],
  pegawai: { nip: '198001012005011001', nama: 'Budi' },
  tmt_cpns: '2005-01-01',
  tgl_awal_maks: '2004-12-31',
  berkas: { file_kontrak: 'Surat kontrak kerja', file_paklaring: 'Surat paklaring', file_surat_phk: 'Surat PHK', file_slip_gaji: 'Slip gaji' },
  can_create: true,
  adalah_pic: false,
}

const ITEM = {
  id: 31,
  nip: '198001012005011001',
  jenis_pmk: 'Swasta',
  instansi: 'PT Contoh',
  tgl_awal: '2001-01-01',
  tgl_akhir: '2003-06-30',
  mker_th: 2,
  mker_bl: 5,
  status: 0,
  reason_note: null,
  berkas: { file_kontrak: true, file_paklaring: false, file_surat_phk: false, file_slip_gaji: false },
  aksi_tersedia: [],
  label_status: 'Menunggu PIC',
  nada_status: 'warning',
  tahap: [
    { kode: 'diajukan', label: 'Diajukan', state: 'done', nama_petugas: null },
    { kode: 'pic', label: 'Diproses PIC', state: 'current', nama_petugas: null },
    { kode: 'selesai', label: 'Selesai', state: 'todo', nama_petugas: null },
  ],
  aksi: { ubah: true, hapus: true, proses: false },
} as unknown as PmkItem

function isi(sel: string, nilai: string): void {
  const el = q<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(sel)
  if (!el) throw new Error(`isian ${sel} tidak ada`)
  el.value = nilai
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
}

async function lewatJeda(ms = 300): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await flushPromises()
}

async function mountForm(role: number = Role.PEGAWAI, row: PmkItem | null = null) {
  loginAs(role as never)
  const router = createShellRouter()
  await router.push('/')
  await router.isReady()
  const wrapper = mount(PmkFormDialog, { props: { open: true, row }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function isiDasar(): Promise<void> {
  isi('[data-testid="pmk-jenis"] select', 'Swasta')
  isi('[data-testid="pmk-instansi"] input', 'PT Contoh')
  isi('[data-testid="pmk-tgl-awal"] input', '2001-01-01')
  isi('[data-testid="pmk-tgl-akhir"] input', '2003-06-30')
  await lewatJeda()
}

afterEach(() => {
  document.body.innerHTML = ''
  vi.useRealTimers()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  pegawaiList.mockReset()
  svc.opsi.mockResolvedValue(OPSI)
  svc.hitungMker.mockResolvedValue({ mker_th: 2, mker_bl: 5, peringatan: null })
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

describe('PmkFormDialog — kalkulator masa kerja', () => {
  it('debounce 300 ms: satu panggilan hitung-mker; angka dari server ditampilkan', async () => {
    await mountForm()
    isi('[data-testid="pmk-tgl-awal"] input', '2001-01-01')
    isi('[data-testid="pmk-tgl-akhir"] input', '2003-06-30')
    await vi.advanceTimersByTimeAsync(299)
    expect(svc.hitungMker).not.toHaveBeenCalled()

    await lewatJeda(2)

    expect(svc.hitungMker).toHaveBeenCalledTimes(1)
    expect(svc.hitungMker).toHaveBeenCalledWith('2001-01-01', '2003-06-30')
    expect(q('[data-testid="pmk-mker-th"]')?.textContent).toBe('2')
    expect(q('[data-testid="pmk-mker-bl"]')?.textContent).toBe('5')
  })

  it('peringatan dan galat 422 kalkulator tampil tanpa mengunci Simpan', async () => {
    svc.hitungMker.mockResolvedValueOnce({ mker_th: 0, mker_bl: 0, peringatan: 'Masa kerja kurang dari satu bulan.' })
    await mountForm()
    await isiDasar()
    expect(q('[data-testid="pmk-peringatan"]')?.textContent).toContain('kurang dari satu bulan')

    svc.hitungMker.mockRejectedValueOnce(apiError(422, { tgl_akhir: ['Tanggal akhir tidak boleh sebelum tanggal awal.'] }))
    isi('[data-testid="pmk-tgl-akhir"] input', '2000-01-01')
    await lewatJeda()

    expect(q('[data-testid="pmk-tgl-akhir"]')?.textContent).toContain('tidak boleh sebelum')
    expect(q<HTMLButtonElement>('[data-testid="pmk-simpan"]')?.disabled).toBe(false)
  })
})

describe('PmkFormDialog — simpan', () => {
  it('pegawai: kirim isian + mker dari server + berkas terpilih; selesai menutup dialog', async () => {
    svc.create.mockResolvedValue(ITEM)
    const wrapper = await mountForm()
    await isiDasar()
    const berkas = new File(['%PDF'], 'kontrak.pdf', { type: 'application/pdf' })
    const input = q<HTMLInputElement>('[data-testid="pmk-berkas-file_kontrak"] input[type="file"]')!
    Object.defineProperty(input, 'files', { value: [berkas], configurable: true })
    input.dispatchEvent(new Event('change'))
    await flushPromises()

    q('[data-testid="pmk-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.create).toHaveBeenCalledWith(
      { jenis_pmk: 'Swasta', instansi: 'PT Contoh', tgl_awal: '2001-01-01', tgl_akhir: '2003-06-30', mker_th: '2', mker_bl: '5' },
      { file_kontrak: berkas },
    )
    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(wrapper.emitted('update:open')?.at(-1)).toEqual([false])
  })

  it('422 per kolom: tanggal, berkas, dan galat umum; dialog tetap terbuka', async () => {
    svc.create.mockRejectedValue(
      apiError(422, { tgl_awal: ['Tanggal awal harus sebelum TMT CPNS.'], file_kontrak: ['Surat kontrak kerja wajib diunggah.'], file_slip_gaji: ['Slip gaji wajib diunggah.'], lain: ['Tumpang tindih.'] }),
    )
    const wrapper = await mountForm()
    await isiDasar()

    q('[data-testid="pmk-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(q('[data-testid="pmk-tgl-awal"]')?.textContent).toContain('sebelum TMT CPNS')
    expect(q('[data-testid="pmk-berkas-file_kontrak"]')?.textContent).toContain('wajib diunggah')
    expect(q('[data-testid="pmk-berkas-file_slip_gaji"]')?.textContent).toContain('wajib diunggah')
    expect(q('[data-testid="pmk-form-error"]')?.textContent).toContain('Tumpang tindih')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('opsi tanpa hak mengajukan (bukan PNS): pesan dan Simpan nonaktif', async () => {
    svc.opsi.mockResolvedValue({ ...OPSI, can_create: false })
    await mountForm()
    expect(q('[data-testid="pmk-tidak-bisa"]')).not.toBeNull()
    expect(q<HTMLButtonElement>('[data-testid="pmk-simpan"]')?.disabled).toBe(true)
  })

  it('role 1: cari pegawai → pilih saran memuat opsi?nip=, kirim nip', async () => {
    svc.create.mockResolvedValue(ITEM)
    pegawaiList.mockResolvedValue({ items: [{ nip: '198001012005011001', nama: 'Budi Santoso', glr_awal: null, glr_akhir: null }], total: 1, page: 1, per_page: 10 } as never)
    await mountForm(Role.SUPER_ADMIN)
    expect(svc.opsi).not.toHaveBeenCalled()
    expect(q('[data-testid="pmk-jenis"]')).toBeNull()

    isi('[data-testid="pegawai-picker"] input', 'budi')
    await lewatJeda()
    expect(pegawaiList).toHaveBeenCalledWith({ page: 1, per_page: 10, search: 'budi' })
    isi('[data-testid="pegawai-picker"] input', '198001012005011001 — Budi Santoso')
    await flushPromises()
    expect(svc.opsi).toHaveBeenCalledWith('198001012005011001')

    await isiDasar()
    q('[data-testid="pmk-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(svc.create.mock.calls[0]![0]).toMatchObject({ nip: '198001012005011001' })
  })

  it('ubah: prefill dari baris, opsi tanpa nip untuk pemilik, update dengan id dan berkas yang sudah ada tidak diminta ulang', async () => {
    svc.update.mockResolvedValue(ITEM)
    await mountForm(Role.PEGAWAI, ITEM)
    await lewatJeda()

    expect(q<HTMLInputElement>('[data-testid="pmk-instansi"] input')?.value).toBe('PT Contoh')
    expect(q('[data-testid="pmk-berkas-file_kontrak"]')?.textContent).toContain('Sudah ada')
    expect(q('[data-testid="pmk-berkas-file_paklaring"]')?.textContent).toContain('Wajib')

    q('[data-testid="pmk-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(svc.update).toHaveBeenCalledWith(31, expect.objectContaining({ instansi: 'PT Contoh', mker_th: '2', mker_bl: '5' }), {})
    expect(svc.create).not.toHaveBeenCalled()
  })
})

describe('PmkDetailPage', () => {
  async function mountDetail(role: number = Role.PEGAWAI) {
    vi.useRealTimers()
    loginAs(role as never)
    const router = createShellRouter()
    router.addRoute({ path: '/layanan/pmk', name: 'layanan-pmk', component: { render: () => null } })
    router.addRoute({ path: '/layanan/pmk/pengajuan/:id', name: 'layanan-pmk-detail', component: PmkDetailPage })
    await router.push('/layanan/pmk/pengajuan/31')
    await router.isReady()
    const wrapper = mount(PmkDetailPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    return wrapper
  }

  it('menampilkan data, stepper dari tahap server, berkas, dan menu ⋮ tanpa Lihat detail', async () => {
    svc.detail.mockResolvedValue({ ...ITEM, berkas: { file_kontrak: true, file_paklaring: false, file_surat_phk: false, file_slip_gaji: true } })
    const wrapper = await mountDetail()

    expect(wrapper.get('[data-testid="pmk-detail-data"]').text()).toContain('1 Januari 2001 s.d. 30 Juni 2003')
    expect(wrapper.get('[data-testid="pmk-detail-data"]').text()).toContain('2 tahun 5 bulan')
    expect(wrapper.get('[data-testid="pmk-stepper"]').text()).toContain('Diproses PIC')
    expect(wrapper.get('[data-testid="pmk-berkas"]').text()).toContain('Surat kontrak kerja')
    expect((await rowMenuActions(wrapper, 'pmk-actions-31')).map((a) => a.key)).toEqual(['edit', 'berkas:file_kontrak', 'berkas:file_slip_gaji', 'hapus'])
  })

  it('pengajuan ditolak menampilkan alasan; 404 dan 403 pesan baku', async () => {
    svc.detail.mockResolvedValueOnce({ ...ITEM, status: 2, reason_note: 'Berkas tidak lengkap', label_status: 'Ditolak', nada_status: 'danger' })
    const wrapper = await mountDetail()
    expect(wrapper.get('[data-testid="pmk-alasan"]').text()).toContain('Berkas tidak lengkap')
    wrapper.unmount()

    svc.detail.mockRejectedValueOnce(apiError(404))
    const w2 = await mountDetail()
    expect(q('[data-testid="pmk-detail-error"]')?.textContent).toContain('Data tidak ditemukan')
    w2.unmount()

    svc.detail.mockRejectedValueOnce(apiError(403))
    await mountDetail()
    expect(q('[data-testid="pmk-detail-error"]')?.textContent).toContain('tidak berhak')
  })
})
