/** @type {import('tailwindcss').Config} */

// Token desain diambil langsung dari "Laporan Redesign Simpeg Kemenpar rev1" bab 3 (UI Components):
// 3.1 Typhography, 3.2 Palet Warna, 3.3 Tombol, 3.4 Input, 3.5 Dropdown, 3.6 Checkbox, 3.7 Avatars.
// Nilai `legacy` (ADR-020) dipertahankan sampai halaman lama selesai dimigrasi ke shell redesign.
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  theme: {
    extend: {
      colors: {
        // 3.2.1 Warna Primer
        brand: {
          primary: '#1C3964', // navy — sidebar aktif, topbar, heading
          secondary: '#FFC043', // kuning — aksen tab aktif & garis identitas
          tertiary: '#217AFF', // biru — aksi utama (tombol Primary, link, checkbox)
        },
        // 3.2.2 Warna Semantik. `soft` = versi latar (tint) untuk badge/ikon, bukan bagian dokumen
        // tapi diturunkan dari warna yang sama agar kontras teks tetap >= WCAG AA.
        success: { DEFAULT: '#28C76F', soft: '#E6F8EF' },
        warning: { DEFAULT: '#FF9F43', soft: '#FFF2E4' },
        danger: { DEFAULT: '#EA5455', soft: '#FDEBEB' },
        info: { DEFAULT: '#00BAD1', soft: '#E0F7FA' },
        muted: { DEFAULT: '#82868B', soft: '#F1F2F3' },
        legacy: {
          primary: '#1A237E',
          accent: '#E53935',
        },
      },
      fontFamily: {
        sans: ['"Public Sans"', 'Montserrat', '"Open Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      // 3.1 Typhography — ukuran/line-height/weight persis dari tabel dokumen.
      fontSize: {
        h1: ['2.875rem', { lineHeight: '4.25rem', fontWeight: '500' }], // 46/68
        h2: ['2.375rem', { lineHeight: '3.5rem', fontWeight: '500' }], // 38/56
        h3: ['1.75rem', { lineHeight: '2.625rem', fontWeight: '500' }], // 28/42
        h4: ['1.5rem', { lineHeight: '2.375rem', fontWeight: '500' }], // 24/38
        h5: ['1.125rem', { lineHeight: '1.75rem', fontWeight: '500' }], // 18/28
        h6: ['0.9375rem', { lineHeight: '1.375rem', fontWeight: '500' }], // 15/22
        subtitle1: ['0.9375rem', { lineHeight: '1.375rem', fontWeight: '400' }], // 15/22
        subtitle2: ['0.8125rem', { lineHeight: '1.25rem', fontWeight: '400' }], // 13/20
        body1: ['0.9375rem', { lineHeight: '1.375rem', fontWeight: '400' }], // 15/22
        body2: ['0.8125rem', { lineHeight: '1.25rem', fontWeight: '400' }], // 13/20
        caption: ['0.8125rem', { lineHeight: '1.125rem', fontWeight: '400' }], // 13/18
        overline: ['0.75rem', { lineHeight: '0.875rem', fontWeight: '400', letterSpacing: '0.08em' }], // 12/14
      },
      borderRadius: {
        card: '0.875rem', // radius kartu & panel pada mockup redesign
      },
      boxShadow: {
        card: '0 1px 2px 0 rgb(16 24 40 / 0.04), 0 1px 3px 0 rgb(16 24 40 / 0.06)',
        panel: '0 8px 24px -8px rgb(16 24 40 / 0.12)',
        float: '0 12px 32px -12px rgb(28 57 100 / 0.28)',
        // Pemisah kolom yang menempel di kanan tabel (kolom Aksi sticky, CR-038) saat ada isi tergulir di bawahnya.
        'sticky-end': '-8px 0 8px -8px rgb(16 24 40 / 0.18)',
      },
      transitionDuration: {
        DEFAULT: '150ms',
      },
    },
  },
  plugins: [],
}
