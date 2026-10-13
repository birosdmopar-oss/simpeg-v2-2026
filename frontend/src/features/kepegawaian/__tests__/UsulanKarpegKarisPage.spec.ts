/**
 * Halaman Usulan Karpeg/Karis (B-17, MAKE-007) — mode halaman usulan mandiri: pengusul (role 2) melihat usulan sendiri
 * (GET usulan/{jenis}/saya), mengajukan dengan berkas kolom `berkas[file_*]`, Edit/Hapus nonaktif untuk baris
 * Disetujui; role 1 melihat antrean (GET usulan/{jenis}) dengan filter status dan Setujui/Tolak; role 4 tanpa proses;
 * unduh berkas kolom; Karis/Karsu: judul menurut jenis kelamin dan syarat keluarga.
 */
import { DOMWrapper, enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../riwayat/usulan.service', () => ({ usulanService: { saya: vi.fn(), antrean: vi.fn(), unduh: vi.fn() } }))
vi.mock('../riwayat/riwayat.service', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../riwayat/riwayat.service')>()
  return { ...actual, riwayatService: { tabs: vi.fn(), list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(), process: vi.fn() } }
})

import { Role, type RoleCode } from '@/features/auth/types'
import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import { riwayatService } from '../riwayat/riwayat.service'
import { usulanService } from '../riwayat/usulan.service'
import UsulanKarpegKarisPage from '../views/UsulanKarpegKarisPage.vue'

import { NIP } from './fixtures'

const usulan = vi.mocked(usulanService)
const riwayat = vi.mocked(riwayatService)

const baris = (id: number, status: number, extra: Record<string, unknown> = {}) => ({
  id_riwayat_karpeg: id,
  id_riwayat_kariskarsu: id,
  nip: NIP,
  jenis_permohonan: 'Permohonan Pertama',
  file_1: 'kolom/a.pdf',
  file_2: 'kolom/b.pdf',
  file_3: 'kolom/c.pdf',
  file_4: 'kolom/d.pdf',
  file_5: 'kolom/e.pdf',
  file_surat_hilang: null,
  status,
  reason_note: status === 2 ? 'Foto buram' : null,
  created_at: '2026-10-01 03:00:00',
  ...extra,
})

const saya = (extra: Record<string, unknown> = {}) => ({
  pegawai: { nip: NIP, nama: 'Budi', glr_awal: null, glr_akhir: null, jenis_kelamin: 1 },
  can_create: true,
  can_edit: true,
  can_delete: true,
  syarat_terpenuhi: true,
  items: [baris(1, 1), baris(2, 0)],
  ...extra,
})

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of [...Object.values(usulan), ...Object.values(riwayat)]) fn.mockReset()
  usulan.saya.mockResolvedValue(saya())
  usulan.antrean.mockResolvedValue({ can_process: true, items: [baris(2, 0, { nama: 'Budi', satker: 'Biro SDM' })] })
})

async function mountPage(role: RoleCode, query = '') {
  loginAs(role)
  const router = createShellRouter()
  router.addRoute({ path: '/karpeg-karis', name: 'karpeg-karis-usulan', component: UsulanKarpegKarisPage })
  await router.push(`/karpeg-karis${query}`)
  await router.isReady()
  const wrapper = mount(UsulanKarpegKarisPage, { global: { plugins: [router] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

const body = () => new DOMWrapper(document.body)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

describe('Usulan Karpeg/Karis — pengusul (role 2)', () => {
  it('memuat usulan saya; Edit/Hapus nonaktif untuk baris Disetujui; label ⋮ & testid', async () => {
    await mountPage(Role.PEGAWAI)
    expect(usulan.saya).toHaveBeenCalledWith('karpeg')
    expect(usulan.antrean).not.toHaveBeenCalled()
    const disetujui = await rowMenuActions(body(), 'usulan-actions-1')
    expect(disetujui.map((i) => [i.key, i.disabled])).toEqual([['edit', true], ['berkas', false], ['hapus', true]])
    const menunggu = await rowMenuActions(body(), 'usulan-actions-2')
    expect(menunggu.map((i) => i.disabled)).toEqual([false, false, false])
    expect(q('[data-testid="usulan-actions-2"]')?.getAttribute('aria-label')).toContain('Aksi untuk Kartu Pegawai')
  })

  it('ajukan: berkas wajib dicek; kirim create(nip, karpeg, fields, values, { file_1..5 }) dengan kunci kolom', async () => {
    riwayat.create.mockResolvedValue({})
    await mountPage(Role.PEGAWAI)
    q<HTMLButtonElement>('[data-testid="usulan-add"]')?.click()
    await flushPromises()

    const select = q<HTMLSelectElement>('[data-field="jenis_permohonan"] select')!
    select.value = 'Permohonan Pertama'
    select.dispatchEvent(new Event('change'))
    q('[data-testid="usulan-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(q('[data-testid="usulan-berkas-error-file_1"]')?.textContent).toContain('SK CPNS wajib diunggah.')
    expect(q('[data-testid="usulan-berkas-error-file_surat_hilang"]')).toBeNull()
    expect(riwayat.create).not.toHaveBeenCalled()

    const files: Record<string, File> = {}
    for (const kolom of ['file_1', 'file_2', 'file_3', 'file_4', 'file_5']) {
      files[kolom] = new File(['%PDF'], `${kolom}.pdf`, { type: 'application/pdf' })
      const input = q<HTMLInputElement>(`[data-testid="usulan-berkas-${kolom}"] input[type="file"]`)!
      Object.defineProperty(input, 'files', { value: [files[kolom]], configurable: true })
      input.dispatchEvent(new Event('change'))
    }
    await flushPromises()
    q('[data-testid="usulan-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(riwayat.create).toHaveBeenCalledTimes(1)
    const [nip, jenis, , values, berkas] = riwayat.create.mock.calls[0]!
    expect([nip, jenis, values]).toEqual([NIP, 'karpeg', { jenis_permohonan: 'Permohonan Pertama' }])
    expect(berkas).toEqual(files)
    expect(usulan.saya).toHaveBeenCalledTimes(2)
  })

  it('berkas bersyarat yang syaratnya tidak terpenuhi tidak dikirim (surat hilang untuk permohonan pertama)', async () => {
    riwayat.create.mockResolvedValue({})
    await mountPage(Role.PEGAWAI)
    q<HTMLButtonElement>('[data-testid="usulan-add"]')?.click()
    await flushPromises()
    const select = q<HTMLSelectElement>('[data-field="jenis_permohonan"] select')!
    select.value = 'Permohonan Pertama'
    select.dispatchEvent(new Event('change'))
    for (const kolom of ['file_1', 'file_2', 'file_3', 'file_4', 'file_5', 'file_surat_hilang']) {
      const input = q<HTMLInputElement>(`[data-testid="usulan-berkas-${kolom}"] input[type="file"]`)!
      Object.defineProperty(input, 'files', { value: [new File(['%PDF'], `${kolom}.pdf`, { type: 'application/pdf' })], configurable: true })
      input.dispatchEvent(new Event('change'))
    }
    await flushPromises()
    q('[data-testid="usulan-form"]')?.dispatchEvent(new Event('submit'))
    await flushPromises()
    expect(Object.keys(riwayat.create.mock.calls[0]![4] ?? {}).sort()).toEqual(['file_1', 'file_2', 'file_3', 'file_4', 'file_5'])
  })

  it('hapus (pengusul): NIP dari identitas usulan/saya', async () => {
    usulan.saya.mockResolvedValue(saya({ items: [baris(2, 0, { nip: 'NIP-BARIS-LAIN' })] }))
    riwayat.remove.mockResolvedValue()
    await mountPage(Role.PEGAWAI)
    await selectRowAction(body(), 'usulan-actions-2', 'hapus')
    ;[...document.body.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Ya, hapus')?.click()
    await flushPromises()
    expect(riwayat.remove).toHaveBeenCalledWith(NIP, 'karpeg', '2')
  })

  it('Karis/Karsu: judul menurut jenis kelamin; tanpa riwayat keluarga → Ajukan nonaktif + keterangan', async () => {
    usulan.saya.mockResolvedValue(saya({ syarat_terpenuhi: false, items: [] }))
    await mountPage(Role.PEGAWAI, '?jenis=kariskarsu')
    expect(usulan.saya).toHaveBeenCalledWith('kariskarsu')
    expect(q('[data-testid="usulan-kariskarsu"]')?.textContent).toContain('Usulan Kartu Istri (Karis)')
    expect(q<HTMLButtonElement>('[data-testid="usulan-add"]')?.disabled).toBe(true)
    expect(q('[data-testid="usulan-syarat"]')).not.toBeNull()
  })

  it('berkas: dialog daftar kolom; Unduh memanggil GET …/berkas/{kolom}', async () => {
    usulan.unduh.mockResolvedValue(new Blob(['x']))
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:x')
    globalThis.URL.revokeObjectURL = vi.fn()
    await mountPage(Role.PEGAWAI)
    await selectRowAction(body(), 'usulan-actions-1', 'berkas')
    expect(q('[data-testid="usulan-unduh-file_surat_hilang"]')).toBeNull()
    q<HTMLButtonElement>('[data-testid="usulan-unduh-file_1"]')?.click()
    await flushPromises()
    expect(usulan.unduh).toHaveBeenCalledWith(NIP, 'karpeg', '1', 'file_1')
  })
})

describe('Usulan Karpeg/Karis — antrean', () => {
  it('role 1: antrean + filter status; Setujui → process', async () => {
    riwayat.process.mockResolvedValue({})
    await mountPage(Role.SUPER_ADMIN)
    expect(usulan.antrean).toHaveBeenCalledWith('karpeg', '')
    expect(q('[data-testid="usulan-row-2"]')?.textContent).toContain('Biro SDM')

    const filter = q<HTMLSelectElement>('[data-field="status"] select')!
    filter.value = '0'
    filter.dispatchEvent(new Event('change'))
    await flushPromises()
    expect(usulan.antrean).toHaveBeenLastCalledWith('karpeg', '0')

    // AGENTS.md §1: Setujui/Tolak → Berkas.
    expect((await rowMenuActions(body(), 'usulan-actions-2')).map((i) => i.key)).toEqual(['setujui', 'tolak', 'berkas'])
    await selectRowAction(body(), 'usulan-actions-2', 'setujui')
    q<HTMLButtonElement>('[data-testid="approval-confirm"]')?.click()
    await flushPromises()
    expect(riwayat.process).toHaveBeenCalledWith(NIP, 'karpeg', '2', { aksi: 'setujui', reason_note: '' })
  })

  it('role 4: antrean tanpa Setujui/Tolak', async () => {
    usulan.antrean.mockResolvedValue({ can_process: false, items: [baris(2, 0, { nama: 'Budi' })] })
    await mountPage(Role.ADMIN_VIEW_ESELON1)
    expect((await rowMenuActions(body(), 'usulan-actions-2')).map((i) => i.key)).toEqual(['berkas'])
  })
})
