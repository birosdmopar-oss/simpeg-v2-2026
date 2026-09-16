/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}'],
  theme: {
    extend: {
      // Palet dari Laporan Redesign SIMPEG (Tech Spec 1.6). Warna legacy ADR-020 (#1A237E/#E53935)
      // tetap tersedia sebagai `legacy` sampai QA visual Fase 1 mengkonfirmasi palet final.
      colors: {
        brand: {
          primary: '#1C3964',
          secondary: '#FFC043',
          tertiary: '#217AFF',
        },
        legacy: {
          primary: '#1A237E',
          accent: '#E53935',
        },
      },
      fontFamily: {
        sans: ['"Public Sans"', 'Montserrat', '"Open Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
