/**
 * Konstanta helpdesk Halo Simpeg (PRD Fase 8 v1.3 §5.2 F8-04/F8-06, §6.1b, §7.3a; README kontrak beku
 * `backend/app/Controllers/Api/HaloSimpeg/README.md` bagian master MAKE-070). Jalur chatbot punya konstanta sendiri
 * (`chatbot/constants.ts`); helpdesk tidak mengimpor dari `chatbot/` (PRD §3).
 */
import { Role, type RoleCode } from '@/features/auth/types'

/** Gerbang rilis: menu dan polling helpdesk aktif saat `ACTIVE_PHASE >= 8` (H8-11 (a)). */
export const FASE_HALO = 8

/** Role yang memakai Halo Simpeg (F8-04): 1–7. Role 8 (Pimpinan) tanpa akses. */
export const HALO_ROLES: readonly RoleCode[] = [
  Role.SUPER_ADMIN,
  Role.PEGAWAI,
  Role.ADMIN_SATKER,
  Role.ADMIN_VIEW_ESELON1,
  Role.MENTERI,
  Role.PTT,
  Role.PPPK,
]

/** Layar admin master Kategori / Petugas / Kontak: role 1 (E-02, E-07). */
export const HALO_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

/** Status master helpdesk: 0 Tidak Aktif, 1 Aktif (berbeda dari chatbot 1/2). */
export const STATUS_MASTER = { TIDAK_AKTIF: 0, AKTIF: 1 } as const

/** Batas field master (PRD §7.3a, README beku). Panjang = code point sesudah trim. */
export const BATAS_MASTER = {
  namaKategori: 250,
  emailPetugas: 150,
  noHpPetugas: 20,
  namaKontak: 150,
  nomorKontak: 20,
  emailKontak: 150,
  cariKandidatMin: 2,
  cariKandidatMaks: 50,
} as const

/** Paginasi master (README final S3): `page` bulat 1..1.000.000 (di luar → 422 `errors.page`); `per_page` 1..100, bawaan 20. */
export const PER_PAGE_MASTER = 20
export const PER_PAGE_MAKS = 100
export const PAGE_MAKS = 1_000_000

/** Bawaan filter baca per tab (✔ Horii 16-11-2026): "Tiket saya" = semua [V2], antrean petugas = belum [K `VI:51`]. */
export const BACA_BAWAAN_SAYA = 'semua'
export const BACA_BAWAAN_ANTREAN = 'belum'

/** Kode galat bisnis bernama (`code` amplop v2) bagian master. */
export const KODE_GALAT_MASTER = {
  kategoriDuplikat: 'kategori_duplikat',
  kategoriDipakai: 'kategori_dipakai',
  petugasDuplikat: 'petugas_duplikat',
  petugasTerakhir: 'petugas_terakhir',
  kontakDuplikat: 'kontak_duplikat',
} as const

/** Teks galat per kolom dari README beku; dipakai validasi klien agar pesan klien = pesan BE. */
export const TEKS_GALAT_MASTER = {
  namaKategoriWajib: 'Nama kategori wajib diisi.',
  namaKategoriMaks: 'Nama kategori maksimal 250 karakter.',
  akunWajib: 'Akun petugas wajib dipilih.',
  kategoriMin: 'Minimal satu kategori.',
  emailWajib: 'Email wajib diisi.',
  emailFormat: 'Format email tidak valid.',
  emailMaks: 'Email maksimal 150 karakter.',
  noHpMaks: 'Nomor HP maksimal 20 karakter.',
  namaWajib: 'Nama wajib diisi.',
  namaMaks: 'Nama maksimal 150 karakter.',
  kontakWajib: 'Nomor kontak wajib diisi.',
  kontakMaks: 'Nomor kontak maksimal 20 karakter.',
  cariMin: 'Kata kunci minimal 2 karakter.',
  cariMaks: 'Kata kunci maksimal 50 karakter.',
  // README master final (MAKE-070 @56a700b1): teks galat BE yang tidak punya validasi klien padanan; ditampilkan dari amplop.
  kategoriTidakTersedia: 'Kategori tidak tersedia.',
  statusTidakValid: 'Status tidak valid.',
  halamanTidakValid: 'Halaman harus berupa angka bulat 1 sampai 1000000.',
  parameterDaftar: 'Parameter daftar tidak valid.',
  isianTeks: 'Isian harus berupa teks.',
  idTidakValid: 'ID tidak valid.',
} as const

/** Nilai `?tab=` tab antrean petugas di `/halo-simpeg` (tab pemilik = bawaan tanpa query). */
export const TAB_ANTREAN = 'antrean'

// --- tiket (README kontrak beku MAKE-071) --------------------------------------------------------------------------------

/** Batas isian tiket (PRD §7.3a, README tiket). Panjang = code point sesudah trim. */
export const BATAS_TIKET = {
  subjek: 250,
  pesan: 5000,
  /** Lampiran `arsip`: ≤ 5 MB, 9 ekstensi (`ValidasiBerkas`). */
  lampiranMb: 5,
} as const

export const EKSTENSI_LAMPIRAN = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'] as const

/** Pembuat tiket baru: role pegawai 2/6/7 (F8-04, README tiket). */
export const HALO_PEMBUAT_TIKET_ROLES: readonly RoleCode[] = [Role.PEGAWAI, Role.PTT, Role.PPPK]

/** Polling ringkasan helpdesk saat tab terlihat (pola lonceng `useRingkasanNotifikasi`). */
export const INTERVAL_RINGKASAN_MS = 60_000

/** Kode galat bisnis bernama bagian tiket. */
export const KODE_GALAT_TIKET = {
  bukanPihakBerwenang: 'bukan_pihak_berwenang',
  tiketDitutup: 'tiket_ditutup',
  tiketTanpaBalasan: 'tiket_tanpa_balasan',
  tiketSudahDitutup: 'tiket_sudah_ditutup',
  tiketTanpaKategori: 'tiket_tanpa_kategori',
  throttle: 'throttle',
} as const

/** Teks galat per kolom tiket dari README beku (pesan klien = pesan BE). */
export const TEKS_GALAT_TIKET = {
  kategoriWajib: 'Kategori wajib dipilih.',
  subjekWajib: 'Subjek wajib diisi.',
  subjekMaks: 'Subjek maksimal 250 karakter.',
  pesanWajib: 'Pesan wajib diisi.',
  pesanMaks: 'Pesan maksimal 5.000 karakter.',
  tidakDitemukan: 'Data percakapan tidak ditemukan.',
  lampiranTidakDitemukan: 'Lampiran tidak ditemukan.',
} as const

