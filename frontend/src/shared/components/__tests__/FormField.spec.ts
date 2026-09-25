/**
 * DBV-002/CR-003 E3 — FormField tipe `html`: textarea tinggi (>= 10 baris) + toggle "Pratinjau" yang merender isi
 * lewat sanitizeHtml() (SafeHtml), bukan HTML mentah.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import FormField from '../FormField.vue'

describe('FormField type="html"', () => {
  it('textarea minimal 10 baris dan mengirim update:modelValue', async () => {
    const wrapper = mount(FormField, { props: { label: 'Isi Artikel', modelValue: '', type: 'html', required: true } })
    const textarea = wrapper.get('textarea')
    expect(Number(textarea.attributes('rows'))).toBeGreaterThanOrEqual(10)
    await textarea.setValue('<p>Halo</p>')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['<p>Halo</p>'])
  })

  it('Pratinjau merender HTML tersanitasi (script & on* dibuang) lalu bisa ditutup', async () => {
    const wrapper = mount(FormField, {
      props: { label: 'Isi Artikel', modelValue: '<p onclick="alert(1)">Isi <strong>tebal</strong></p><script>alert(2)</script>', type: 'html' },
    })
    expect(wrapper.find('[data-testid="html-preview"]').exists()).toBe(false)

    await wrapper.get('[data-testid="html-preview-toggle"]').trigger('click')
    // SafeHtml dimuat async (defineAsyncComponent).
    await vi.dynamicImportSettled()
    await flushPromises()
    const preview = wrapper.get('[data-testid="html-preview"]')
    expect(preview.find('strong').text()).toBe('tebal')
    expect(preview.html()).not.toContain('script')
    expect(preview.html()).not.toContain('onclick')
    expect(wrapper.get('[data-testid="html-preview-toggle"]').text()).toBe('Tutup pratinjau')
    expect(wrapper.get('textarea').isVisible()).toBe(false)

    await wrapper.get('[data-testid="html-preview-toggle"]').trigger('click')
    expect(wrapper.find('[data-testid="html-preview"]').exists()).toBe(false)
  })

  it('aria-controls tombol Pratinjau hanya ada saat pratinjau terbuka dan menunjuk kontainer pratinjau', async () => {
    const wrapper = mount(FormField, { props: { label: 'Isi Artikel', modelValue: '<p>Isi</p>', type: 'html' }, attachTo: document.body })
    const toggle = wrapper.get('[data-testid="html-preview-toggle"]')
    expect(toggle.attributes('aria-controls')).toBeUndefined()

    await toggle.trigger('click')
    // SafeHtml dimuat async (defineAsyncComponent).
    await vi.dynamicImportSettled()
    await flushPromises()
    const controls = toggle.attributes('aria-controls')
    expect(controls).toBeTruthy()
    expect(document.getElementById(String(controls))).toBe(wrapper.get('[data-testid="html-preview"]').element)

    await toggle.trigger('click')
    expect(toggle.attributes('aria-controls')).toBeUndefined()
    wrapper.unmount()
  })

  it('pratinjau konten kosong menampilkan keterangan', async () => {
    const wrapper = mount(FormField, { props: { label: 'Isi Artikel', modelValue: '', type: 'html' } })
    await wrapper.get('[data-testid="html-preview-toggle"]').trigger('click')
    // SafeHtml dimuat async (defineAsyncComponent).
    await vi.dynamicImportSettled()
    await flushPromises()
    expect(wrapper.get('[data-testid="html-preview"]').text()).toContain('Belum ada konten untuk dipratinjau.')
  })
})
