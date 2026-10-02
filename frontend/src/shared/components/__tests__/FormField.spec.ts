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

describe('FormField type="checkbox" (CR-009, field boolean master)', () => {
  it("tercentang bila nilai '1', emit '0'/'1' saat diubah, label terhubung ke kotak centang", async () => {
    const wrapper = mount(FormField, { props: { label: 'Jenjang D-III', modelValue: '1', type: 'checkbox', name: 'flag_d3' } })
    const box = wrapper.get<HTMLInputElement>('input[type="checkbox"]')
    expect(box.element.checked).toBe(true)
    expect(wrapper.get('label').attributes('for')).toBe(box.attributes('id'))
    expect(wrapper.findAll('input')).toHaveLength(1)

    await box.setValue(false)
    await box.setValue(true)
    expect(wrapper.emitted('update:modelValue')).toEqual([['0'], ['1']])
  })

  it("nilai '0'/kosong tidak tercentang; error ditampilkan", () => {
    for (const modelValue of ['0', '', null]) {
      const wrapper = mount(FormField, { props: { label: 'S-1', modelValue, type: 'checkbox', error: 'S-1 wajib diisi.' } })
      expect(wrapper.get<HTMLInputElement>('input[type="checkbox"]').element.checked).toBe(false)
      expect(wrapper.get('[role="alert"]').text()).toBe('S-1 wajib diisi.')
    }
  })
})

describe('FormField select allowEmpty', () => {
  it('pilihan kosong hanya bisa dipilih bila allowEmpty (select opsional)', () => {
    const options = [{ value: '31', label: 'DKI Jakarta' }]
    const locked = mount(FormField, { props: { label: 'Provinsi', modelValue: '31', type: 'select', options } })
    expect(locked.get('option[value=""]').attributes('disabled')).toBeDefined()
    const clearable = mount(FormField, { props: { label: 'Provinsi', modelValue: '31', type: 'select', options, allowEmpty: true } })
    expect(clearable.get('option[value=""]').attributes('disabled')).toBeUndefined()
  })
})

describe('FormField memakai komponen redesign (CR-028 / MIG-001b)', () => {
  it('text: dirender UiTextField — label di atas kotak dan terhubung for/id, tanda wajib, emit nilai', async () => {
    const wrapper = mount(FormField, { props: { label: 'Nama Libur', modelValue: '', name: 'nama_libur', required: true, hint: 'Maks. 100' } })
    const label = wrapper.get('label')
    const input = wrapper.get('input[name="nama_libur"]')
    expect(label.attributes('for')).toBe(input.attributes('id'))
    expect(label.text()).toContain('*')
    // Label di atas: elemen label mendahului input di DOM dan bukan label takik (outlined).
    expect(label.element.compareDocumentPosition(input.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(label.classes()).not.toContain('absolute')
    // Validasi milik Zod/VeeValidate: tanpa atribut required native, ditandai aria-required.
    expect(input.attributes('required')).toBeUndefined()
    expect(input.attributes('aria-required')).toBe('true')
    expect(wrapper.text()).toContain('Maks. 100')

    await input.setValue('Hari Kemerdekaan')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['Hari Kemerdekaan'])
  })

  it('error menggantikan hint: border/label merah, aria-invalid, pesan role="alert" ter-refer aria-describedby', () => {
    const wrapper = mount(FormField, { props: { label: 'Kode', modelValue: 'x', hint: 'Maks. 10', error: 'Kode sudah dipakai.' } })
    const input = wrapper.get('input')
    expect(input.classes()).toContain('border-danger')
    expect(input.attributes('aria-invalid')).toBe('true')
    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toBe('Kode sudah dipakai.')
    expect(input.attributes('aria-describedby')).toBe(alert.attributes('id'))
    expect(wrapper.text()).not.toContain('Maks. 10')
  })

  it('select: dirender UiSelect dengan label di atas, error ber-role alert, emit nilai terpilih', async () => {
    const options = [{ value: '1', label: 'Aktif' }]
    const wrapper = mount(FormField, { props: { label: 'Status', modelValue: '', type: 'select', name: 'status', options, error: 'Wajib dipilih.' } })
    const select = wrapper.get('select[name="status"]')
    expect(wrapper.get('label').attributes('for')).toBe(select.attributes('id'))
    expect(select.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('[role="alert"]').text()).toBe('Wajib dipilih.')
    await select.setValue('1')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['1'])
  })

  it('slot suffix diteruskan ke UiTextField (tombol lihat password) dan input diberi ruang kanan', () => {
    const wrapper = mount(FormField, {
      props: { label: 'Password', modelValue: '', type: 'password' },
      slots: { suffix: '<button type="button" data-testid="toggle">lihat</button>' },
    })
    expect(wrapper.find('[data-testid="toggle"]').exists()).toBe(true)
    expect(wrapper.get('input').classes()).toContain('pr-10')
    expect(mount(FormField, { props: { label: 'Nama', modelValue: '' } }).get('input').classes()).not.toContain('pr-10')
  })
})

describe('FormField type="checkbox" memakai UiCheckbox (CR-028)', () => {
  it('error: aria-invalid & aria-describedby di <input>, tanda wajib di label', () => {
    const wrapper = mount(FormField, { props: { label: 'S-1', modelValue: '0', type: 'checkbox', required: true, error: 'S-1 wajib diisi.' } })
    const box = wrapper.get('input[type="checkbox"]')
    expect(box.attributes('aria-invalid')).toBe('true')
    expect(box.attributes('aria-describedby')).toBe(wrapper.get('[role="alert"]').attributes('id'))
    expect(wrapper.get('label').text()).toContain('S-1 *')
  })
})

describe('FormField error pada field terkunci (CR-028)', () => {
  it('select disabled ber-error: pesan tetap merah dan ber-role alert', () => {
    const wrapper = mount(FormField, {
      props: { label: 'Kecamatan', modelValue: '', type: 'select', disabled: true, error: 'Induk wajib dipilih.' },
    })
    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toBe('Induk wajib dipilih.')
    expect(alert.classes()).toContain('text-danger')
  })
})
