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
        // Status presensi harian (laporan Presensi 5C): DEFAULT = warna teks, soft = latar chip/baris, titik = penanda legenda.
        // Pasangan DEFAULT/soft >= 4,5:1 (diuji di features/presensi/__tests__/statusPresensi.spec.ts).
        status: {
          kuning: { DEFAULT: '#8a5b00', soft: '#FFF2E4', titik: '#FF9F43' },
          merah: { DEFAULT: '#a82d2e', soft: '#FDEBEB', titik: '#EA5455' },
          jingga: { DEFAULT: '#8f3f00', soft: '#FFE9D6', titik: '#F97316' },
          biru: { DEFAULT: '#006672', soft: '#E0F7FA', titik: '#00BAD1' },
          abu: { DEFAULT: '#475569', soft: '#F1F5F9', titik: '#94A3B8' },
          hijau: { DEFAULT: '#13753d', soft: '#E6F8EF', titik: '#28C76F' },
          ungu: { DEFAULT: '#5b3f8c', soft: '#EFE9F7', titik: '#6F519D' },
          pink: { DEFAULT: '#9c1f5a', soft: '#FBE6F0', titik: '#D5317C' },
        },
        legacy: {
          primary: '#1A237E',
          accent: '#E53935',
        },
        // CR-190: token SIMPEG V5 "Liquid Glass" (spesifikasi desainer §2, keputusan D1). ADITIF: sementara hanya
        // dipakai halaman auth (layout AuthCardLayout + prop `appearance="v5"`); token lama di atas tidak berubah.
        v5: {
          primary: { DEFAULT: '#2D3F9A', hover: '#3A52CC', pressed: '#22348B' },
          blue: '#4560E6', // ring fokus
          link: '#3D58D8',
          text: { DEFAULT: '#1D1D1F', secondary: '#48484D', muted: '#636369', disabled: '#AEAEB2' },
          page: '#EEF1F8',
          border: { DEFAULT: 'rgb(60 60 67 / 0.16)', subtle: 'rgb(60 60 67 / 0.08)', strong: '#8E8E93', hover: 'rgb(60 60 67 / 0.28)' },
          disabled: 'rgb(120 120 128 / 0.12)',
          field: 'rgb(255 255 255 / 0.72)',
          error: { DEFAULT: '#D70015', subtle: '#FDECEE', strong: '#A50011' },
          success: { DEFAULT: '#22A06B', subtle: '#E6F7EF', strong: '#11774A' },
          warning: { DEFAULT: '#F59E0B', subtle: '#FEF4E2', strong: '#8A5208' },
          info: { DEFAULT: '#1593C9', subtle: '#E6F4FB', strong: '#0B6690' },
        },
      },
      fontFamily: {
        sans: ['"Public Sans"', 'Montserrat', '"Open Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        // CR-190 (D2): Inter self-host (@fontsource-variable/inter, OFL), hanya lewat kelas `font-v5` di layout auth.
        v5: ['"Inter Variable"', 'Inter', 'system-ui', 'sans-serif'],
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
        // CR-190: radius V5 (aditif; rounded-lg/xl/2xl bawaan tidak berubah).
        'v5-sm': '10px',
        'v5-md': '12px',
        'v5-lg': '18px',
        'v5-xl': '24px',
        'v5-2xl': '30px',
      },
      boxShadow: {
        card: '0 1px 2px 0 rgb(16 24 40 / 0.04), 0 1px 3px 0 rgb(16 24 40 / 0.06)',
        panel: '0 8px 24px -8px rgb(16 24 40 / 0.12)',
        float: '0 12px 32px -12px rgb(28 57 100 / 0.28)',
        // Pemisah kolom yang menempel di kanan tabel (kolom Aksi sticky, CR-038) saat ada isi tergulir di bawahnya.
        'sticky-end': '-8px 0 8px -8px rgb(16 24 40 / 0.18)',
        // CR-190: efek V5 (Liquid Glass Card, Shadow/Button Primary, Focus/Ring 2 lapis putih + biru/merah).
        'v5-card': 'inset 0 1px 0 0 rgb(255 255 255 / 0.95), 0 30px 80px -16px rgb(28 33 64 / 0.28), 0 2px 8px 0 rgb(28 33 64 / 0.06)',
        'v5-button': '0 4px 14px 0 rgb(45 63 154 / 0.35), inset 0 1px 0 0 rgb(255 255 255 / 0.28)',
        'v5-focus': '0 0 0 2px #FFFFFF, 0 0 0 4px #4560E6',
        'v5-focus-error': '0 0 0 2px #FFFFFF, 0 0 0 4px #D70015',
      },
      transitionDuration: {
        DEFAULT: '150ms',
      },
    },
  },
  plugins: [],
}
