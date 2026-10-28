/**
 * CR-072 (temuan QA Lapis 1 Fase 4, C-11) — perbaikan FE layanan yang bisa diuji di jsdom:
 * breadcrumb "Home / Layanan / …" (butir 1), h1 pada Cuti/PMK/Status Layanan (2), label filter Title Case (3),
 * label ⋮ "Unduh Surat PHK" (4), breadcrumb Petugas Approval Cuti (5), tanda wajib berkas (7), fokus kembali ke ⋮
 * setelah dialog ditutup dengan Esc (8), dan judul dialog PMK memakai nama pegawai (10). Service di-mock.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/cuti.service', () => ({
  cutiService: { list: vi.fn(), detail: vi.fn(), process: vi.fn(), tunda: vi.fn(), remove: vi.fn(), pengingat: vi.fn(), lampiran: vi.fn(), surat: vi.fn(), opsi: vi.fn(), hitungLama: vi.fn(), create: vi.fn(), update: vi.fn() },
}))
vi.mock('../services/cutiPetugasApproval.service', () => ({
  cutiPetugasApprovalService: { list: vi.fn(), opsi: vi.fn(), create: vi.fn(), update: vi.fn(), hapus: vi.fn() },
}))
vi.mock('../services/layananStatus.service', () => ({
  layananStatusService: { list: vi.fn() },
}))
vi.mock('@/features/master-data/services/master.service', () => ({
  masterService: { options: vi.fn(async () => []) },
}))
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

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { cutiPetugasApprovalService } from '../services/cutiPetugasApproval.service'
import { cutiService } from '../services/cuti.service'
import { layananStatusService } from '../services/layananStatus.service'
import { pmkService } from '../services/pmk.service'
import { aksiBarisPmk } from '../rowActionsPmk'
import type { CutiItem, CutiListResponse, ModeCuti, PmkItem, PmkListResponse, PmkOpsi } from '../types'
import CutiPage from '../views/CutiPage.vue'
import LayananStatusPage from '../views/LayananStatusPage.vue'
import PetugasApprovalCutiPage from '../views/PetugasApprovalCutiPage.vue'
import PmkPage from '../views/PmkPage.vue'
import PmkFormDialog from '../components/PmkFormDialog.vue'

const cuti = vi.mocked(cutiService)
const pmk = vi.mocked(pmkService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const teks = (el: Element | null | undefined) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim()

/** Urutan kemunculan teks dalam breadcrumb: `Home` sebelum `Layanan`, lalu halaman. */
function urutanBreadcrumb(urutan: string[]): void {
  const crumb = teks(q('nav[aria-label="Breadcrumb"]'))
  let posisi = -1
  for (const kata of urutan) {
    const at = crumb.indexOf(kata, posisi + 1)
    expect(at, `"${kata}" harus ada setelah posisi ${posisi} di breadcrumb "${crumb}"`).toBeGreaterThan(posisi)
    posisi = at
  }
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

const judulDialog = (kata: string) => [...document.body.querySelectorAll('h2')].find((h) => h.textContent?.includes(kata))

// --- Cuti ---------------------------------------------------------------------------------------------------------------

const AKSI_KOSONG = { ubah: false, hapus: false, proses: false, tangguhkan: false, tunda: false, esign: false, unduh_surat: false, proses_atas_nama: false, tangguhkan_atas_nama: false }

const barisCuti = (id: number, aksi: Partial<typeof AKSI_KOSONG> = {}): CutiItem => ({
  id,
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  id_jenis_cuti: 1,
  nama_jenis_cuti: 'Cuti Tahunan',
  tgl_mulai: '2026-11-04',
  tgl_akhir: '2026-11-06',
  lama_cuti: 3,
  status: 0,
  keterangan: 'Keperluan keluarga besar',
  no_telp_saat_cuti: '08123456789',
  alamat_saat_cuti: 'Jl. Uji No. 1',
  nip_atasan_langsung: '197001012000011001',
  nama_atasan_langsung: 'Atasan Uji',
  nip_yang_menyetujui: null,
  nama_yang_menyetujui: null,
  ada_lampiran: false,
  ada_surat: false,
  perlu_atas_nama: false,
  created_at: null,
  label_status: 'Menunggu Atasan',
  nada_status: 'warning',
  tahap: [],
  aksi: { ...AKSI_KOSONG, ...aksi },
})

const daftarCuti = (mode: ModeCuti, items: CutiItem[], can_create = false): CutiListResponse => ({ items, total: items.length, page: 1, per_page: 20, mode, can_create })

async function mountCuti(role: number, query = '') {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: CutiPage })
  router.addRoute({ path: '/layanan/cuti/:id', name: 'layanan-cuti-detail', component: { render: () => null } })
  await router.push(`/layanan/cuti${query}`)
  await router.isReady()
  const wrapper = mount(CutiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

/** Beri waktu bagi `setTimeout(0)` pemulihan fokus dan antrean Radix untuk berjalan. */
const beriWaktu = () => new Promise((resolve) => setTimeout(resolve, 20))

// --- PMK ----------------------------------------------------------------------------------------------------------------

const OPSI_PMK: PmkOpsi = {
  jenis_pmk: ['Swasta', 'Negeri'],
  pegawai: null,
  tmt_cpns: null,
  tgl_awal_maks: null,
  berkas: { file_kontrak: 'Surat kontrak kerja', file_paklaring: 'Surat paklaring', file_surat_phk: 'Surat PHK', file_slip_gaji: 'Slip gaji' },
  can_create: true,
  adalah_pic: true,
}

const barisPmk = (id: number, extra: Partial<PmkItem> & { aksi?: Record<string, boolean> } = {}): PmkItem =>
  ({
    id,
    nip: '198001012005011001',
    nama: 'Budi Santoso',
    jenis_pmk: 'Swasta',
    instansi: 'PT Contoh',
    tgl_awal: '2001-01-01',
    tgl_akhir: '2003-06-30',
    mker_th: 2,
    mker_bl: 5,
    status: 0,
    reason_note: null,
    berkas: { file_kontrak: false, file_paklaring: false, file_surat_phk: false, file_slip_gaji: false },
    aksi_tersedia: ['proses', 'setujui', 'tolak'],
    created_at: null,
    approved_at: null,
    label_status: 'Menunggu PIC',
    nada_status: 'warning',
    tahap: [],
    ...extra,
    aksi: { ubah: false, hapus: false, proses: true, ...(extra.aksi ?? {}) },
  }) as PmkItem

const daftarPmk = (mode: PmkListResponse['mode'], items: PmkItem[]): PmkListResponse => ({
  items,
  total: items.length,
  page: 1,
  per_page: 20,
  mode,
  can_create: false,
  can_process: true,
})

async function mountPmk(role: number, query = '') {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/pmk', name: 'layanan-pmk', component: PmkPage })
  router.addRoute({ path: '/layanan/pmk/pengajuan/:id', name: 'layanan-pmk-detail', component: { render: () => null } })
  await router.push(`/layanan/pmk${query}`)
  await router.isReady()
  const wrapper = mount(PmkPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

beforeEach(() => {
  for (const fn of Object.values(cuti)) fn.mockReset()
  for (const fn of Object.values(pmk)) fn.mockReset()
  cuti.pengingat.mockResolvedValue({ items: [], total: 0 })
  cuti.list.mockImplementation(async (query) => daftarCuti(query.mode, []))
  pmk.riwayat.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 20, can_edit: false })
  pmk.opsi.mockResolvedValue(OPSI_PMK)
  pmk.list.mockImplementation(async (query) => daftarPmk(query.mode, []))
})

describe('CR-072 butir 1 & 2 — breadcrumb dan h1 Cuti/PMK', () => {
  it('Cuti: breadcrumb Home / Layanan / Cuti dan h1 "Cuti"', async () => {
    await mountCuti(Role.PEGAWAI)
    urutanBreadcrumb(['Home', 'Layanan', 'Cuti'])
    expect(q('h1')?.textContent?.trim()).toBe('Cuti')
  })

  it('PMK: breadcrumb Home / Layanan / PMK dan h1 "Peninjauan Masa Kerja (PMK)"', async () => {
    await mountPmk(Role.SUPER_ADMIN)
    urutanBreadcrumb(['Home', 'Layanan', 'PMK'])
    expect(q('h1')?.textContent?.trim()).toBe('Peninjauan Masa Kerja (PMK)')
  })

  it('Status Layanan: breadcrumb Home / Layanan / Status Layanan dan h1', async () => {
    vi.mocked(layananStatusService).list.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 10 })
    loginAs(Role.SUPER_ADMIN as never)
    const router = createShellRouter()
    router.addRoute({ path: '/layanan/status', name: 'layanan-status', component: LayananStatusPage })
    await router.push('/layanan/status')
    await router.isReady()
    mount(LayananStatusPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    urutanBreadcrumb(['Home', 'Layanan', 'Status Layanan'])
    expect(q('h1')?.textContent?.trim()).toBe('Status Layanan')
  })

  it('Petugas Approval Cuti: breadcrumb memakai judul penuh "Petugas Approval Cuti"', async () => {
    vi.mocked(cutiPetugasApprovalService).list.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 20 })
    loginAs(Role.SUPER_ADMIN as never)
    const router = createShellRouter()
    router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: { render: () => null } })
    router.addRoute({ path: '/layanan/cuti/petugas-approval', name: 'layanan-cuti-petugas-approval', component: PetugasApprovalCutiPage })
    await router.push('/layanan/cuti/petugas-approval')
    await router.isReady()
    mount(PetugasApprovalCutiPage, { global: { plugins: [router] }, attachTo: document.body })
    await flushPromises()
    urutanBreadcrumb(['Home', 'Layanan', 'Cuti', 'Petugas Approval Cuti'])
    expect(teks(q('nav[aria-label="Breadcrumb"]'))).not.toMatch(/Petugas Approval(?! Cuti)/)
  })
})

describe('CR-072 butir 3 — label filter Title Case', () => {
  it('Cuti: placeholder "Semua Status" (bukan "Semua status")', async () => {
    const { wrapper } = await mountCuti(Role.PEGAWAI)
    expect(wrapper.get('[data-testid="cuti-filter"]').text()).toContain('Semua Status')
    expect(wrapper.get('[data-testid="cuti-filter"]').text()).not.toContain('Semua status')
  })

  it('PMK: placeholder "Semua Status" pada tab pengajuan', async () => {
    const { wrapper } = await mountPmk(Role.SUPER_ADMIN, '?tab=pic')
    expect(wrapper.get('[data-testid="pmk-filter"]').text()).toContain('Semua Status')
  })
})

describe('CR-072 butir 4 — label ⋮ PMK memakai label server apa adanya', () => {
  it('"Unduh Surat PHK" dari label server (bukan "Unduh surat phk")', () => {
    const row = barisPmk(3, { berkas: { file_kontrak: false, file_paklaring: false, file_surat_phk: true, file_slip_gaji: false } })
    const aksi = aksiBarisPmk(row, { labelBerkas: { file_surat_phk: 'Surat PHK' } })
    expect(aksi.find((a) => a.key === 'berkas:file_surat_phk')?.label).toBe('Unduh Surat PHK')
  })

  it('label bawaan tetap berkapital awal bila server belum memberi label', () => {
    const row = barisPmk(4, { berkas: { file_kontrak: false, file_paklaring: false, file_surat_phk: true, file_slip_gaji: false } })
    const aksi = aksiBarisPmk(row, {})
    expect(aksi.find((a) => a.key === 'berkas:file_surat_phk')?.label).toBe('Unduh Surat PHK')
  })
})

describe('CR-072 butir 7 — tanda wajib berkas PMK saat tambah', () => {
  it('tambah: berkas bertanda * (wajib); ubah dengan berkas yang sudah ada tidak bertanda *', async () => {
    loginAs(Role.PEGAWAI as never)
    mount(PmkFormDialog, { props: { open: true, row: null }, attachTo: document.body })
    await flushPromises()
    expect(document.body.textContent).toContain('Surat kontrak kerja *')
    expect(document.body.textContent).toContain('Slip gaji *')
  })

  it('ubah: kolom yang sudah berisi tidak bertanda *, kolom kosong tetap *', async () => {
    loginAs(Role.PEGAWAI as never)
    const row = barisPmk(9, { berkas: { file_kontrak: true, file_paklaring: false, file_surat_phk: false, file_slip_gaji: false } })
    mount(PmkFormDialog, { props: { open: true, row }, attachTo: document.body })
    await flushPromises()
    expect(document.body.textContent).toContain('Surat kontrak kerja')
    expect(document.body.textContent).not.toContain('Surat kontrak kerja *')
    expect(document.body.textContent).toContain('Slip gaji *')
  })
})

describe('CR-072 butir 10 — judul dialog PMK memakai nama pegawai', () => {
  it('Tolak PMK: judul "Tolak PMK Budi Santoso" tanpa NIP', async () => {
    pmk.list.mockImplementation(async (query) => daftarPmk(query.mode, [barisPmk(7)]))
    const { wrapper } = await mountPmk(Role.SUPER_ADMIN, '?tab=pic')

    await selectRowAction(wrapper, 'pmk-actions-7', 'tolak')
    await flushPromises()

    const judul = judulDialog('Tolak')?.textContent?.trim()
    expect(judul).toBe('Tolak PMK Budi Santoso')
    expect(judul).not.toContain('198001012005011001')
  })
})

describe('CR-072 butir 8 — fokus kembali ke ⋮ setelah dialog ditutup dengan Esc', () => {
  it('Cuti: Tolak lalu Esc → fokus di tombol ⋮ baris itu', async () => {
    cuti.list.mockImplementation(async (query) => daftarCuti(query.mode, [barisCuti(7, { proses: true, tangguhkan: true })]))
    const { wrapper } = await mountCuti(Role.PEGAWAI, '?tab=persetujuan')

    await selectRowAction(wrapper, 'cuti-actions-7', 'tolak')
    await flushPromises()
    expect(judulDialog('Tolak')).toBeDefined()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    await beriWaktu()

    expect(judulDialog('Tolak')).toBeUndefined()
    expect(document.activeElement).toBe(q('[data-testid="cuti-actions-7"]'))
  })

  it('PMK: Tolak lalu Esc → fokus di tombol ⋮ baris itu', async () => {
    pmk.list.mockImplementation(async (query) => daftarPmk(query.mode, [barisPmk(7)]))
    const { wrapper } = await mountPmk(Role.SUPER_ADMIN, '?tab=pic')

    await selectRowAction(wrapper, 'pmk-actions-7', 'tolak')
    await flushPromises()
    expect(judulDialog('Tolak')).toBeDefined()

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    await beriWaktu()

    expect(judulDialog('Tolak')).toBeUndefined()
    expect(document.activeElement).toBe(q('[data-testid="pmk-actions-7"]'))
  })
})

describe('menu ⋮ PMK memuat item unduh berkas dengan label server', () => {
  it('daftar aksi dari baris dengan berkas PHK', async () => {
    pmk.list.mockImplementation(async (query) =>
      daftarPmk(query.mode, [barisPmk(8, { berkas: { file_kontrak: false, file_paklaring: false, file_surat_phk: true, file_slip_gaji: false } })]),
    )
    const { wrapper } = await mountPmk(Role.SUPER_ADMIN, '?tab=pic')
    const aksi = await rowMenuActions(wrapper, 'pmk-actions-8')
    expect(aksi.map((a) => a.label)).toContain('Unduh Surat PHK')
  })
})
