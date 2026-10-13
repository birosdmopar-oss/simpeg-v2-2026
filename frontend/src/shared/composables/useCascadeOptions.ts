/**
 * Re-export cascade opsi master (provinsi → kota → …) untuk modul selain Master Data (Fase 3 dst.).
 * Berkas asal tetap di `features/master-data/composables/useCascadeOptions.ts`; impor lewat sini dari modul lain.
 */
export { resolveAncestorPath, useCascadeOptions, type CascadeLevel } from '@/features/master-data/composables/useCascadeOptions'
