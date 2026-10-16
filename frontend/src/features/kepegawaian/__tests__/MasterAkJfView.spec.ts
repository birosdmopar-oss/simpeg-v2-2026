/**
 * MasterAkJfView (MAKE-021): master Angka Kredit JF `dm_ak_jf` — daftar + cari, menu ⋮ hanya Edit (tanpa hapus [K]),
 * tambah lewat pilihan berjenjang Group → Sub Group → Jenjang JF → Jabatan (dimuat server) + Gol. Ruang + AK, ubah hanya AK
 * (jabatan & gol. ruang terkunci), galat 422 dipetakan ke isian.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../angka-kredit/akJf.service', () => ({
  akJfService: { list: vi.fn(), opsi: vi.fn(), jabatan: vi.fn(), create: vi.fn(), update: vi.fn() },
}))

import { rowMenuActions, selectRowAction } from '@/shared/components/__tests__/rowActionsMenu.helpers'

import MasterAkJfView from '../angka-kredit/MasterAkJfView.vue'
import { akJfService, type AkJfRow } from '../angka-kredit/akJf.service'

const svc = vi.mocked(akJfService)

const baris = (over: Partial<AkJfRow> = {}): AkJfRow => ({
  id_jabatan: 11, id_pangkat: 9, ak: 100.5, jabatan: 'Analis Kebijakan Ahli Pertama', id_group_jabatan: 2, group_jabatan: 'JFT',
  id_sub_group_jabatan: 5, sub_group_jabatan: 'Analis', id_jenjang_jf: 1, kategori_jf: 'Ahli', jenjang_jf: 'Pertama',
  gol_ruang: 'III/a', pangkat: 'Penata Muda', updated_at: null, ...over,
})

function apiError422(errors: Record<string, string[]>) {
  return { status: 422, message: 'Validasi gagal.', errors, isNetworkError: false, original: new Error('x') }
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  for (const fn of Object.values(svc)) fn.mockReset()
  svc.list.mockResolvedValue([baris(), baris({ id_jabatan: 12, jabatan: 'Pranata Komputer Ahli Pertama', ak: 50 })])
  svc.opsi.mockResolvedValue({
    group_jabatan: [{ id_group_jabatan: 2, group_jabatan: 'JFT' }],
    sub_group_jabatan: [{ id_sub_group_jabatan: 5, id_group_jabatan: 2, sub_group_jabatan: 'Analis' }],
    jenjang_jf: [{ id_jenjang_jf: 1, kategori_jf: 'Ahli', jenjang_jf: 'Pertama' }],
    pangkat: [{ id_pangkat: 9, pangkat: 'Penata Muda', gol_ruang: 'III/a' }],
  })
  svc.jabatan.mockResolvedValue([{ id_jabatan: 11, jabatan: 'Analis Kebijakan Ahli Pertama' }])
})

async function mountView() {
  const wrapper = mount(MasterAkJfView, { attachTo: document.body })
  await flushPromises()
  return wrapper
}

function setScrollSize(el: HTMLElement, size: { scrollWidth: number; clientWidth: number; scrollLeft: number }): void {
  Object.defineProperty(el, 'scrollWidth', { configurable: true, value: size.scrollWidth })
  Object.defineProperty(el, 'clientWidth', { configurable: true, value: size.clientWidth })
  Object.defineProperty(el, 'scrollLeft', { configurable: true, writable: true, value: size.scrollLeft })
}

const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)

async function isi(name: string, value: string): Promise<void> {
  const el = q<HTMLInputElement | HTMLSelectElement>(`[data-testid="ak-jf-form"] [name="${name}"]`)
  if (!el) throw new Error(`isian ${name} tidak ada`)
  el.value = value
  el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input'))
  await flushPromises()
}

async function kirim(): Promise<void> {
  q('[data-testid="ak-jf-form"]')?.dispatchEvent(new Event('submit'))
  await flushPromises()
}

describe('MasterAkJfView', () => {
  it('daftar, cari, dan menu ⋮ hanya Edit (tanpa hapus)', async () => {
    const wrapper = await mountView()
    expect(wrapper.find('[data-testid="ak-jf-row-11-9"]').text()).toContain('100,5')
    expect((await rowMenuActions(wrapper, 'ak-jf-actions-11-9')).map((a) => a.key)).toEqual(['edit'])
    expect(q('[data-testid="ak-jf-actions-11-9"]')?.getAttribute('aria-label')).toContain('Aksi untuk Analis Kebijakan Ahli Pertama')
    // CR-052 Q4P-F1: kolom Aksi sticky kanan (header & sel), pola CR-038.
    expect(wrapper.findAll('thead th').at(-1)?.classes()).toEqual(expect.arrayContaining(['sticky', 'right-0', 'z-10']))
    expect(wrapper.get('[data-testid="ak-jf-row-11-9"]').findAll('td').at(-1)?.classes()).toEqual(expect.arrayContaining(['sticky', 'right-0', 'z-10', 'bg-white']))

    await wrapper.get('[data-testid="ak-jf-search"]').setValue('pranata')
    expect(wrapper.findAll('[data-testid^="ak-jf-row-"]')).toHaveLength(1)
  })

  it('CR-053: bayangan kolom Aksi hanya selama masih ada kolom tergulir di bawahnya (pola CR-038)', async () => {
    const wrapper = await mountView()
    const scroller = wrapper.get('[data-testid="ak-jf-table-scroll"]')
    const header = () => wrapper.get('[data-testid="ak-jf-col-actions"]')
    const cell = () => wrapper.get('[data-testid="ak-jf-row-11-9"]').findAll('td').at(-1)
    expect(header().classes()).not.toContain('shadow-sticky-end')

    // Layar sempit: tabel 700px di area 390px, belum digeser.
    setScrollSize(scroller.element as HTMLElement, { scrollWidth: 700, clientWidth: 390, scrollLeft: 0 })
    await scroller.trigger('scroll')
    expect(header().classes()).toContain('shadow-sticky-end')
    expect(cell()?.classes()).toContain('shadow-sticky-end')

    // Digeser sampai ujung kanan → tanpa bayangan.
    setScrollSize(scroller.element as HTMLElement, { scrollWidth: 700, clientWidth: 390, scrollLeft: 310 })
    await scroller.trigger('scroll')
    expect(header().classes()).not.toContain('shadow-sticky-end')
    expect(cell()?.classes()).not.toContain('shadow-sticky-end')

    // Resize jendela: bisa digeser → ada; tabel muat → hilang.
    setScrollSize(scroller.element as HTMLElement, { scrollWidth: 900, clientWidth: 600, scrollLeft: 0 })
    window.dispatchEvent(new Event('resize'))
    await flushPromises()
    expect(header().classes()).toContain('shadow-sticky-end')
    setScrollSize(scroller.element as HTMLElement, { scrollWidth: 900, clientWidth: 900, scrollLeft: 0 })
    window.dispatchEvent(new Event('resize'))
    await flushPromises()
    expect(header().classes()).not.toContain('shadow-sticky-end')

    // Isi tabel berubah (pencarian) → dihitung ulang sesudah render.
    setScrollSize(scroller.element as HTMLElement, { scrollWidth: 700, clientWidth: 390, scrollLeft: 0 })
    await wrapper.get('[data-testid="ak-jf-search"]').setValue('pranata')
    await flushPromises()
    expect(header().classes()).toContain('shadow-sticky-end')
  })

  it('tambah: pilihan berjenjang memuat jabatan dari server, lalu create; galat 422 dipetakan ke isian', async () => {
    const wrapper = await mountView()
    await wrapper.get('[data-testid="ak-jf-add"]').trigger('click')
    await flushPromises()

    await isi('id_group_jabatan', '2')
    await isi('id_sub_group_jabatan', '5')
    await isi('id_jenjang_jf', '1')
    expect(svc.jabatan).toHaveBeenLastCalledWith({ id_group_jabatan: '2', id_sub_group_jabatan: '5', id_jenjang_jf: '1' })
    await isi('id_jabatan', '11')
    await isi('id_pangkat', '9')
    await isi('ak', '12,5')

    svc.create.mockRejectedValueOnce(apiError422({ id_pangkat: ['Data angka kredit JF untuk jabatan X dan gol. III/a sudah terdaftar di sistem.'] }))
    await kirim()
    expect(svc.create).toHaveBeenCalledWith({ id_jabatan: '11', id_pangkat: '9', ak: '12.5' })
    expect(q('[data-testid="ak-jf-form"]')?.textContent).toContain('sudah terdaftar di sistem')
    expect(q<HTMLInputElement>('[data-testid="ak-jf-form"] [name="ak"]')?.getAttribute('inputmode')).toBe('decimal')

    // CR-052 Q4P-F3: galat pasangan kembar hilang saat jabatan diganti (juga saat rantai pilihannya diganti).
    await isi('id_jabatan', '')
    expect(q('[data-testid="ak-jf-form"]')?.textContent).not.toContain('sudah terdaftar di sistem')
    await isi('id_jabatan', '11')
    svc.create.mockRejectedValueOnce(apiError422({ id_pangkat: ['Data angka kredit JF untuk jabatan X dan gol. III/a sudah terdaftar di sistem.'] }))
    await kirim()
    expect(q('[data-testid="ak-jf-form"]')?.textContent).toContain('sudah terdaftar di sistem')
    await isi('id_jenjang_jf', '1')
    expect(q('[data-testid="ak-jf-form"]')?.textContent).not.toContain('sudah terdaftar di sistem')
    await isi('id_jabatan', '11')

    svc.create.mockResolvedValueOnce(baris({ ak: 12.5 }))
    await kirim()
    await flushPromises()
    expect(svc.list).toHaveBeenCalledTimes(2)
    expect(document.body.textContent).toContain('tersimpan')
  })

  it('tambah: kombinasi tanpa jabatan menampilkan keterangan (CR-052 Q4P-I2)', async () => {
    const wrapper = await mountView()
    await wrapper.get('[data-testid="ak-jf-add"]').trigger('click')
    await flushPromises()
    svc.jabatan.mockResolvedValueOnce([])
    await isi('id_group_jabatan', '2')
    expect(q('[data-testid="ak-jf-form"]')?.textContent).not.toContain('Tidak ada jabatan untuk pilihan ini')
    await isi('id_sub_group_jabatan', '5')
    await isi('id_jenjang_jf', '1')
    expect(q('[data-testid="ak-jf-form"]')?.textContent).toContain('Tidak ada jabatan untuk pilihan ini')
  })

  it('tambah: validasi klien (wajib, AK angka maks 3 desimal) tanpa memanggil API', async () => {
    const wrapper = await mountView()
    await wrapper.get('[data-testid="ak-jf-add"]').trigger('click')
    await flushPromises()
    await isi('ak', '1,2345')
    await kirim()
    const teks = q('[data-testid="ak-jf-form"]')?.textContent ?? ''
    expect(teks).toContain('Silahkan pilih jabatan.')
    expect(teks).toContain('Silahkan pilih gol. ruang.')
    expect(teks).toContain('paling banyak 3 angka desimal')
    expect(svc.create).not.toHaveBeenCalled()
  })

  it('edit: jabatan & gol. ruang terkunci, hanya AK dikirim', async () => {
    const wrapper = await mountView()
    await selectRowAction(wrapper, 'ak-jf-actions-11-9', 'edit')
    await flushPromises()
    expect(q<HTMLInputElement>('[data-testid="ak-jf-form"] [name="jabatan"]')?.disabled).toBe(true)
    expect(q('[data-testid="ak-jf-form"] [name="id_pangkat"]')).toBeNull()
    expect(q<HTMLInputElement>('[data-testid="ak-jf-form"] [name="ak"]')?.value).toBe('100.5')

    svc.update.mockResolvedValueOnce(baris({ ak: 80 }))
    await isi('ak', '80')
    await kirim()
    expect(svc.update).toHaveBeenCalledWith(11, 9, '80')
    expect(svc.opsi).not.toHaveBeenCalled()
  })
})
