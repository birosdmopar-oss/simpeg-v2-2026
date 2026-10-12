/**
 * Bentuk konfigurasi satu jenis tab riwayat Detail Pegawai (mesin riwayat FE, pemilik WS-1 setelah Sprint 0-2).
 * Satu berkas per jenis di `jenis/<slug>.ts` (default export `RiwayatJenisConfig`, nama berkas = slug `{jenis}` beku),
 * dimuat otomatis `registry.ts` lewat `import.meta.glob` — tidak ada daftar terpusat.
 *
 * Nama `field`/`column` = nama kolom DDL tabel riwayat (payload & respons API = kolom DDL, README kontrak).
 * Isian per jenis adalah titik awal; pemilik jenis menyempurnakannya bersama Definisi backend-nya (validasi, opsi,
 * lampiran). Field rujukan master (`ref`) mengambil opsi dari `GET master/{entity}/options`.
 */
import type { RoleCode } from '@/features/auth/types'

import type { AturanLampiran, JenisTab } from '../types'

export type FieldType = 'text' | 'date' | 'number' | 'select' | 'textarea' | 'ref'

export interface FieldOption {
  value: string
  label: string
}

/**
 * Syarat tampil sebuah isian dari nilai isian lain (pola aturan bersyarat Definisi, mis. bidang/jurusan pendidikan
 * hanya untuk jenjang di atas SMA). `in` / `notIn` = nilai (string) isian `field`.
 */
export interface FieldCondition {
  field: string
  in?: readonly string[]
  notIn?: readonly string[]
}

export interface FieldDef {
  /** Nama kolom DDL. */
  name: string
  label: string
  type: FieldType
  required?: boolean
  /** Opsi `select` (nilai kode kolom, mis. '1'/'2'). */
  options?: FieldOption[]
  /** Entity master untuk `ref` (key `master/{entity}/options`, mis. 'jenjang-pendidikan'). */
  entity?: string
  /** Panjang maksimum teks (default 255; textarea 1000) — ikuti panjang kolom DDL. */
  max?: number
  placeholder?: string
  /** Lebar penuh (2 kolom) pada form. */
  wide?: boolean
  /**
   * Isian `ref` induk (sama dengan `MasterField::dependsOn` Definisi): opsi isian ini disaring `?parent=<nilai induk>`
   * dan dikosongkan saat induk berganti (dropdown berjenjang lewat `useCascadeOptions`).
   */
  dependsOn?: string
  /**
   * Isian hanya tampil (dan hanya wajib) bila syarat terpenuhi; isian tersembunyi dikirim kosong (= NULL). Validasi
   * yang mengikat tetap aturan bersyarat Definisi backend (422).
   */
  showWhen?: FieldCondition
  /**
   * Isian hitung: dikosongkan = dihitung server (mis. tanggal berakhir hukdis dari masa sanksi jenis). Form
   * menampilkan petunjuk ini di bawah isian.
   */
  hint?: string
  /**
   * Isian hitung yang dikosongkan saat pengguna mengubah salah satu isian ini (mis. tanggal berakhir hukdis saat TMT atau
   * jenis berganti), supaya server menghitung ulang — kecuali pengguna mengisinya lagi.
   */
  resetOn?: readonly string[]
}

export interface ColumnDef {
  /** Nama kolom DDL pada baris respons. */
  key: string
  label: string
  /** `date` = "1 Januari 2026"; default teks apa adanya. */
  format?: 'date'
  /** Kolom berkode (mis. `jenis_alamat`): tampilkan label opsi, bukan kodenya. */
  options?: FieldOption[]
}

/** Aturan lampiran + label isian berkas. Kunci form/422 = `berkas.<id_riwayat>`. */
export interface LampiranDef extends AturanLampiran {
  label: string
}

export interface RiwayatJenisConfig {
  /** Slug `{jenis}` (= nama berkas `jenis/<slug>.ts`). */
  jenis: JenisTab
  /** false = tab non-engine WS-2 (mis. `lkh`): datanya tidak lewat `riwayat/{jenis}`. */
  engine: boolean
  title: string
  subtitle: string
  /** Task Tech Spec pemilik jenis. */
  task: string
  /** Kata benda untuk tombol "Tambah …" dan judul dialog. */
  singular: string
  /** PK baris (`id_riwayat_*`) — dipakai untuk `{id}` dan `id_entri` lampiran. */
  primaryKey: string
  columns: ColumnDef[]
  fields: FieldDef[]
  /** Lampiran per kode `jenis_rwy`; kosong = jenis tanpa lampiran (atau belum ditetapkan Definisi-nya). */
  lampiran: LampiranDef[]
  /**
   * Role yang memilih status saat Edit (= `RiwayatDefinisi::rolePilihStatusSaatUbah()`, mis. KP/KGB role 1 ikut
   * legacy). Form edit role ini menampilkan isian `status` (Disetujui/Ditolak/Menunggu, bawaan Disetujui).
   */
  pilihStatusSaatUbah?: readonly RoleCode[]
  /**
   * Role yang tidak boleh MENGHAPUS baris berstatus Disetujui (salinan Definisi: `roleKunciHapusDisetujui()`, ditambah
   * UL_PEGAWAI bila `kunciBarisDisetujui()`). Item ⋮ "Hapus" baris itu tampil nonaktif (AGENTS.md §1.4); server tetap
   * menegakkan (403).
   */
  kunciHapusDisetujuiRole?: readonly RoleCode[]
  /** Role yang tidak boleh MENGUBAH baris berstatus Disetujui (UL_PEGAWAI bila `kunciBarisDisetujui()`); "Edit" nonaktif. */
  kunciUbahDisetujuiRole?: readonly RoleCode[]
}

/**
 * Isian tampil menurut `showWhen` terhadap nilai form saat ini (tanpa `showWhen` = selalu tampil). Isian syarat yang
 * masih kosong = belum tampil (mis. bidang pendidikan baru muncul setelah jenjang dipilih).
 */
export function fieldVisible(field: FieldDef, values: Record<string, string | undefined>): boolean {
  const cond = field.showWhen
  if (!cond) return true
  const value = String(values[cond.field] ?? '').trim()
  if (value === '') return false
  if (cond.in && !cond.in.includes(value)) return false
  if (cond.notIn && cond.notIn.includes(value)) return false
  return true
}

export const T = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'text', ...extra })
export const D = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'date', ...extra })
export const N = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'number', ...extra })
export const A = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'textarea', wide: true, ...extra })
export const R = (name: string, label: string, entity: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'ref', entity, ...extra })
export const S = (name: string, label: string, options: FieldOption[], extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'select', options, ...extra })

export const defineRiwayatJenis = (config: RiwayatJenisConfig): RiwayatJenisConfig => config
