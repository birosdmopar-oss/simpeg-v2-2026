/**
 * Paket 7 M7 (MAKE-026) — PmkPage: tab per role (Riwayat PMK semua, Pengajuan Saya role 2, Perlu Persetujuan PIC, Monitoring
 * 1/3), menu ⋮ dari `aksi {}`/`aksi_tersedia[]`/berkas server (AGENTS §1), proses PIC (Proses/Setujui/Tolak), unduh berkas,
 * hapus, riwayat final (arsip/hapus hanya `can_edit`), dan keadaan galat. Service di-mock; amplop diuji di pmk.service.amplop.spec.
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
vi.mock('@/features/kepegawaian/services/berkasUnduhan', async (importOriginal) => ({ ...(await importOriginal<object>()), simpanBerkas: vi.fn() }))

import { Role } from '@/features/auth/types'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import type { ApiError } from '@/lib/axios'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { pmkService } from '../services/pmk.service'
import type { AksiProsesPmk, PmkItem, PmkListResponse, PmkOpsi, RiwayatPmkResponse } from '../types'
import PmkPage from '../views/PmkPage.vue'

const svc = vi.mocked(pmkService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const apiError = (status: number, errors: Record<string, string[]> | null = null): ApiError =>
  ({ status, message: `HTTP ${status}`, errors, isNetworkError: false, original: new Error('x') }) as ApiError

const OPSI: PmkOpsi = {
  jenis_pmk: ['Swasta', 'Negeri'],
  pegawai: null,
  tmt_cpns: null,
  tgl_awal_maks: null,
  berkas: { file_kontrak: 'Surat kontrak kerja', file_paklaring: 'Surat paklaring', file_surat_phk: 'Surat PHK', file_slip_gaji: 'Slip gaji' },
  can_create: true,
  adalah_pic: true,
}

const baris = (id: number, extra: Partial<PmkItem> & { aksi?: Record<string, boolean> } = {}): PmkItem =>
  ({
    id,
    nip: '198001012005011001',
    jenis_pmk: 'Swasta',
    instansi: 'PT Contoh',
    tgl_awal: '2001-01-01',
    tgl_akhir: '2003-06-30',
    mker_th: 2,
    mker_bl: 5,
    status: 0,
    reason_note: null,
    berkas: { file_kontrak: false, file_paklaring: false, file_surat_phk: false, file_slip_gaji: false },
    aksi_tersedia: [],
    created_at: null,
    approved_at: null,
    label_status: 'Menunggu PIC',
    nada_status: 'warning',
    tahap: [],
    ...extra,
    aksi: { ubah: false, hapus: false, proses: false, ...(extra.aksi ?? {}) },
  }) as PmkItem

const daftar = (mode: PmkListResponse['mode'], items: PmkItem[], can_create = false, can_process = false): PmkListResponse => ({
  items,
  total: items.length,
  page: 1,
  per_page: 20,
  mode,
  can_create,
  can_process,
})

const RIWAYAT: RiwayatPmkResponse = {
  items: [{ id: 5, nip: '198001012005011001', no_sk: 'SK-PMK-1', tgl_sk: '2020-01-01', instansi: 'PT Lama', tgl_awal: '1999-01-01', tgl_akhir: '2000-12-31', mker_th: 1, mker_bl: 11, arsip: [{ id_attachment: 9, display_name: 'sk.pdf' }] }],
  total: 1,
  page: 1,
  per_page: 20,
  can_edit: true,
}

async function mountPage(role: number, query = '') {
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

function isi(sel: string, nilai: string): void {
  const el = q<HTMLTextAreaElement>(sel)
  if (!el) throw new Error(`isian ${sel} tidak ada`)
  el.value = nilai
  el.dispatchEvent(new Event('input'))
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  vi.mocked(simpanBerkas).mockReset()
  svc.opsi.mockResolvedValue(OPSI)
  svc.riwayat.mockResolvedValue({ ...RIWAYAT, items: [], total: 0, can_edit: false })
  svc.list.mockImplementation(async (query) => daftar(query.mode, []))
})

describe('PmkPage — tab per role', () => {
  it('pegawai (2): Riwayat PMK, Pengajuan Saya, Perlu Persetujuan; tanpa Monitoring', async () => {
    const { wrapper } = await mountPage(Role.PEGAWAI)
    const tabs = wrapper.findAll('[role="tab"]').map((t) => t.text())
    expect(tabs).toEqual(['Riwayat PMK', 'Pengajuan Saya', 'Perlu Persetujuan'])
    expect(svc.riwayat).toHaveBeenCalled()
  })

  it('super admin (1) dan admin satker (3): Riwayat, Perlu Persetujuan, Monitoring; tanpa Pengajuan Saya', async () => {
    for (const role of [Role.SUPER_ADMIN, Role.ADMIN_SATKER]) {
      const { wrapper } = await mountPage(role)
      expect(wrapper.findAll('[role="tab"]').map((t) => t.text())).toEqual(['Riwayat PMK', 'Perlu Persetujuan', 'Monitoring'])
      wrapper.unmount()
    }
  })

  it('adalah_pic=false dari server menyembunyikan tab Perlu Persetujuan; true/unknown menampilkannya', async () => {
    svc.opsi.mockResolvedValue({ ...OPSI, adalah_pic: false })
    const { wrapper } = await mountPage(Role.PEGAWAI)
    expect(wrapper.findAll('[role="tab"]').map((t) => t.text())).toEqual(['Riwayat PMK', 'Pengajuan Saya'])
    wrapper.unmount()

    svc.opsi.mockResolvedValue({ ...OPSI, adalah_pic: true })
    const { wrapper: w2 } = await mountPage(Role.PEGAWAI)
    expect(w2.findAll('[role="tab"]').map((t) => t.text())).toContain('Perlu Persetujuan')
  })

  it('tombol Ajukan PMK hanya pada tab pengajuan dan bila can_create dari server', async () => {
    const { wrapper } = await mountPage(Role.PEGAWAI)
    expect(wrapper.find('[data-testid="pmk-tambah"]').exists()).toBe(false)

    await wrapper.findAll('[role="tab"]')[1]!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="pmk-tambah"]').exists()).toBe(true)

    svc.opsi.mockResolvedValue({ ...OPSI, can_create: false })
    const { wrapper: w3 } = await mountPage(Role.ADMIN_SATKER, '?tab=admin')
    expect(w3.find('[data-testid="pmk-tambah"]').exists()).toBe(false)
  })
})

describe('PmkPage — menu ⋮ dari server', () => {
  it('urutan AGENTS §1: Edit → Lihat detail → Proses/Setujui/Tolak → Unduh berkas → Hapus (danger)', async () => {
    svc.list.mockImplementation(async (query) =>
      daftar(query.mode, [
        baris(31, {
          aksi: { ubah: true, hapus: true, proses: true },
          aksi_tersedia: ['proses', 'setujui', 'tolak'] as AksiProsesPmk[],
          berkas: { file_kontrak: true, file_paklaring: false, file_surat_phk: true, file_slip_gaji: false },
        }),
      ]),
    )
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=pic')

    const aksi = await rowMenuActions(wrapper, 'pmk-actions-31')

    expect(aksi.map((a) => a.key)).toEqual(['edit', 'detail', 'proses', 'setujui', 'tolak', 'berkas:file_kontrak', 'berkas:file_surat_phk', 'hapus'])
    expect(aksi.find((a) => a.key === 'berkas:file_kontrak')?.label).toBe('Unduh surat kontrak kerja')
    expect(aksi.at(-1)).toMatchObject({ key: 'hapus', danger: true })
    expect(q('[data-testid="pmk-actions-31"]')?.getAttribute('aria-label')).toContain('Aksi untuk PMK')
  })

  it('status Diproses (3): hanya Setujui dan Tolak (aksi_tersedia dari server)', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(32, { status: 3, aksi: { proses: true }, aksi_tersedia: ['setujui', 'tolak'] as AksiProsesPmk[] })]))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=pic')

    expect((await rowMenuActions(wrapper, 'pmk-actions-32')).map((a) => a.key)).toEqual(['detail', 'setujui', 'tolak'])
  })

  it('tanpa hak: hanya Lihat detail', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(33)]))
    const { wrapper } = await mountPage(Role.ADMIN_SATKER, '?tab=admin')

    expect((await rowMenuActions(wrapper, 'pmk-actions-33')).map((a) => a.key)).toEqual(['detail'])
  })

  it('Lihat detail membuka route detail', async () => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(34)]))
    const { wrapper, router } = await mountPage(Role.PEGAWAI, '?tab=saya')

    await selectRowAction(wrapper, 'pmk-actions-34', 'detail')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('layanan-pmk-detail')
    expect(router.currentRoute.value.params.id).toBe('34')
  })
})

describe('PmkPage — proses PIC', () => {
  beforeEach(() => {
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(40, { aksi: { proses: true }, aksi_tersedia: ['proses', 'setujui', 'tolak'] as AksiProsesPmk[] })], false, true))
  })

  it('Proses: dialog konfirmasi, memanggil process("proses") tanpa alasan lalu memuat ulang', async () => {
    svc.process.mockResolvedValue()
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=pic')

    await selectRowAction(wrapper, 'pmk-actions-40', 'proses')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="pmk-proses-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(40, 'proses', undefined)
    expect(q('[data-testid="pmk-notice"]')?.textContent).toContain('diproses')
    expect(svc.list.mock.calls.length).toBeGreaterThan(1)
  })

  it('Tolak tanpa alasan tidak terkirim; dengan alasan mengirim reason_note', async () => {
    svc.process.mockResolvedValue()
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=pic')

    await selectRowAction(wrapper, 'pmk-actions-40', 'tolak')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()
    expect(svc.process).not.toHaveBeenCalled()

    isi('[data-testid="approval-reason"]', 'Berkas tidak lengkap')
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(40, 'tolak', 'Berkas tidak lengkap')
  })

  it('Setujui mengirim aksi setujui; galat 422 status final tampil di dialog', async () => {
    svc.process.mockRejectedValueOnce(apiError(422, { status: ['Pengajuan sudah final.'] }))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=pic')

    await selectRowAction(wrapper, 'pmk-actions-40', 'setujui')
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')!.click()
    await flushPromises()

    expect(svc.process).toHaveBeenCalledWith(40, 'setujui', undefined)
    expect(q('[data-testid="approval-error"]')).not.toBeNull()
  })

  it('403 pada tab PIC → pesan bukan PIC aktif', async () => {
    svc.list.mockRejectedValue(apiError(403))
    await mountPage(Role.PEGAWAI, '?tab=pic')
    expect(q('[data-testid="pmk-error"]')?.textContent).toContain('bukan PIC')
  })
})

describe('PmkPage — unduh berkas dan hapus', () => {
  it('Unduh berkas memanggil service dengan kolom yang dipilih lalu menyimpan berkas', async () => {
    const berkas = { blob: new Blob(['x']), nama: 'k.pdf' }
    svc.berkas.mockResolvedValue(berkas)
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(41, { berkas: { file_kontrak: true, file_paklaring: false, file_surat_phk: false, file_slip_gaji: false } })]))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=saya')

    await selectRowAction(wrapper, 'pmk-actions-41', 'berkas:file_kontrak')
    await flushPromises()

    expect(svc.berkas).toHaveBeenCalledWith(41, 'file_kontrak')
    expect(simpanBerkas).toHaveBeenCalledWith(berkas)
  })

  it('404 berkas → pesan baku', async () => {
    svc.berkas.mockRejectedValue(apiError(404))
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(42, { berkas: { file_kontrak: true, file_paklaring: false, file_surat_phk: false, file_slip_gaji: false } })]))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=saya')

    await selectRowAction(wrapper, 'pmk-actions-42', 'berkas:file_kontrak')
    await flushPromises()

    expect(q('[data-testid="pmk-notice"]')?.textContent).toContain('tidak ditemukan')
  })

  it('Hapus meminta konfirmasi lalu memanggil remove', async () => {
    svc.remove.mockResolvedValue()
    svc.list.mockImplementation(async (query) => daftar(query.mode, [baris(43, { aksi: { hapus: true } })]))
    const { wrapper } = await mountPage(Role.PEGAWAI, '?tab=saya')

    await selectRowAction(wrapper, 'pmk-actions-43', 'hapus')
    await flushPromises()
    expect(svc.remove).not.toHaveBeenCalled()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))?.click()
    await flushPromises()

    expect(svc.remove).toHaveBeenCalledWith(43)
  })
})

describe('PmkPage — riwayat PMK final', () => {
  it('menu ⋮: Unduh arsip untuk semua yang melihat; Kelola arsip → Hapus hanya bila can_edit server', async () => {
    svc.riwayat.mockResolvedValue(RIWAYAT)
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)

    expect((await rowMenuActions(wrapper, 'riwayat-pmk-actions-5')).map((a) => a.key)).toEqual(['unduh:9', 'arsip', 'hapus'])

    svc.riwayat.mockResolvedValue({ ...RIWAYAT, can_edit: false })
    const { wrapper: w2 } = await mountPage(Role.PEGAWAI)
    expect((await rowMenuActions(w2, 'riwayat-pmk-actions-5')).map((a) => a.key)).toEqual(['unduh:9'])
    expect(w2.find('[data-testid="riwayat-pmk-row-5"]').text()).toContain('SK-PMK-1')
  })

  it('Unduh arsip memanggil service dengan id riwayat dan arsip lalu menyimpan berkas; 404 → pesan baku', async () => {
    const berkas = { blob: new Blob(['x']), nama: 'sk.pdf' }
    svc.riwayat.mockResolvedValue({ ...RIWAYAT, can_edit: false })
    svc.arsip.mockResolvedValueOnce(berkas)
    const { wrapper } = await mountPage(Role.PEGAWAI)

    await selectRowAction(wrapper, 'riwayat-pmk-actions-5', 'unduh:9')
    await flushPromises()
    expect(svc.arsip).toHaveBeenCalledWith(5, 9)
    expect(simpanBerkas).toHaveBeenCalledWith(berkas)

    svc.arsip.mockRejectedValueOnce(apiError(404))
    await selectRowAction(wrapper, 'riwayat-pmk-actions-5', 'unduh:9')
    await flushPromises()
    expect(q('[data-testid="pmk-notice"]')?.textContent).toContain('tidak ditemukan')
  })

  it('Kelola arsip: tambah arsip memanggil service; galat minimal-satu-arsip tampil per kolom', async () => {
    svc.riwayat.mockResolvedValue(RIWAYAT)
    svc.hapusArsip.mockRejectedValueOnce(apiError(422, { arsip: ['Minimal satu arsip SK harus tersisa.'] }))
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)

    await selectRowAction(wrapper, 'riwayat-pmk-actions-5', 'arsip')
    await flushPromises()
    expect(q('[data-testid="arsip-pmk-list"]')?.textContent).toContain('sk.pdf')

    q<HTMLButtonElement>('[data-testid="arsip-pmk-hapus-9"]')!.click()
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))?.click()
    await flushPromises()

    expect(svc.hapusArsip).toHaveBeenCalledWith(5, 9)
    expect(q('[data-testid="arsip-pmk-error"]')?.textContent).toContain('Minimal satu arsip')
  })

  it('Hapus riwayat meminta konfirmasi lalu memanggil hapusRiwayat', async () => {
    svc.riwayat.mockResolvedValue(RIWAYAT)
    svc.hapusRiwayat.mockResolvedValue()
    const { wrapper } = await mountPage(Role.SUPER_ADMIN)

    await selectRowAction(wrapper, 'riwayat-pmk-actions-5', 'hapus')
    await flushPromises()
    ;[...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))?.click()
    await flushPromises()

    expect(svc.hapusRiwayat).toHaveBeenCalledWith(5)
  })

  it('keadaan kosong berbahasa Indonesia', async () => {
    const { wrapper } = await mountPage(Role.PEGAWAI)
    expect(wrapper.find('[data-testid="riwayat-pmk-empty"]').text()).toContain('Belum ada riwayat PMK')
  })
})
