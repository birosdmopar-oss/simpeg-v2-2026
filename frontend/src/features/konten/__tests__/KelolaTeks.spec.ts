/**
 * Kelola berita (persetujuan hanya role 1), DIBO (aksi status), dan master sederhana (urutan). Service di-mock;
 * dialog form di-stub. Aksi dibaca dari menu ⋮ yang dibuka.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/features/auth/stores/auth.store'
import type { User } from '@/features/auth/types'

const s = vi.hoisted(() => ({
  berita: { list: vi.fn(), proses: vi.fn(), remove: vi.fn() },
  dibo: { list: vi.fn(), setStatus: vi.fn(), remove: vi.fn() },
  master: { list: vi.fn(), setStatus: vi.fn(), geser: vi.fn(), remove: vi.fn(), simpan: vi.fn() },
  popup: { opsiUnitSatker: vi.fn(), opsiPegawai: vi.fn() },
}))

vi.mock('../services/kelolaTeks.service', () => ({
  beritaKelolaService: s.berita,
  diboKelolaService: s.dibo,
  masterKontenService: () => s.master,
}))
vi.mock('../services/kelola.service', () => ({ kelolaService: () => s.popup }))

import BeritaKelolaView from '../views/BeritaKelolaView.vue'
import DiboKelolaView from '../views/DiboKelolaView.vue'
import MasterKontenView from '../views/MasterKontenView.vue'

async function pasang(komponen: object, role: number, props: Record<string, unknown> = {}) {
  setActivePinia(createPinia())
  useAuthStore().user = { username: 'uji', user_level: role } as User
  const w = mount(komponen, {
    props,
    global: { stubs: { BeritaFormDialog: true, DiboFormDialog: true } },
    attachTo: document.body,
  })
  await flushPromises()
  return w
}

async function aksi(w: { get: (q: string) => { trigger: (e: string) => Promise<unknown> } }, testid: string): Promise<string[]> {
  await w.get(`[data-testid="${testid}"]`).trigger('click')
  await flushPromises()
  return [...document.body.querySelectorAll('[data-action]')].map((e) => e.getAttribute('data-action') ?? '')
}

beforeEach(() => {
  document.body.innerHTML = ''
  vi.clearAllMocks()
  s.popup.opsiUnitSatker.mockResolvedValue([])
  s.popup.opsiPegawai.mockResolvedValue([])
  s.master.list.mockResolvedValue([
    { id: '1', nama: 'Umum', urutan: 1, status: '1' },
    { id: '2', nama: 'Cuti', urutan: 2, status: '2' },
  ])
})

describe('BeritaKelolaView', () => {
  const baris = { id: '5', judul: 'Berita A', kategori: 'Umum', id_kategori: '1', penulis: 'Budi', publish_at: '2026-11-01', status: '0' }

  beforeEach(() => s.berita.list.mockResolvedValue({ items: [baris], total: 1 }))

  it('role 1 melihat Setujui/Tolak pada berita menunggu, lalu memanggil proses', async () => {
    s.berita.proses.mockResolvedValue(undefined)
    const w = await pasang(BeritaKelolaView, 1)
    expect(await aksi(w, 'konten-berita-actions-5')).toEqual(['edit', 'approve', 'reject', 'delete'])
    ;(document.body.querySelector('[data-action="approve"]') as HTMLElement).click()
    await flushPromises()
    expect(s.berita.proses).toHaveBeenCalledWith('5', 'setuju')
    w.unmount()
  })

  it('role 3 tidak melihat persetujuan', async () => {
    const w = await pasang(BeritaKelolaView, 3)
    expect(await aksi(w, 'konten-berita-actions-5')).toEqual(['edit', 'delete'])
    expect(w.text()).toContain('menunggu persetujuan admin')
    w.unmount()
  })

  it('Hapus meminta konfirmasi dulu', async () => {
    const w = await pasang(BeritaKelolaView, 1)
    await aksi(w, 'konten-berita-actions-5')
    ;(document.body.querySelector('[data-action="delete"]') as HTMLElement).click()
    await flushPromises()
    expect(s.berita.remove).not.toHaveBeenCalled()
    w.unmount()
  })
})

describe('DiboKelolaView', () => {
  it('menu aktif: Edit, Nonaktifkan, Hapus; Nonaktifkan memanggil service', async () => {
    s.dibo.list.mockResolvedValue({ items: [{ id: '3', judul: 'Dok', jenis: 'Umum', id_jenis: '1', keterangan: '', status: '1' }], total: 1 })
    s.dibo.setStatus.mockResolvedValue(undefined)
    const w = await pasang(DiboKelolaView, 1)
    expect(await aksi(w, 'konten-dibo-actions-3')).toEqual(['edit', 'deactivate', 'delete'])
    ;(document.body.querySelector('[data-action="deactivate"]') as HTMLElement).click()
    await flushPromises()
    expect(s.dibo.setStatus).toHaveBeenCalledWith('3', '2')
    w.unmount()
  })

  it('baris dihapus hanya punya Pulihkan', async () => {
    s.dibo.list.mockResolvedValue({ items: [{ id: '4', judul: 'Lama', jenis: '', id_jenis: '', keterangan: '', status: '10' }], total: 1 })
    const w = await pasang(DiboKelolaView, 1)
    expect(await aksi(w, 'konten-dibo-actions-4')).toEqual(['restore'])
    w.unmount()
  })
})

describe('MasterKontenView', () => {
  it('baris pertama tidak bisa dinaikkan; urutan memanggil geser', async () => {
    s.master.geser.mockResolvedValue(undefined)
    const w = await pasang(MasterKontenView, 1, { jenis: 'dibo-jenis' })
    expect(await aksi(w, 'konten-dibo-jenis-actions-1')).toEqual(['edit', 'deactivate', 'up', 'down', 'delete'])
    const naik = document.body.querySelector('[data-action="up"]') as HTMLElement
    expect(naik.getAttribute('aria-disabled') === 'true' || (naik as HTMLButtonElement).disabled || naik.hasAttribute('data-disabled')).toBe(true)
    ;(document.body.querySelector('[data-action="down"]') as HTMLElement).click()
    await flushPromises()
    expect(s.master.geser).toHaveBeenCalledWith('1', 'turun')
    w.unmount()
  })

  it('judul mengikuti jenis', async () => {
    const w = await pasang(MasterKontenView, 1, { jenis: 'berita-kategori' })
    expect(w.get('h1').text()).toBe('Kategori Berita')
    w.unmount()
  })
})
