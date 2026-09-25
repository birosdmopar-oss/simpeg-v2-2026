/**
 * DBV-002/CR-003 §5 — sanitasi HTML sisi render: whitelist setara HTMLPurifier backend. Konten berbahaya
 * (script, on*, javascript:, data:, style/class) dibuang; tag/atribut yang diizinkan tetap utuh.
 */
import { describe, expect, it } from 'vitest'

import { sanitizeHtml } from '../sanitizeHtml'

/** Parse hasil sanitasi agar assertion tidak bergantung urutan atribut. */
function parse(html: string): HTMLElement {
  const box = document.createElement('div')
  box.innerHTML = sanitizeHtml(html)
  return box
}

describe('sanitizeHtml', () => {
  it('membuang <script>, <style>, dan <iframe> beserta isinya', () => {
    expect(sanitizeHtml('<p>Halo<script>alert(1)</script></p>')).toBe('<p>Halo</p>')
    expect(sanitizeHtml('<style>p{color:red}</style><p>A</p>')).toBe('<p>A</p>')
    expect(sanitizeHtml('<iframe src="https://contoh.go.id"></iframe><p>B</p>')).toBe('<p>B</p>')
  })

  it('membuang atribut event handler (on*), style, class, id, data-*, aria-*', () => {
    const box = parse('<p onclick="alert(1)" style="color:red" class="x" id="y" data-x="1" aria-label="z">Teks</p><img src="https://a.go.id/g.png" onerror="alert(1)">')
    const p = box.querySelector('p')
    expect(p?.getAttributeNames()).toEqual([])
    expect(box.querySelector('img')?.getAttributeNames()).toEqual(['src'])
    expect(box.innerHTML).not.toMatch(/on(click|error)|style=|class=/)
  })

  it('href javascript:/data:/vbscript: dibuang (termasuk variasi huruf besar & tab), http/https/mailto/relatif tetap', () => {
    for (const href of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'java\tscript:alert(1)', 'data:text/html,<b>x</b>', 'vbscript:msgbox(1)']) {
      expect(parse(`<a href="${href}">x</a>`).querySelector('a')?.hasAttribute('href')).toBe(false)
    }
    for (const href of ['https://simpeg.kemenparekraf.go.id', 'http://contoh.go.id/a?b=1', 'mailto:sdm@kemenparekraf.go.id', '/faq/1', '#bagian']) {
      expect(parse(`<a href="${href}">x</a>`).querySelector('a')?.getAttribute('href')).toBe(href)
    }
  })

  it('img dengan src bukan URL absolut http/https dibuang seluruhnya (sama dengan backend); teks di sekitarnya tetap', () => {
    // Daftar yang sama dengan HtmlSanitizerTest::testImageSourceMustBeAbsoluteHttp + variasi relatif & //host.
    for (const src of ['data:image/png;base64,AAAA', 'javascript:alert(1)', 'mailto:a@b.c', '/assets/upload/news/a.png', 'x', 'gambar/a.png', '//cdn.contoh.id/a.png', 'https:/a.png', '']) {
      expect(sanitizeHtml(`<img src="${src}" alt="a">`), src).toBe('')
    }
    expect(sanitizeHtml('<img alt="tanpa src">')).toBe('')
    expect(sanitizeHtml('<p>Buka <img src="x" onerror="alert(1)">menu</p>')).toBe('<p>Buka menu</p>')
    expect(parse('<img src=" HTTPS://a.go.id/g.png">').querySelector('img')).not.toBeNull()
  })

  it('img src http/https absolut tetap; alt/width/height tetap', () => {
    const img = parse('<img src="https://a.go.id/g.png" alt="Gambar" width="120" height="80" title="t">').querySelector('img')
    expect(img?.getAttribute('src')).toBe('https://a.go.id/g.png')
    expect(img?.getAttribute('alt')).toBe('Gambar')
    expect(img?.getAttribute('width')).toBe('120')
    expect(img?.getAttribute('height')).toBe('80')
    // title hanya untuk <a>.
    expect(img?.hasAttribute('title')).toBe(false)
  })

  it('target hanya _blank dan otomatis rel="noopener noreferrer"; target lain dibuang', () => {
    const blank = parse('<a href="https://a.go.id" target="_blank" rel="opener">x</a>').querySelector('a')
    expect(blank?.getAttribute('target')).toBe('_blank')
    expect(blank?.getAttribute('rel')).toBe('noopener noreferrer')

    const self = parse('<a href="https://a.go.id" target="_self" rel="opener">x</a>').querySelector('a')
    expect(self?.hasAttribute('target')).toBe(false)
    expect(self?.hasAttribute('rel')).toBe(false)
  })

  it('atribut dibatasi per tag: colspan/rowspan hanya th/td, href hanya a', () => {
    const box = parse('<table><tbody><tr><td colspan="2" rowspan="1">A</td><th colspan="3">B</th></tr></tbody></table><p colspan="2">C</p><span href="https://a.go.id">D</span>')
    expect(box.querySelector('td')?.getAttribute('colspan')).toBe('2')
    expect(box.querySelector('td')?.getAttribute('rowspan')).toBe('1')
    expect(box.querySelector('th')?.getAttribute('colspan')).toBe('3')
    expect(box.querySelector('p')?.hasAttribute('colspan')).toBe(false)
    expect(box.querySelector('span')?.hasAttribute('href')).toBe(false)
  })

  it('tag di luar whitelist dibuka (isi teks dipertahankan), tag whitelist utuh', () => {
    expect(sanitizeHtml('<div><font>Isi</font></div>')).toBe('Isi')
    expect(sanitizeHtml('<h1>Judul</h1>')).toBe('Judul')
    const allowed =
      '<h2>A</h2><h3>B</h3><h4>C</h4><p><strong>1</strong><b>2</b><em>3</em><i>4</i><u>5</u><s>6</s><sub>7</sub><sup>8</sup><span>9</span><br></p>' +
      '<ul><li>x</li></ul><ol><li>y</li></ol><blockquote>q</blockquote><pre><code>c</code></pre><hr>'
    expect(sanitizeHtml(allowed)).toBe(allowed)
  })

  it('form/input/svg berbahaya dibuang', () => {
    const out = sanitizeHtml('<form action="https://x"><input name="password"></form><svg><script>alert(1)</script></svg><p>ok</p>')
    expect(out).toBe('<p>ok</p>')
  })

  it('null/undefined/kosong → string kosong', () => {
    expect(sanitizeHtml(null)).toBe('')
    expect(sanitizeHtml(undefined)).toBe('')
    expect(sanitizeHtml('')).toBe('')
  })
})
