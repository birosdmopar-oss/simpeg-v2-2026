/**
 * CR-190: kerangka halaman auth V5 — logo ber-alt, foto latar <picture> (AVIF/WebP/JPG + WebP 960 untuk layar kecil),
 * kelas kaca beserta fallback-nya, footer BSrE, dan slot isi.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AuthCardLayout from '../components/AuthCardLayout.vue'
import sumber from '../components/AuthCardLayout.vue?raw'

function pasang() {
  return mount(AuthCardLayout, { slots: { default: '<form data-testid="isi">Isi</form>' } })
}

describe('AuthCardLayout (V5)', () => {
  it('font Inter hanya lewat kelas font-v5 di layout ini; slot isi dirender di dalam kartu', () => {
    const w = pasang()
    expect(w.get('[data-testid="auth-layout"]').classes()).toContain('font-v5')
    expect(w.get('[data-testid="auth-card"]').find('[data-testid="isi"]').exists()).toBe(true)
  })

  it('logo memakai alt yang benar; wordmark SIMPEG sebagai h1', () => {
    const w = pasang()
    const alts = w.findAll('img').map((i) => i.attributes('alt'))
    expect(alts).toEqual(
      expect.arrayContaining([
        'Logo Kementerian Pariwisata Republik Indonesia',
        'Logo Wonderful Indonesia',
        'Logo Balai Besar Sertifikasi Elektronik',
      ]),
    )
    expect(w.get('h1').text()).toBe('SIMPEG')
    expect(w.text()).toContain('Sistem Informasi Kepegawaian')
    expect(w.text()).toContain('Kementerian Pariwisata Republik Indonesia')
  })

  it('foto latar: <picture> dengan sumber WebP 960 (≤ 1024 px), AVIF, WebP, lalu JPG; dekoratif (alt kosong)', () => {
    const w = pasang()
    const picture = w.get('[data-testid="auth-bg"]')
    expect(picture.element.tagName).toBe('PICTURE')
    const sources = picture.findAll('source')
    expect(sources.map((s) => s.attributes('type'))).toEqual(['image/webp', 'image/avif', 'image/webp'])
    expect(sources[0].attributes('media')).toBe('(max-width: 1024px)')
    expect(sources[0].attributes('srcset')).toContain('login-bg-960')
    expect(sources[1].attributes('srcset')).toContain('login-bg')
    const img = picture.get('img')
    expect(img.attributes('alt')).toBe('')
    expect(img.attributes('src')).toContain('login-bg')
    expect(picture.element.parentElement?.getAttribute('aria-hidden')).toBe('true')
  })

  it('footer sertifikat elektronik BSrE / BSSN', () => {
    const footer = pasang().get('[data-testid="auth-footer"]')
    expect(footer.text()).toContain('Tersertifikasi Sertifikat Elektronik')
    expect(footer.text()).toContain('Badan Siber dan Sandi Negara (BSSN)')
  })

  it('kartu memakai kelas kaca, dengan fallback solid untuk tanpa backdrop-filter, reduced transparency, dan forced colors', () => {
    expect(pasang().get('[data-testid="auth-card"]').classes()).toEqual(expect.arrayContaining(['auth-glass', 'rounded-v5-2xl', 'max-w-[460px]']))
    expect(sumber).toContain('-webkit-backdrop-filter: blur(44px) saturate(1.2)')
    expect(sumber).toContain('@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px)))')
    expect(sumber).toContain('@media (prefers-reduced-transparency: reduce)')
    expect(sumber).toContain('@media (forced-colors: active)')
  })
})
