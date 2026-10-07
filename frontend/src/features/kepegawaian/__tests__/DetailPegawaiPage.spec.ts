/**
 * DetailPegawaiPage (B-20) — data dari GET pegawai/{nip}; tab = "Data Umum" + descriptor `data.tabs` (urutan backend,
 * hanya can_view, hanya jenis yang punya berkas registry; karpeg/kariskarsu tidak pernah jadi tab); keadaan galat
 * 403/404/501 tanpa data contoh; ?tab= tersimpan; aksi cetak/arsip/hapus memberi tahu belum tersedia.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({ pegawaiService: { detail: vi.fn(), list: vi.fn() } }))
vi.mock('../riwayat/riwayat.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../riwayat/riwayat.service')>()
  return { ...actual, riwayatService: { list: vi.fn().mockResolvedValue([]), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn() } }
})

import { Role, type RoleCode } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { pegawaiService } from '../services/pegawai.service'
import DetailPegawaiPage from '../views/DetailPegawaiPage.vue'

import { apiError, descriptor, NIP, pegawaiDetail } from './fixtures'

const detail = vi.mocked(pegawaiService.detail)

const TABS = [
  descriptor('jabatan', 'Riwayat Jabatan'),
  descriptor('pendidikan', 'Riwayat Pendidikan'),
  descriptor('kgb', 'Riwayat KGB', { can_view: false }),
  descriptor('karpeg', 'Karpeg'),
  descriptor('lkh', 'Laporan Kerja Harian'),
]

async function mountDetail(path = `/pegawai/${NIP}`, role: RoleCode = Role.SUPER_ADMIN) {
  loginAs(role)
  const router = createShellRouter()
  await router.push(path)
  await router.isReady()
  const wrapper = mount(DetailPegawaiPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper, router }
}

const tabLabels = (w: ReturnType<typeof mount>) => w.findAll('[role="tab"]').map((t) => t.text())

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  detail.mockReset()
  detail.mockResolvedValue(pegawaiDetail(TABS))
})

describe('DetailPegawaiPage — data', () => {
  it('memanggil GET pegawai/{nip}; kartu identitas dari kolom DDL (glr_awal, nama, glr_akhir, status, tgl_lahir)', async () => {
    const { wrapper } = await mountDetail()
    expect(detail).toHaveBeenCalledWith(NIP)
    expect(wrapper.get('[data-testid="pegawai-name"]').text()).toBe('Dr. Budi Contoh, S.E., M.M.')
    expect(wrapper.get('[data-testid="pegawai-status"]').text()).toBe('Aktif')
    expect(wrapper.get('[data-testid="pegawai-header"]').text()).toContain('1 Januari 1990')
  })

  it('tab Data Umum (bawaan) menampilkan biodata kolom pegawai, hanya-lihat (tanpa tombol simpan)', async () => {
    const { wrapper } = await mountDetail()
    const du = wrapper.get('[data-testid="data-umum"]')
    expect(du.get('[data-field="bpjs_kes"]').text()).toContain('0001234567890')
    expect(du.get('[data-field="jenis_kelamin"]').text()).toContain('Laki-laki')
    expect(du.get('[data-field="status_pernikahan"]').text()).toContain('Menikah')
    expect(du.get('[data-field="npwp"]').text()).toContain('—')
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it.each([
    [404, 'not-found', 'Pegawai tidak ditemukan'],
    [403, 'forbidden', 'Akses ditolak'],
    [501, 'unavailable', 'Data pegawai belum tersedia'],
  ] as const)('galat %i → keadaan "%s" tanpa data contoh', async (status, kind, title) => {
    detail.mockRejectedValue(apiError(status))
    const { wrapper } = await mountDetail()
    const card = wrapper.get('[data-testid="pegawai-failure"]')
    expect(card.attributes('data-kind')).toBe(kind)
    expect(card.text()).toContain(title)
    expect(wrapper.find('[data-testid="pegawai-header"]').exists()).toBe(false)
  })
})

describe('DetailPegawaiPage — tab dari descriptor', () => {
  it('urutan backend; can_view=false dan karpeg dibuang; lkh (non-engine) tetap tab', async () => {
    const { wrapper } = await mountDetail()
    expect(tabLabels(wrapper)).toEqual(['Data Umum', 'Riwayat Jabatan', 'Riwayat Pendidikan', 'Laporan Kerja Harian'])
  })

  it('memilih tab merender RiwayatTabHost jenis itu dan menyimpan ?tab=<slug>', async () => {
    const { wrapper, router } = await mountDetail()
    await wrapper.findAll('[role="tab"]').find((t) => t.text() === 'Riwayat Pendidikan')?.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.tab).toBe('pendidikan')
    expect(wrapper.find('[data-testid="riwayat-section-pendidikan"]').exists()).toBe(true)
  })

  it('?tab=lkh membuka tab non-engine ("belum tersedia"); ?tab yang tidak ada di descriptor kembali ke Data Umum', async () => {
    const lkh = await mountDetail(`/pegawai/${NIP}?tab=lkh`)
    expect(lkh.wrapper.find('[data-testid="riwayat-belum-tersedia"]').exists()).toBe(true)
    lkh.wrapper.unmount()

    const { wrapper } = await mountDetail(`/pegawai/${NIP}?tab=kgb`)
    expect(wrapper.get('[role="tab"][aria-selected="true"]').text()).toBe('Data Umum')
  })

  it('descriptor kosong → hanya tab Data Umum', async () => {
    detail.mockResolvedValue(pegawaiDetail([]))
    const { wrapper } = await mountDetail()
    expect(tabLabels(wrapper)).toEqual(['Data Umum'])
  })
})

describe('DetailPegawaiPage — aksi kartu', () => {
  it('Cetak: role 1,2,3,4,5 ya; PTT/PPPK/Pimpinan tidak; Hapus (⋯) hanya Super Admin', async () => {
    for (const [role, print, more] of [
      [Role.SUPER_ADMIN, true, true],
      [Role.PEGAWAI, true, false],
      [Role.PTT, false, false],
      [Role.PIMPINAN, false, false],
    ] as const) {
      const { wrapper } = await mountDetail(`/pegawai/${NIP}`, role)
      expect(wrapper.find('[data-testid="btn-cetak"]').exists(), `cetak role ${role}`).toBe(print)
      expect(wrapper.find('[data-testid="btn-more"]').exists(), `hapus role ${role}`).toBe(more)
      wrapper.unmount()
    }
  })

  it('"Arsip Kepegawaian" memberi tahu belum tersedia (B-18)', async () => {
    const { wrapper } = await mountDetail()
    await wrapper.get('[data-testid="btn-arsip"]').trigger('click')
    expect(wrapper.text()).toContain('Arsip Kepegawaian belum tersedia (task B-18).')
  })
})
