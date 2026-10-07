/**
 * Bentuk konfigurasi satu jenis tab riwayat Detail Pegawai (mesin riwayat FE, pemilik WS-1 setelah Sprint 0-2).
 * Satu berkas per jenis di `jenis/<slug>.ts` (default export `RiwayatJenisConfig`, nama berkas = slug `{jenis}` beku),
 * dimuat otomatis `registry.ts` lewat `import.meta.glob` — tidak ada daftar terpusat.
 *
 * Nama `field`/`column` = nama kolom DDL tabel riwayat (payload & respons API = kolom DDL, README kontrak).
 * Isian per jenis adalah titik awal; pemilik jenis menyempurnakannya bersama Definisi backend-nya (validasi, opsi,
 * lampiran). Field rujukan master (`ref`) mengambil opsi dari `GET master/{entity}/options`.
 */
import type { AturanLampiran, JenisTab } from '../types'

export type FieldType = 'text' | 'date' | 'number' | 'select' | 'textarea' | 'ref'

export interface FieldOption {
  value: string
  label: string
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
}

export const T = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'text', ...extra })
export const D = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'date', ...extra })
export const N = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'number', ...extra })
export const A = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'textarea', wide: true, ...extra })
export const R = (name: string, label: string, entity: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'ref', entity, ...extra })
export const S = (name: string, label: string, options: FieldOption[], extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'select', options, ...extra })

export const defineRiwayatJenis = (config: RiwayatJenisConfig): RiwayatJenisConfig => config
