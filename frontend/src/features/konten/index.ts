/**
 * Pintu tunggal modul konten admin Fase 7 (Jalur A). Komponen untuk beranda (dipasang Jalur D, PRD Fase 7 v2.0 §7):
 * memuat data sendiri, kosong/403/galat → tidak merender, tanpa memancarkan galat ke parent.
 */
export { default as BannerCarousel } from './components/BannerCarousel.vue'
export { default as BeritaTerbaruWidget } from './components/BeritaTerbaruWidget.vue'
export { default as PopupAktifDialog } from './components/PopupAktifDialog.vue'
export { ROUTE_DETAIL_BERITA, ROUTE_PORTAL_BERITA } from './routes'
export { resetSesiKonten } from './sesi'
export { default as TargetPicker } from './components/TargetPicker.vue'
export { SEMUA_KEMENTERIAN } from './target'
export type { SpesifikasiTarget } from './types'
