/**
 * MAKE-078 (ISSUE-058) — halaman Admin → Integrasi: daftar log (kolom, badge status, waktu UTC → zona browser), filter
 * layanan/status/tanggal → parameter query (halaman kembali ke 1), paginasi, aksi baris ⋮ "Lihat detail" (dipilih lewat
 * `data-action`), 403/422, tarik ulang e-Kinerja (payload per jenis, 202 → pesan antre, 422 per field), route /integrasi
 * hanya role 1, dan menu Pengaturan → Integrasi hanya untuk role 1.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/logIntegrasi.service', () => ({
  logIntegrasiService: { list: vi.fn(), tarikEkinerja: vi.fn() },
}))

import { useAuthStore } from '@/features/auth/stores/auth.store'
import { Role, type User } from '@/features/auth/types'
import router from '@/router'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { buildNav } from '@/shared/layouts/nav.config'
import { UiTextField } from '@/shared/ui'
import { formatApiDateTime } from '@/shared/utils/dateTime'

import TarikEkinerjaDialog from '../components/TarikEkinerjaDialog.vue'
import type { DaftarLogIntegrasi, LogIntegrasiRow } from '../integrasi.types'
import { logIntegrasiService } from '../services/logIntegrasi.service'
import IntegrasiAdminView from '../views/IntegrasiAdminView.vue'

const rows: LogIntegrasiRow[] = [
  {
    id_integrasi_log: 9,
    arah: 1,
    layanan: 'bsre',
    operasi: 'sign_pdf',
    status: 2,
    http_status: 500,
    kode: 'E500',
    pesan: 'Gagal tanda tangan (teredaksi)',
    durasi_ms: 812,
    percobaan: 2,
    ref_id: 'cuti:12',
    id_klien: null,
    created_at: '2026-11-02 03:00:00',
  },
  {
    id_integrasi_log: 4,
    arah: 2,
    layanan: 'simapi',
    operasi: 'auth_klien',
    status: 1,
    http_status: null,
    kode: null,
    pesan: null,
    durasi_ms: null,
    percobaan: 1,
    ref_id: null,
    id_klien: 3,
    created_at: '2026-11-01 01:00:00',
  },
]

const daftar = (override: Partial<DaftarLogIntegrasi> = {}): DaftarLogIntegrasi => ({
  items: rows,
  total: rows.length,
  page: 1,
  per_page: 25,
  layanan_tersedia: ['bsre', 'simapi', 'bkn'],
  ...override,
})

const query = (override: Record<string, unknown> = {}) => ({
  layanan: '',
  status: '',
  tanggal_mulai: '',
  tanggal_selesai: '',
  page: 1,
  per_page: 25,
  ...override,
})

function apiError(status: number, message: string, errors: Record<string, string[]> | null = null) {
  return { status, message, errors, isNetworkError: false, original: new AxiosError('x') }
}

async function mountView(): Promise<VueWrapper> {
  setActivePinia(createPinia())
  useAuthStore().user = { username: 'admin.uji', user_level: Role.SUPER_ADMIN } as User
  const wrapper = mount(IntegrasiAdminView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

function lastQuery(): unknown {
  return vi.mocked(logIntegrasiService.list).mock.lastCall?.[0]
}

beforeEach(() => {
  document.body.innerHTML = ''
  vi.mocked(logIntegrasiService.list).mockReset().mockResolvedValue(daftar())
  vi.mocked(logIntegrasiService.tarikEkinerja).mockReset().mockResolvedValue({ jenis: 'skp_periodik', tahun: 2026, bulan: 10, antre: true })
})

describe('IntegrasiAdminView — log integrasi', () => {
  it('memuat halaman 1 tanpa filter dan menampilkan kolom + badge status', async () => {
    const wrapper = await mountView()

    expect(logIntegrasiService.list).toHaveBeenCalledWith(query())
    const gagal = wrapper.get('[data-testid="integrasi-log-row-9"]').text()
    expect(gagal).toContain('BSrE (e-sign)')
    expect(gagal).toContain('Keluar')
    expect(gagal).toContain('sign_pdf')
    expect(gagal).toContain('500')
    expect(gagal).toContain('E500')
    expect(gagal).toContain('Gagal')
    expect(gagal).toContain(formatApiDateTime('2026-11-02 03:00:00')!)

    const sukses = wrapper.get('[data-testid="integrasi-log-row-4"]').text()
    expect(sukses).toContain('SIMAPI')
    expect(sukses).toContain('Masuk')
    expect(sukses).toContain('Sukses')
    expect(sukses).toContain('—')
    expect(wrapper.get('[data-testid="integrasi-log-total"]').text()).toBe('2 log')
  })

  it('daftar kosong menampilkan pesan kosong', async () => {
    vi.mocked(logIntegrasiService.list).mockResolvedValue(daftar({ items: [], total: 0 }))
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Belum ada log integrasi yang cocok.')
  })

  it('filter layanan & status → parameter query, halaman kembali ke 1; pilihan layanan dari layanan_tersedia', async () => {
    vi.mocked(logIntegrasiService.list).mockResolvedValue(daftar({ total: 60 }))
    const wrapper = await mountView()

    const opsi = wrapper.findAll('[data-testid="integrasi-filter-layanan"] select option').map((o) => o.attributes('value'))
    expect(opsi).toEqual(expect.arrayContaining(['bsre', 'simapi', 'bkn']))

    wrapper.getComponent({ name: 'UiPagination' }).vm.$emit('update:page', 2)
    await flushPromises()
    expect(lastQuery()).toEqual(query({ page: 2 }))

    await wrapper.get('[data-testid="integrasi-filter-layanan"] select').setValue('bkn')
    await flushPromises()
    expect(lastQuery()).toEqual(query({ layanan: 'bkn' }))

    await wrapper.get('[data-testid="integrasi-filter-status"] select').setValue('2')
    await flushPromises()
    expect(lastQuery()).toEqual(query({ layanan: 'bkn', status: '2' }))
  })

  it('filter tanggal → tanggal_mulai/tanggal_selesai; Hapus filter mengosongkan semuanya', async () => {
    const wrapper = await mountView()
    const [mulai, selesai] = wrapper.findAllComponents(UiTextField)

    mulai!.vm.$emit('update:modelValue', '2026-11-01')
    await flushPromises()
    selesai!.vm.$emit('update:modelValue', '2026-11-09')
    await flushPromises()
    expect(lastQuery()).toEqual(query({ tanggal_mulai: '2026-11-01', tanggal_selesai: '2026-11-09' }))

    await wrapper.get('[data-testid="integrasi-filter-reset"]').trigger('click')
    await flushPromises()
    expect(lastQuery()).toEqual(query())
    expect(wrapper.find('[data-testid="integrasi-filter-reset"]').exists()).toBe(false)
  })

  it('paginasi memuat halaman yang dipilih', async () => {
    vi.mocked(logIntegrasiService.list).mockResolvedValue(daftar({ total: 60 }))
    const wrapper = await mountView()

    wrapper.getComponent({ name: 'UiPagination' }).vm.$emit('update:page', 3)
    await flushPromises()
    expect(lastQuery()).toEqual(query({ page: 3 }))
  })

  it('menu ⋮: satu aksi "Lihat detail" → dialog detail berisi pesan teredaksi', async () => {
    const wrapper = await mountView()

    const aksi = await rowMenuActions(wrapper, 'integrasi-actions-9')
    expect(aksi.map((a) => [a.key, a.label, a.danger])).toEqual([['detail', 'Lihat detail', false]])
    expect(wrapper.get('[data-testid="integrasi-actions-9"]').attributes('aria-label')).toBe('Aksi untuk log BSrE (e-sign) sign_pdf #9')

    await selectRowAction(wrapper, 'integrasi-actions-9', 'detail')
    const dialog = document.body.querySelector('[data-testid="integrasi-log-detail"]')
    expect(dialog).not.toBeNull()
    expect(dialog!.textContent).toContain('Gagal tanda tangan (teredaksi)')
    expect(dialog!.textContent).toContain('812 ms')
    expect(dialog!.textContent).toContain('cuti:12')
  })

  it('403 dari backend tampil sebagai pesan galat', async () => {
    vi.mocked(logIntegrasiService.list).mockRejectedValue(apiError(403, 'Akses ditolak.'))
    const wrapper = await mountView()

    expect(wrapper.get('[role="alert"]').text()).toBe('Akses ditolak.')
    expect(wrapper.text()).toContain('Belum ada log integrasi yang cocok.')
  })

  it('422 filter ditandai per field', async () => {
    vi.mocked(logIntegrasiService.list).mockRejectedValue(apiError(422, 'Parameter daftar tidak valid.', { tanggal_selesai: ['Tanggal selesai tidak boleh sebelum tanggal mulai.'] }))
    const wrapper = await mountView()

    expect(wrapper.get('[role="alert"]').text()).toContain('Filter tidak valid')
    expect(wrapper.get('[data-testid="integrasi-filter-selesai"]').text()).toContain('Tanggal selesai tidak boleh sebelum tanggal mulai.')
  })
})

describe('IntegrasiAdminView — tarik ulang e-Kinerja', () => {
  async function bukaDialog(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('[data-testid="integrasi-tarik-buka"]').trigger('click')
    await flushPromises()
  }

  async function kirim(): Promise<void> {
    document.body.querySelector<HTMLFormElement>('[data-testid="integrasi-tarik-form"]')!.dispatchEvent(new Event('submit'))
    await flushPromises()
  }

  it('SKP periodik dengan bulan → {jenis, tahun, bulan}; 202 → pesan antre', async () => {
    const wrapper = await mountView()
    await bukaDialog(wrapper)

    const dialog = wrapper.getComponent(TarikEkinerjaDialog)
    dialog.findComponent(UiTextField).vm.$emit('update:modelValue', '2026-10')
    await flushPromises()
    await kirim()

    expect(logIntegrasiService.tarikEkinerja).toHaveBeenCalledWith({ jenis: 'skp_periodik', tahun: 2026, bulan: 10 })
    expect(wrapper.get('[role="status"]').text()).toContain('SKP periodik 10/2026 masuk antrean')
  })

  it('SKP periodik tanpa bulan → hanya {jenis} (periode terpilih otomatis)', async () => {
    vi.mocked(logIntegrasiService.tarikEkinerja).mockResolvedValue({ jenis: 'skp_periodik', tahun: null, bulan: null, antre: true })
    const wrapper = await mountView()
    await bukaDialog(wrapper)
    await kirim()

    expect(logIntegrasiService.tarikEkinerja).toHaveBeenCalledWith({ jenis: 'skp_periodik' })
    expect(wrapper.get('[role="status"]').text()).toContain('periode terpilih otomatis')
  })

  it('jenis periode → hanya {jenis: periode}, tanpa isian bulan', async () => {
    vi.mocked(logIntegrasiService.tarikEkinerja).mockResolvedValue({ jenis: 'periode', tahun: null, bulan: null, antre: true })
    const wrapper = await mountView()
    await bukaDialog(wrapper)

    document.body.querySelector<HTMLElement>('[data-testid="integrasi-tarik-jenis-periode"]')!.click()
    await flushPromises()
    expect(wrapper.getComponent(TarikEkinerjaDialog).findComponent(UiTextField).exists()).toBe(false)
    await kirim()

    expect(logIntegrasiService.tarikEkinerja).toHaveBeenCalledWith({ jenis: 'periode' })
    expect(wrapper.get('[role="status"]').text()).toContain('daftar periode masuk antrean')
  })

  it('422 tahun/bulan tampil di isian bulan; dialog tetap terbuka', async () => {
    vi.mocked(logIntegrasiService.tarikEkinerja).mockRejectedValue(apiError(422, 'Validasi gagal.', { bulan: ['Bulan wajib 1-12 bila tahun diisi.'] }))
    const wrapper = await mountView()
    await bukaDialog(wrapper)

    const dialog = wrapper.getComponent(TarikEkinerjaDialog)
    dialog.findComponent(UiTextField).vm.$emit('update:modelValue', '2026-10')
    await flushPromises()
    await kirim()

    expect(document.body.querySelector('[data-testid="integrasi-tarik-dialog"]')!.textContent).toContain('Bulan wajib 1-12 bila tahun diisi.')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })
})

describe('route & menu Integrasi', () => {
  it('route /integrasi hanya role 1', () => {
    const rute = router.getRoutes().find((r) => r.name === 'integrasi-admin')
    expect(rute?.path).toBe('/integrasi')
    expect(rute?.meta.roles).toEqual([Role.SUPER_ADMIN])
  })

  it('menu Pengaturan → Integrasi tampil untuk role 1 saja', () => {
    const punya = (role: (typeof Role)[keyof typeof Role]) =>
      buildNav(role).some((g) => g.items.some((i) => i.key === 'integrasi'))

    expect(punya(Role.SUPER_ADMIN)).toBe(true)
    for (const role of [Role.PEGAWAI, Role.ADMIN_SATKER, Role.ADMIN_VIEW_ESELON1, Role.MENTERI, Role.PTT, Role.PPPK, Role.PIMPINAN]) {
      expect(punya(role)).toBe(false)
    }
  })
})
