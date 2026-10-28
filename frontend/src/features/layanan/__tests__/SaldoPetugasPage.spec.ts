/**
 * Paket 4 (MAKE-026) — CutiSaldoPage dan PetugasApprovalCutiPage: baris menampilkan ⋮ Edit/Hapus (AGENTS §1), item dipilih
 * lewat `data-action`, Hapus lewat ConfirmDialog memanggil service, dan tombol tambah membuka dialog. Service di-mock; bentuk
 * amplop diuji di cutiSaldo.petugas.amplop.spec.ts.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/cutiSaldo.service', () => ({
  cutiSaldoService: { list: vi.fn(), tambah: vi.fn(), ubah: vi.fn(), hapus: vi.fn() },
}))
vi.mock('../services/cutiPetugasApproval.service', () => ({
  cutiPetugasApprovalService: { list: vi.fn(), opsi: vi.fn(), tambah: vi.fn(), ubah: vi.fn(), hapus: vi.fn() },
}))

import { Role } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { cutiPetugasApprovalService, type PetugasApprovalCuti } from '../services/cutiPetugasApproval.service'
import { cutiSaldoService, type SaldoCuti } from '../services/cutiSaldo.service'
import CutiSaldoPage from '../views/CutiSaldoPage.vue'
import PetugasApprovalCutiPage from '../views/PetugasApprovalCutiPage.vue'

const saldo = (id: number, extra: Partial<SaldoCuti> = {}): SaldoCuti => ({
  id,
  nip: '198001012005011001',
  nama: 'Budi Santoso',
  tahun: 2026,
  sisa_cuti: 12,
  sisa_cuti_n1: 3,
  sisa_cuti_n2: 0,
  sisa_cuti_ditangguhkan: 0,
  utang_cuti: 0,
  jabatan: 'Staf',
  satker: 'Satker A',
  ...extra,
})

const petugas = (id: number): PetugasApprovalCuti => ({
  id,
  id_jenis_pa: 1,
  id_jenis_pegawai: 1,
  jenis_pegawai: 'PNS',
  id_unit_pemohon: 10,
  unit_pemohon: 'Biro SDM',
  id_group_jabatan_pemohon: 2,
  group_jabatan_pemohon: 'Pelaksana',
  id_sub_group_jabatan_pemohon: 5,
  sub_group_jabatan_pemohon: 'Staf',
  gol_ruang_pemohon: null,
  id_unit_petugas: 11,
  unit_petugas: 'Bagian Umum',
  id_satker_petugas: 4,
  satker_petugas: 'Satker A',
  id_group_jabatan_petugas: 3,
  group_jabatan_petugas: 'Struktural',
  id_sub_group_jabatan_petugas: 6,
  sub_group_jabatan_petugas: 'Kepala',
  jabatan_struktural: 'Kepala Bagian',
  jabatan_koordinasi: null,
  id_jabatan: 21,
  id_jabatan_koord: null,
  petugas: { nip: null, nama: null, jabatan: 'Kepala Bagian' },
  status: 1,
})

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

async function mountHalaman(komponen: unknown, role: number, path: string) {
  loginAs(role as never)
  const router = createShellRouter()
  router.addRoute({ path: '/layanan/cuti', name: 'layanan-cuti', component: { render: () => null } })
  router.addRoute({ path, name: 'halaman-uji', component: komponen as never })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(komponen as never, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return { wrapper }
}

afterEach(() => {
  document.body.innerHTML = ''
  window.sessionStorage.clear()
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  vi.mocked(cutiSaldoService.list).mockReset()
  vi.mocked(cutiSaldoService.hapus).mockReset()
  vi.mocked(cutiPetugasApprovalService.list).mockReset()
  vi.mocked(cutiPetugasApprovalService.opsi).mockReset()
})

describe('CutiSaldoPage', () => {
  it('baris saldo punya ⋮ Edit dan Hapus; Hapus lewat konfirmasi memanggil service', async () => {
    vi.mocked(cutiSaldoService.list).mockResolvedValue({ items: [saldo(12)], total: 1, page: 1, per_page: 20, tahun: 2026 })
    vi.mocked(cutiSaldoService.hapus).mockResolvedValue(undefined)
    const { wrapper } = await mountHalaman(CutiSaldoPage, Role.SUPER_ADMIN, '/layanan/cuti/saldo')

    expect(cutiSaldoService.list).toHaveBeenCalledWith(expect.objectContaining({ tahun: String(new Date().getFullYear()) }))
    const menu = await rowMenuActions(wrapper, 'saldo-actions-12')
    expect(menu.map((m) => m.key)).toEqual(['edit', 'hapus'])
    expect(menu.find((m) => m.key === 'hapus')?.danger).toBe(true)

    await selectRowAction(wrapper, 'saldo-actions-12', 'hapus')
    await flushPromises()
    expect(cutiSaldoService.hapus).not.toHaveBeenCalled()
    const ya = [...document.body.querySelectorAll<HTMLButtonElement>('button')].find((b) => b.textContent?.includes('Ya, hapus'))
    expect(ya).toBeDefined()
    ya?.click()
    await flushPromises()
    expect(cutiSaldoService.hapus).toHaveBeenCalledWith(12)
  })

  it('tombol Tambah Saldo membuka formulir', async () => {
    vi.mocked(cutiSaldoService.list).mockResolvedValue({ items: [], total: 0, page: 1, per_page: 20, tahun: 2026 })
    const { wrapper } = await mountHalaman(CutiSaldoPage, Role.ADMIN_SATKER, '/layanan/cuti/saldo')

    await wrapper.get('[data-testid="saldo-tambah"]').trigger('click')
    await flushPromises()
    expect(q('[data-testid="saldo-form-dialog"]')).not.toBeNull()
  })
})

describe('PetugasApprovalCutiPage', () => {
  it('baris petugas punya ⋮ Edit dan Hapus; nama petugas dari server tampil', async () => {
    vi.mocked(cutiPetugasApprovalService.list).mockResolvedValue({ items: [petugas(7)], total: 1, page: 1, per_page: 20 })
    const { wrapper } = await mountHalaman(PetugasApprovalCutiPage, Role.SUPER_ADMIN, '/layanan/cuti/petugas-approval')

    expect(wrapper.text()).toContain('Kepala Bagian')
    const menu = await rowMenuActions(wrapper, 'pa-actions-7')
    expect(menu.map((m) => m.key)).toEqual(['edit', 'hapus'])
  })
})
