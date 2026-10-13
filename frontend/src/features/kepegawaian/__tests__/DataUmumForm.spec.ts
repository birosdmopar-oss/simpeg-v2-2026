/**
 * DataUmumForm (B-03, MAKE-010): isian per role ikut form legacy (nama/gelar/tgl lahir/jenis status hanya Super Admin;
 * jenis pegawai role 1/3), nilai awal dari detail (kerabat bebas → "Kerabat Lain"), kirim `PUT pegawai/{nip}/biodata`
 * (dengan foto → berkas), galat 422 per kolom + `errors.foto`, foto wajib bila pegawai belum punya foto.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/pegawai.service', () => ({ pegawaiService: { updateBiodata: vi.fn() } }))
vi.mock('@/features/master-data/services/master.service', () => ({ masterService: { options: vi.fn().mockResolvedValue([]) } }))

import { Role, type RoleCode } from '@/features/auth/types'
import { createShellRouter, loginAs } from '@/shared/layouts/__tests__/shellTestUtils'

import DataUmumForm from '../components/DataUmumForm.vue'
import { pegawaiService } from '../services/pegawai.service'
import type { PegawaiDetail } from '../types'

import { apiError, NIP, pegawaiDetail } from './fixtures'

const update = vi.mocked(pegawaiService.updateBiodata)

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const field = (name: string) =>
  q<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(
    `[data-field="${name}"] select, [data-field="${name}"] input, [data-field="${name}"] textarea, select[data-field="${name}"], input[data-field="${name}"]`,
  )

async function mountForm(role: RoleCode, detail: PegawaiDetail) {
  loginAs(role)
  const wrapper = mount(DataUmumForm, { props: { detail }, global: { plugins: [createShellRouter()] }, attachTo: document.body })
  await flushPromises()
  return wrapper
}

async function setInput(name: string, value: string): Promise<void> {
  const el = field(name)
  if (!el) throw new Error(`isian ${name} tidak ada`)
  el.value = value
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await flushPromises()
}

async function submit(): Promise<void> {
  q('[data-testid="data-umum-form"] form')?.dispatchEvent(new Event('submit'))
  await flushPromises()
  await new Promise((r) => setTimeout(r, 0))
  await flushPromises()
}

const lengkap = (extra: Parameters<typeof pegawaiDetail>[2] = {}) =>
  pegawaiDetail([], { npwp: '11', bpjs_kes: '22', no_taspen: '33', jenis_kerabat: 'Sepupu Jauh', no_telp_kerabat: '44', ada_foto: true }, extra)

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  update.mockReset()
  update.mockResolvedValue({ jalur: 'langsung', pegawai: lengkap(), pengajuan: null })
})

describe('DataUmumForm — isian per role', () => {
  it('Super Admin: nama, gelar, tanggal lahir, jenis pegawai, jenis status, TMT status tersedia', async () => {
    await mountForm(Role.SUPER_ADMIN, lengkap())
    for (const name of ['nama', 'glr_awal', 'glr_akhir', 'tgl_lahir', 'id_jenis_pegawai', 'id_jenis_status', 'tmt_status', 'email']) {
      expect(q(`[data-field="${name}"]`), name).not.toBeNull()
    }
    expect(q('[data-testid="data-umum-save"]')?.textContent).toContain('Simpan')
  })

  it('Pegawai: tanpa nama/gelar/tgl lahir/jenis pegawai/jenis status; tombol "Ajukan Perubahan"', async () => {
    await mountForm(Role.PEGAWAI, lengkap({ aksi: { ubah_biodata: true, ubah_langsung: false, koreksi_nip: false } }))
    for (const name of ['nama', 'glr_awal', 'tgl_lahir', 'id_jenis_pegawai', 'id_jenis_status', 'tmt_status']) {
      expect(q(`[data-field="${name}"]`), name).toBeNull()
    }
    expect(q('[data-field="npwp"]')).not.toBeNull()
    expect(q('[data-testid="data-umum-save"]')?.textContent).toContain('Ajukan Perubahan')
    expect(q('[data-testid="data-umum-form"]')?.textContent).toContain('berlaku setelah disetujui admin')
  })

  it('Admin Satker: jenis pegawai ada, nama tidak', async () => {
    await mountForm(Role.ADMIN_SATKER, lengkap({ aksi: { ubah_biodata: true, ubah_langsung: false, koreksi_nip: false } }))
    expect(q('[data-field="id_jenis_pegawai"]')).not.toBeNull()
    expect(q('[data-field="nama"]')).toBeNull()
  })
})

describe('DataUmumForm — kirim', () => {
  it('nilai awal dari detail; kerabat bebas data lama → Kerabat Lain + teks; kirim PUT tanpa foto', async () => {
    const wrapper = await mountForm(Role.PEGAWAI, lengkap({ aksi: { ubah_biodata: true, ubah_langsung: false, koreksi_nip: false } }))
    expect((field('jenis_kerabat_lain') as HTMLInputElement).value).toBe('Sepupu Jauh')
    await setInput('no_hp', '0812-9999')
    await submit()

    expect(update).toHaveBeenCalledTimes(1)
    const [nip, payload, foto] = update.mock.calls[0]
    expect(nip).toBe(NIP)
    expect(payload).toMatchObject({ no_hp: '0812-9999', jenis_kerabat: 'Kerabat Lain', jenis_kerabat_lain: 'Sepupu Jauh', email: 'budi@contoh.test' })
    expect(payload).not.toHaveProperty('nama')
    expect(foto).toBeNull()
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('422 per kolom ditampilkan di isian; errors.foto di isian foto; tidak emit saved', async () => {
    update.mockRejectedValue(apiError(422, { npwp: ['Wajib diisi (angka).'], foto: ['Foto pegawai wajib diunggah.'] }))
    const wrapper = await mountForm(Role.SUPER_ADMIN, lengkap())
    await submit()

    await vi.waitFor(() => expect(q('[data-field="npwp"]')?.textContent).toContain('Wajib diisi (angka).'))
    expect(q('[data-testid="data-umum-berkas-error-0"]')?.textContent).toContain('Foto pegawai wajib diunggah.')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('foto wajib dipilih bila pegawai belum punya foto (legacy)', async () => {
    await mountForm(Role.SUPER_ADMIN, pegawaiDetail([], { npwp: '1', bpjs_kes: '2', no_taspen: '3', jenis_kerabat: 'Anak', no_telp_kerabat: '4', ada_foto: false }))
    await submit()

    expect(update).not.toHaveBeenCalled()
    expect(q('[data-testid="data-umum-berkas-error-0"]')?.textContent).toContain('wajib diunggah')
  })
})
