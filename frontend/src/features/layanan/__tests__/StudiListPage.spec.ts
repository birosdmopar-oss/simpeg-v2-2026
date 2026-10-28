/**
 * Paket 5 (MAKE-026) — StudiListPage (TB): tab Perlu Persetujuan mengikuti `adalah_pic` dari opsi server (bukan dari
 * role/tebakan FE), tombol Ajukan mengikuti `can_create` respons daftar, dan ⋮ baris memakai `aksi {}` server (AGENTS §1,
 * dipilih lewat `data-action`). Service di-mock; bentuk amplop diuji di studi.service.amplop.spec.ts.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/studi.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../services/studi.service')>()
  const layanan = {
    list: vi.fn(),
    opsi: vi.fn(),
    detail: vi.fn(),
    hapus: vi.fn(),
    surat: vi.fn(),
    proses: vi.fn(),
    tambah: vi.fn(),
    ubah: vi.fn(),
    lampiran: vi.fn(),
    tambahPerpanjangan: vi.fn(),
    ubahPerpanjangan: vi.fn(),
    hapusPerpanjangan: vi.fn(),
  }
  return { ...actual, tbService: { ...layanan, jenis: 'tb' }, ibService: { ...layanan, jenis: 'ib' } }
})

import { Role } from '@/features/auth/types'
import { rowMenuActions } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { tbService, type StudiItem } from '../services/studi.service'
import StudiListPage from '../views/StudiListPage.vue'
import TbPage from '../views/TbPage.vue'

const svc = vi.mocked(tbService)

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

const item = (id: number, aksi: Record<string, boolean> = {}): StudiItem => ({
  id,
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  id_jenjang_pendidikan: 4,
  institusi_pendidikan: 'Universitas Uji',
  jurusan_pendidikan: 'Teknik',
  lokasi_pendidikan: 'Bandung',
  lembaga_yang_mendanai: 'Instansi',
  up: 'Pusat',
  tgl_mulai: '2027-01-01',
  tgl_akhir: '2028-12-31',
  dalam_negeri: 1,
  tembusan: null,
  keterangan: null,
  status: 0,
  label_status: 'Menunggu Verifikasi PIC',
  nada_status: 'warning',
  tahap: [],
  aksi: { ubah: true, hapus: false, proses: false, unduh_surat: false, perpanjangan: false, ...aksi },
  sk: { no_sk: null, tgl_sk: null, ds: null, no_ds: null, tgl_ds: null, perihal_ds: null },
  reason_note: null,
})

async function mountHalaman(role: number) {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/tb', name: 'layanan-tb', component: TbPage })
  router.addRoute({ path: '/layanan/tb/:id', name: 'layanan-tb-detail', component: { render: () => null } })
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: { render: () => null } })
  await router.push('/layanan/tb')
  await router.isReady()
  const wrapper = mount(StudiListPage, { props: { jenis: 'tb' }, global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper }
}

const tabLabels = (): string[] => [...document.body.querySelectorAll('[role="tab"]')].map((t) => t.textContent?.trim() ?? '')

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
})

describe('StudiListPage — Tugas Belajar', () => {
  it('pegawai dengan adalah_pic = false: tanpa tab Perlu Persetujuan (penanda dari server)', async () => {
    svc.opsi.mockResolvedValue({
      jenjang_pendidikan: [],
      up: 'Pusat',
      pegawai: null,
      slot_berkas: [],
      berkas_wajib: true,
      can_create: true,
      adalah_pic: false,
    })
    svc.list.mockResolvedValue({ items: [item(1)], total: 1, page: 1, per_page: 20, mode: 'saya', can_create: true, can_process: false })

    await mountHalaman(Role.PEGAWAI)

    const tabs = tabLabels()
    expect(tabs).toContain('Saya')
    expect(tabs.some((t) => t.includes('Perlu Persetujuan'))).toBe(false)
    expect(q('[data-testid="tb-ajukan"]')).not.toBeNull()
  })

  it('PIC aktif (adalah_pic = true): tab Perlu Persetujuan muncul', async () => {
    svc.opsi.mockResolvedValue({
      jenjang_pendidikan: [],
      up: 'Pusat',
      pegawai: null,
      slot_berkas: [],
      berkas_wajib: true,
      can_create: false,
      adalah_pic: true,
    })
    svc.list.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 20, mode: 'saya', can_create: false, can_process: true })

    await mountHalaman(Role.PEGAWAI)

    expect(tabLabels().some((t) => t.includes('Perlu Persetujuan'))).toBe(true)
    expect(q('[data-testid="tb-ajukan"]')).toBeNull()
  })

  it('⋮ baris memakai aksi server: Edit ada, Setujui/Hapus disembunyikan bila server tidak mengizinkan', async () => {
    svc.opsi.mockResolvedValue({
      jenjang_pendidikan: [],
      up: 'Pusat',
      pegawai: null,
      slot_berkas: [],
      berkas_wajib: true,
      can_create: false,
      adalah_pic: false,
    })
    svc.list.mockResolvedValue({ items: [item(7)], total: 1, page: 1, per_page: 20, mode: 'admin', can_create: false, can_process: false })

    const { wrapper } = await mountHalaman(Role.SUPER_ADMIN)

    const menu = await rowMenuActions(wrapper, 'tb-actions-7')
    expect(menu.map((m) => m.key)).toEqual(['edit', 'detail'])
  })
})
