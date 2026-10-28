/**
 * Paket 6 (MAKE-026) — Izin Belajar (IB) dan kontrak role TB/IB di FE:
 * (a) meta route roles: IB 1,2,3,7 (role 6 tidak masuk); TB 1,2,3,6 (role 7 tidak masuk);
 * (b) tab per role untuk IB: Saya (2/7), Perlu Persetujuan (adalah_pic dari server), Monitoring (1/3), Ajukan hanya bila can_create;
 * (c) berkas IB wajib untuk semua role termasuk role 1 (sumber: berkas_wajib server, bukan asumsi FE);
 * (e) IB tanpa efek final di FE: tanpa status awal yang dapat dipilih role 1, tanpa item Perpanjangan.
 * Service di-mock; bentuk amplop IB diuji di studi.service.amplop.spec.ts.
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

import { IB_ROLES, TB_ROLES } from '../roles'
import { LAYANAN_ROUTE, layananRoutes } from '../routes.layanan'
import { ibService, type StudiItem, type StudiOpsi } from '../services/studi.service'
import { STUDI_CONFIG } from '../studiConfig'
import { aksiBarisStudi } from '../rowActionsStudi'
import IbPage from '../views/IbPage.vue'
import StudiFormDialog from '../components/StudiFormDialog.vue'

const svc = vi.mocked(ibService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const tabLabels = (): string[] => [...document.body.querySelectorAll('[role="tab"]')].map((t) => t.textContent?.trim() ?? '')

const opsiIb = (over: Partial<StudiOpsi> = {}): StudiOpsi => ({
  jenjang_pendidikan: [{ id_jenjang_pendidikan: 4, jenjang_pendidikan: 'S2', jenjang_pendidikan_singkat: 'S2' }],
  up: 'Pusat',
  pegawai: null,
  slot_berkas: [
    { tag: 'file_sp', label: 'Surat Pengantar' },
    { tag: 'file_1', label: 'Surat Persetujuan' },
    { tag: 'file_2', label: 'Dokumen Pendukung' },
  ],
  berkas_wajib: true,
  can_create: true,
  adalah_pic: false,
  ...over,
})

const itemIb = (id: number, aksi: Record<string, boolean> = {}): StudiItem => ({
  id,
  nip: '198001012005011001',
  nama: 'Sari Lestari',
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

async function mountIb(role: number, opsi: StudiOpsi, daftarMode: 'saya' | 'pic' | 'admin' = 'saya', items: StudiItem[] = [itemIb(1)]) {
  loginAs(role as never)
  svc.opsi.mockResolvedValue(opsi)
  svc.list.mockResolvedValue({ items, total: items.length, page: 1, per_page: 20, mode: daftarMode, can_create: opsi.can_create, can_process: false })
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/ib', name: 'layanan-ib', component: IbPage })
  router.addRoute({ path: '/layanan/ib/:id', name: 'layanan-ib-detail', component: { render: () => null } })
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: { render: () => null } })
  await router.push('/layanan/ib')
  await router.isReady()
  const wrapper = mount(IbPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper }
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) if (typeof fn === 'function' && 'mockReset' in fn) (fn as ReturnType<typeof vi.fn>).mockReset()
})

describe('(a) meta route roles TB dan IB', () => {
  const roles = (name: string) => layananRoutes.find((r) => r.name === name)?.meta?.roles as readonly number[]

  it('IB: role 1, 2, 3, 7 diizinkan; role 6 (PTT) tidak', () => {
    expect(roles(LAYANAN_ROUTE.IB)).toEqual([Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PPPK])
    expect(roles(LAYANAN_ROUTE.IB)).not.toContain(Role.PTT)
    expect(roles(LAYANAN_ROUTE.IB_DETAIL)).toEqual(IB_ROLES)
  })

  it('TB: role 1, 2, 3, 6 diizinkan; role 7 (PPPK) tidak', () => {
    expect(roles(LAYANAN_ROUTE.TB)).toEqual([Role.SUPER_ADMIN, Role.PEGAWAI, Role.ADMIN_SATKER, Role.PTT])
    expect(roles(LAYANAN_ROUTE.TB)).not.toContain(Role.PPPK)
    expect(roles(LAYANAN_ROUTE.TB_DETAIL)).toEqual(TB_ROLES)
  })
})

describe('(b) tab per role untuk IB', () => {
  it('pegawai (2): Saya; tanpa Monitoring; tanpa Perlu Persetujuan bila adalah_pic = false', async () => {
    await mountIb(Role.PEGAWAI, opsiIb({ adalah_pic: false }))
    const tabs = tabLabels()
    expect(tabs).toContain('Saya')
    expect(tabs.some((t) => t.includes('Monitoring'))).toBe(false)
    expect(tabs.some((t) => t.includes('Perlu Persetujuan'))).toBe(false)
  })

  it('PPPK (7) sebagai PIC aktif: Saya dan Perlu Persetujuan (ditentukan server, bukan role)', async () => {
    await mountIb(Role.PPPK, opsiIb({ adalah_pic: true }))
    const tabs = tabLabels()
    expect(tabs).toContain('Saya')
    expect(tabs.some((t) => t.includes('Perlu Persetujuan'))).toBe(true)
  })

  it('admin (1): Monitoring; Perlu Persetujuan hanya bila adalah_pic = true', async () => {
    await mountIb(Role.SUPER_ADMIN, opsiIb({ adalah_pic: false }), 'admin')
    const tabs = tabLabels()
    expect(tabs.some((t) => t.includes('Monitoring'))).toBe(true)
    expect(tabs.some((t) => t.includes('Perlu Persetujuan'))).toBe(false)
  })

  it('tombol Ajukan IB tampil hanya bila can_create', async () => {
    const { wrapper } = await mountIb(Role.PEGAWAI, opsiIb({ can_create: false }))
    expect(wrapper.find('[data-testid="ib-ajukan"]').exists()).toBe(false)
  })
})

describe('(c) berkas IB wajib untuk semua role, sumber server', () => {
  it('role 1 IB: formulir menampilkan petunjuk wajib dari berkas_wajib (tidak bebas berkas)', async () => {
    loginAs(Role.SUPER_ADMIN as never)
    svc.opsi.mockResolvedValue(opsiIb({ berkas_wajib: true }))
    const cfg = STUDI_CONFIG.ib
    mount(StudiFormDialog, { props: { open: true, row: null, cfg, service: ibService }, attachTo: document.body })
    await flushPromises()

    expect(document.body.textContent).toContain('Wajib diunggah.')
  })

  it('slot berkas diambil dari opsi server (label dari server, bukan konfigurasi FE)', async () => {
    loginAs(Role.PEGAWAI as never)
    svc.opsi.mockResolvedValue(opsiIb({ slot_berkas: [{ tag: 'file_sp', label: 'Label Dari Server' }] }))
    mount(StudiFormDialog, { props: { open: true, row: null, cfg: STUDI_CONFIG.ib, service: ibService }, attachTo: document.body })
    await flushPromises()

    expect(document.body.textContent).toContain('Label Dari Server')
  })

  it('CR-072 butir 7: label slot berkas bertanda * bila berkas_wajib pada pengajuan baru', async () => {
    loginAs(Role.PEGAWAI as never)
    svc.opsi.mockResolvedValue(opsiIb({ berkas_wajib: true, slot_berkas: [{ tag: 'file_sp', label: 'Label Dari Server' }] }))
    mount(StudiFormDialog, { props: { open: true, row: null, cfg: STUDI_CONFIG.ib, service: ibService }, attachTo: document.body })
    await flushPromises()

    expect(document.body.textContent).toContain('Label Dari Server *')
  })
})

describe('(e) IB tanpa efek final di FE', () => {
  it('role 1 tidak punya pilihan status awal untuk IB (status selalu 0)', async () => {
    loginAs(Role.SUPER_ADMIN as never)
    svc.opsi.mockResolvedValue(opsiIb())
    mount(StudiFormDialog, { props: { open: true, row: null, cfg: STUDI_CONFIG.ib, service: ibService }, attachTo: document.body })
    await flushPromises()

    expect(q('[data-testid="ib-form"]')).not.toBeNull()
    expect(document.body.textContent).not.toContain('Status Awal')
  })

  it('item ⋮ IB tidak memuat Perpanjangan meski aksi server mengizinkan (pagar jenis)', () => {
    const row = itemIb(3, { perpanjangan: true })
    const aksi = aksiBarisStudi(row, STUDI_CONFIG.ib)
    expect(aksi.find((a) => a.key === 'perpanjangan')?.hidden).toBe(true)
  })

  it('item ⋮ IB: Setujui/Tolak mengikuti aksi.proses dari server (FE tidak menghitung)', async () => {
    const { wrapper } = await mountIb(Role.SUPER_ADMIN, opsiIb(), 'admin', [itemIb(9, { proses: true })])
    const menu = await rowMenuActions(wrapper, 'ib-actions-9')
    expect(menu.map((m) => m.key)).toEqual(expect.arrayContaining(['setujui', 'tolak']))
  })
})
