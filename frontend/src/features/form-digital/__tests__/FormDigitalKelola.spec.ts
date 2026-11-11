/**
 * MAKE-083 — admin form digital: daftar + menu ⋮ (urutan aksi AGENTS.md bagian 1, `data-action`), ubah status, hapus lewat
 * ConfirmDialog; editor (tambah/urut/hapus pertanyaan, payload, terkunci = baca-saja); rekap kolom dinamis dari skema.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const s = vi.hoisted(() => ({
  svc: {
    daftar: vi.fn(),
    setStatus: vi.fn(),
    hapus: vi.fn(),
    detail: vi.fn(),
    buat: vi.fn(),
    ubah: vi.fn(),
    opsiUnitSatker: vi.fn(),
    target: vi.fn(),
    respons: vi.fn(),
    unduhXlsx: vi.fn(),
    unduhBerkas: vi.fn(),
  },
}))
vi.mock('../services/formDigital.service', () => ({ formDigitalService: s.svc }))
vi.mock('@/features/kepegawaian/services/pegawai.service', () => ({ pegawaiService: { list: vi.fn().mockResolvedValue({ items: [] }) } }))

import FormDigitalEditorDialog from '../components/FormDigitalEditorDialog.vue'
import { formDigitalRoutes } from '../routes'
import type { FormDetail, FormRingkas } from '../types'
import FormDigitalKelolaView from '../views/FormDigitalKelolaView.vue'
import FormDigitalResponsView from '../views/FormDigitalResponsView.vue'

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const semua = (sel: string) => [...document.body.querySelectorAll<HTMLElement>(sel)]

const baris = (id: number, extra: Partial<FormRingkas> = {}): FormRingkas => ({
  id,
  judul_form: `Form ${id}`,
  tgl_mulai: '2026-11-01',
  tgl_akhir: '2026-11-30',
  order: 1,
  keterangan: null,
  status: 1,
  jumlah_target: 3,
  sudah_mengisi: 1,
  belum_mengisi: 2,
  jumlah_jawaban: 1,
  terkunci: true,
  ...extra,
})

function router() {
  return createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { render: () => null } }, ...formDigitalRoutes] })
}

async function aksi(testid: string): Promise<string[]> {
  q<HTMLElement>(`[data-testid="${testid}"]`)!.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, button: 0 }))
  q<HTMLElement>(`[data-testid="${testid}"]`)!.click()
  await flushPromises()
  return semua('[data-action]').map((e) => e.getAttribute('data-action') ?? '')
}

beforeEach(() => {
  document.body.innerHTML = ''
  setActivePinia(createPinia())
  for (const fn of Object.values(s.svc)) fn.mockClear()
  s.svc.daftar.mockResolvedValue({ items: [baris(5), baris(6, { status: 2, terkunci: false, jumlah_jawaban: 0 })], total: 2, page: 1, per_page: 20 })
  s.svc.opsiUnitSatker.mockResolvedValue([{ value: '0', label: 'Seluruh kementerian' }])
  s.svc.setStatus.mockResolvedValue(undefined)
  s.svc.hapus.mockResolvedValue(undefined)
})

describe('FormDigitalKelolaView', () => {
  it('menu ⋮ mengikuti urutan baku dan status menentukan Aktifkan/Nonaktifkan', async () => {
    mount(FormDigitalKelolaView, { global: { plugins: [router()], stubs: { FormDigitalEditorDialog: true, FormDigitalTargetDialog: true } }, attachTo: document.body })
    await flushPromises()

    expect(q('[data-testid="form-digital-actions-5"]')?.getAttribute('aria-label')).toBe('Aksi untuk Form 5')
    expect(await aksi('form-digital-actions-5')).toEqual(['edit', 'deactivate', 'respons', 'target', 'delete'])
    q<HTMLElement>('[data-action="deactivate"]')!.click()
    await flushPromises()
    expect(s.svc.setStatus).toHaveBeenCalledWith(5, 2)
  })

  it('Hapus lewat ConfirmDialog', async () => {
    mount(FormDigitalKelolaView, { global: { plugins: [router()], stubs: { FormDigitalEditorDialog: true, FormDigitalTargetDialog: true } }, attachTo: document.body })
    await flushPromises()

    expect(await aksi('form-digital-actions-6')).toEqual(['edit', 'activate', 'respons', 'target', 'delete'])
    q<HTMLElement>('[data-action="delete"]')!.click()
    await flushPromises()
    expect(s.svc.hapus).not.toHaveBeenCalled()
    const tombol = semua('button').find((b) => b.textContent?.trim() === 'Hapus')
    tombol!.click()
    await flushPromises()
    expect(s.svc.hapus).toHaveBeenCalledWith(6)
  })
})

describe('FormDigitalEditorDialog', () => {
  it('tambah, urutkan, hapus pertanyaan lalu kirim payload', async () => {
    s.svc.buat.mockResolvedValue({})
    const w = mount(FormDigitalEditorDialog, { props: { open: false, idForm: null }, attachTo: document.body })
    await w.setProps({ open: true })
    await flushPromises()

    const isi = (sel: string, v: string) => {
      const el = q<HTMLInputElement>(sel)!
      el.value = v
      el.dispatchEvent(new Event('input'))
    }
    isi('[data-testid="form-digital-editor-form"] input[name="judul_form"]', 'Survei')
    isi('[data-testid="form-digital-q-0"] input', 'Pertama')
    q<HTMLButtonElement>('[data-testid="form-digital-tambah-q"]')!.click()
    await flushPromises()
    isi('[data-testid="form-digital-q-1"] input', 'Kedua')
    q<HTMLButtonElement>('[data-testid="form-digital-q-1"] [data-q-aksi="naik"]')!.click()
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="form-digital-tambah-q"]')!.click()
    await flushPromises()
    q<HTMLButtonElement>('[data-testid="form-digital-q-2"] [data-q-aksi="hapus"]')!.click()
    await flushPromises()

    q<HTMLButtonElement>('[data-testid="form-digital-simpan"]')!.click()
    await flushPromises()

    const input = s.svc.buat.mock.calls[0]?.[0]
    expect(input.judul_form).toBe('Survei')
    expect(input.form.map((p: { label: string }) => p.label)).toEqual(['Kedua', 'Pertama'])
    expect(w.emitted('saved')).toHaveLength(1)
  })

  it('form terkunci: pertanyaan baca-saja tanpa tombol tambah/urut', async () => {
    const detail: FormDetail = {
      ...baris(5),
      form: { versi: 1, pertanyaan: [{ id: 'q1', tipe: 'teks', label: 'Nama', wajib: true }] },
      target: { unit_satker: ['0'], pegawai: [], kecuali: [], label_pegawai: {} },
    }
    s.svc.detail.mockResolvedValue(detail)
    const w = mount(FormDigitalEditorDialog, { props: { open: false, idForm: 5 }, attachTo: document.body })
    await w.setProps({ open: true })
    await flushPromises()

    expect(q('[data-testid="form-digital-terkunci"]')).not.toBeNull()
    expect(q('[data-testid="form-digital-tambah-q"]')).toBeNull()
    expect(q('[data-q-aksi="naik"]')).toBeNull()
    expect(q<HTMLInputElement>('[data-testid="form-digital-q-0"] input')!.disabled).toBe(true)
  })
})

describe('FormDigitalResponsView', () => {
  it('kolom dari skema dan jawaban dirender sebagai teks', async () => {
    s.svc.respons.mockResolvedValue({
      kolom: [
        { id: 'nama', label: 'Nama', tipe: 'teks' },
        { id: 'bukti', label: 'Bukti', tipe: 'berkas' },
      ],
      items: [{ id: 9, nip: '1', nama: 'Ani', unit: 'U', satker: 'S', jawaban: { nama: '<img src=x>', bukti: 'b.pdf' }, berkas: ['bukti'], diisi_at: '2026-11-11 08:00:00' }],
      total: 1,
      page: 1,
      per_page: 20,
    })
    mount(FormDigitalResponsView, { props: { idForm: 7 }, global: { plugins: [router()] }, attachTo: document.body })
    await flushPromises()

    expect(semua('[data-testid^="form-digital-kolom-"]').map((e) => e.textContent?.trim())).toEqual(['Nama', 'Bukti'])
    expect(q('[data-testid="form-digital-respons-9"] img')).toBeNull()
    expect(q('[data-testid="form-digital-respons-9"]')!.textContent).toContain('<img src=x>')
    expect(q('[data-testid="form-digital-berkas-9-bukti"]')).not.toBeNull()
  })
})
