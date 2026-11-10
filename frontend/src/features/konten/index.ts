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
export { default as KontenGambarKelolaView } from './views/KontenGambarKelolaView.vue'
export type { JenisKontenGambar } from './types'
export { default as BeritaKelolaView } from './views/BeritaKelolaView.vue'
export { default as DiboKelolaView } from './views/DiboKelolaView.vue'
export { default as MasterKontenView } from './views/MasterKontenView.vue'
export type { JenisMasterKonten } from './types'
export { default as BeritaDetailView } from './views/BeritaDetailView.vue'
export { default as BeritaPortalView } from './views/BeritaPortalView.vue'
export { default as DiboBacaView } from './views/DiboBacaView.vue'
