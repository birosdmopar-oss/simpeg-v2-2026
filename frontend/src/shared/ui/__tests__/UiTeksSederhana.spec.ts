/**
 * Q7-10 — editor teks sederhana: konversi penulisan ⇄ HTML terbatas (whitelist), pembersih HTML impor, dan komponen
 * (toolbar membungkus pilihan, v-model HTML, ARIA, disabled).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import { UiTeksSederhana } from '..'
import { adalahTautanAman, bersihkanHtml, htmlKeMarkup, markupKeHtml } from '../teksSederhana'

describe('markupKeHtml', () => {
  it('paragraf, baris baru, tebal, miring', () => {
    expect(markupKeHtml('Halo **dunia** dan *kamu*\nbaris dua\n\nParagraf dua')).toBe(
      '<p>Halo <strong>dunia</strong> dan <em>kamu</em><br>baris dua</p><p>Paragraf dua</p>',
    )
  })

  it('daftar berbutir dan bernomor', () => {
    expect(markupKeHtml('- satu\n- **dua**')).toBe('<ul><li>satu</li><li><strong>dua</strong></li></ul>')
    expect(markupKeHtml('1. a\n2. b')).toBe('<ol><li>a</li><li>b</li></ol>')
  })

  it('meloloskan karakter HTML (tanpa tag liar) dan hanya menerima tautan http(s)/mailto', () => {
    expect(markupKeHtml('<script>alert(1)</script> & "x"')).toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;x&quot;</p>')
    expect(markupKeHtml('[situs](https://contoh.test/a?b=1&c=2)')).toBe('<p><a href="https://contoh.test/a?b=1&amp;c=2">situs</a></p>')
    expect(markupKeHtml('[klik](javascript:alert(1))')).toBe('<p>klik (javascript:alert(1))</p>')
    expect(markupKeHtml('[surel](mailto:a@contoh.test)')).toContain('<a href="mailto:a@contoh.test">')
  })

  it('kosong → kosong', () => {
    expect(markupKeHtml('   \n\n ')).toBe('')
  })
})

describe('htmlKeMarkup & bersihkanHtml', () => {
  it('bolak-balik HTML terbatas ⇄ penulisan', () => {
    const html = '<p>Halo <strong>dunia</strong></p><ul><li>satu</li><li>dua</li></ul><p><a href="https://contoh.test">tautan</a></p>'
    const md = htmlKeMarkup(html)
    expect(md).toBe('Halo **dunia**\n\n- satu\n- dua\n\n[tautan](https://contoh.test)')
    expect(markupKeHtml(md)).toBe(html)
  })

  it('bersihkanHtml membuang script/atribut/tag di luar whitelist dan tautan berbahaya, mempertahankan teks', () => {
    const kotor =
      '<p onclick="x()">Isi <b>tebal</b><script>alert(1)</script> <a href="javascript:alert(1)">jahat</a> ' +
      '<a href="https://contoh.test" onclick="y()">baik</a> <img src="x.png" onerror="z()"><span style="color:red">teks</span></p>'
    const bersih = bersihkanHtml(kotor)
    expect(bersih).not.toMatch(/script|onclick|onerror|javascript:|<img|<span|style=/i)
    expect(bersih).toContain('tebal')
    expect(bersih).toContain('jahat')
    expect(bersih).toContain('<a href="https://contoh.test">baik</a>')
    expect(bersih).toContain('teks')
  })

  it('adalahTautanAman menolak skema lain dan spasi/kutip', () => {
    expect(adalahTautanAman('https://a.test')).toBe(true)
    expect(adalahTautanAman('http://a.test/x')).toBe(true)
    expect(adalahTautanAman('mailto:a@b.test')).toBe(true)
    expect(adalahTautanAman('javascript:alert(1)')).toBe(false)
    expect(adalahTautanAman('data:text/html,x')).toBe(false)
    expect(adalahTautanAman('//a.test')).toBe(false)
    expect(adalahTautanAman('https://a.test/"x')).toBe(false)
  })
})

describe('UiTeksSederhana', () => {
  const pasang = (props: Record<string, unknown> = {}) =>
    mount(UiTeksSederhana, { props: { modelValue: '', label: 'Isi berita', ...props }, attachTo: document.body })

  it('ARIA: textarea berlabel, toolbar berlabel, tombol ber-aria-label', () => {
    const w = pasang()
    const area = w.get('textarea')
    expect(w.get(`label[for="${area.attributes('id')}"]`).text()).toBe('Isi berita')
    expect(w.get('[role="toolbar"]').attributes('aria-label')).toBe('Format Isi berita')
    expect(w.findAll('button').map((b) => b.attributes('aria-label'))).toEqual(['Tebal', 'Miring', 'Daftar', 'Daftar bernomor', 'Tautan'])
    w.unmount()
  })

  it('mengetik memancarkan HTML terbatas dan menampilkan pratinjau bersih', async () => {
    const w = pasang()
    await w.get('textarea').setValue('Halo **dunia**')
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual(['<p>Halo <strong>dunia</strong></p>'])
    expect(w.get('[data-testid="teks-sederhana-pratinjau"]').html()).toContain('<strong>dunia</strong>')
    w.unmount()
  })

  it('nilai awal HTML ditampilkan sebagai penulisan (berita lama dibersihkan)', () => {
    const w = pasang({ modelValue: '<p>Isi <b>lama</b><script>x()</script></p>' })
    expect(w.get('textarea').element.value).toBe('Isi **lama**x()')
    expect(w.get('[data-testid="teks-sederhana-pratinjau"]').html()).not.toContain('<script')
    w.unmount()
  })

  it('tombol Tebal membungkus pilihan; Daftar mengawali baris dengan "- "; Tautan membentuk [teks](https://)', async () => {
    const w = pasang({ modelValue: '<p>kata</p>' })
    const area = w.get('textarea').element
    area.setSelectionRange(0, 4)
    await w.get('[data-aksi="tebal"]').trigger('click')
    expect(area.value).toBe('**kata**')

    area.setSelectionRange(0, area.value.length)
    await w.get('[data-aksi="daftar"]').trigger('click')
    expect(area.value).toBe('- **kata**')
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual(['<ul><li><strong>kata</strong></li></ul>'])

    await w.get('textarea').setValue('situs')
    area.setSelectionRange(0, 5)
    await w.get('[data-aksi="tautan"]').trigger('click')
    expect(area.value).toBe('[situs](https://)')
    w.unmount()
  })

  it('disabled mematikan toolbar dan textarea; invalid menandai aria-invalid', () => {
    const w = pasang({ disabled: true, invalid: true, describedBy: 'err' })
    expect(w.get('textarea').attributes('disabled')).toBeDefined()
    expect(w.get('textarea').attributes('aria-invalid')).toBe('true')
    expect(w.get('textarea').attributes('aria-describedby')).toBe('err')
    expect(w.findAll('button').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
    w.unmount()
  })
})
