/**
 * UiFileField (CR-049 D-F6): pilih atau seret-lepas berkas; berkas lolos menampilkan pratinjau (gambar kecil untuk
 * gambar, nama + ukuran untuk lainnya); pesan tolak jenis/ukuran sama untuk kedua cara.
 */
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import UiFileField from '../UiFileField.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

beforeEach(() => {
  Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:pratinjau'), revokeObjectURL: vi.fn() })
})

const pasang = () => mount(UiFileField, { props: { label: 'Berkas SK', allowed: ['pdf', 'png'], maxMb: 1 }, attachTo: document.body })

async function lepas(wrapper: ReturnType<typeof pasang>, file: File): Promise<void> {
  const zona = wrapper.get('[data-testid="file-dropzone"]')
  await zona.trigger('dragenter', { dataTransfer: { files: [file] } })
  await zona.trigger('drop', { dataTransfer: { files: [file] } })
}

describe('UiFileField', () => {
  it('seret-lepas gambar → change + pratinjau gambar; PDF → nama & ukuran', async () => {
    const w = pasang()
    const png = new File(['x'], 'foto.png', { type: 'image/png' })
    await lepas(w, png)
    expect(w.emitted('change')?.[0]).toEqual([png])
    expect(w.get('[data-testid="file-preview"] img').attributes('src')).toBe('blob:pratinjau')

    const pdf = new File([new Uint8Array(2048)], 'sk.pdf', { type: 'application/pdf' })
    await lepas(w, pdf)
    expect(w.get('[data-testid="file-preview"]').text()).toContain('sk.pdf')
    expect(w.get('[data-testid="file-preview"]').text()).toContain('2,0 KB')
    expect(w.find('[data-testid="file-preview"] img').exists()).toBe(false)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:pratinjau')
  })

  it('pesan tolak jenis/ukuran tetap sama saat diseret', async () => {
    const w = pasang()
    await lepas(w, new File(['x'], 'virus.exe'))
    expect(w.get('[role="alert"]').text()).toBe('Jenis file .exe tidak diperbolehkan.')
    await lepas(w, new File([new Uint8Array(1024 * 1024 + 1)], 'besar.pdf'))
    expect(w.get('[role="alert"]').text()).toBe('Ukuran file melebihi 1 MB.')
    expect(w.emitted('change')?.at(-1)).toEqual([null])
    expect(w.find('[data-testid="file-preview"]').exists()).toBe(false)
  })
})
