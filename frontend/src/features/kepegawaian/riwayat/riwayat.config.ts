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

export type FieldType = 'text' | 'date' | 'number' | 'select' | 'textarea' | 'ref' | 'multiselect'

export interface FieldOption {
  value: string
  label: string
  /** Atribut tambahan opsi master (`extra`, MAKE-018), dibaca syarat `FieldCondition.opsi`. */
  extra?: Readonly<Record<string, string | null>>
}

/**
 * Syarat tampil sebuah isian dari nilai isian lain (pola aturan bersyarat Definisi, mis. bidang/jurusan pendidikan
 * hanya untuk jenjang di atas SMA). `in` / `notIn` = nilai (string) isian `field`.
 */
export interface FieldCondition {
  field: string
  in?: readonly string[]
  notIn?: readonly string[]
  /** Nilai angka isian syarat ≥ min / ≤ max (mis. skema SKP menurut tahun). */
  min?: number
  max?: number
  /**
   * Bandingkan atribut `extra[opsi]` opsi TERPILIH di isian ref `field`, bukan nilainya (MAKE-018), mis. sub group
   * jabatan `need_satker` = '1'. Hanya dievaluasi untuk `when` filter opsi (perlu daftar opsi); `showWhen` tidak memakainya.
   */
  opsi?: string
}

/** Satu filter opsi master dari nilai isian lain (`?<param>=<nilai isian field>`), opsional bersyarat `when` (MAKE-018). */
export interface FilterOpsi {
  param: string
  field: string
  when?: FieldCondition
}

/** Muat ulang isian multiselect saat Edit dari kolom teks baris (MAKE-018), mis. nama dipisah `***` (legacy jabatan). */
export interface LoadFromDef {
  kolom: string
  pemisah: string
  /** Pencocokan potongan teks ke opsi: `label` = nama opsi (tanpa beda huruf besar/kecil & spasi tepi). */
  cocokkan: 'label'
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
  /** `ref`: induk tetap opsi master (`?parent=`), mis. sub-group jabatan fungsional = group 2 (legacy Diklat). */
  optionsParent?: string
  /**
   * `ref`/`multiselect`: opsi disaring nilai isian lain lewat filter master (`?<param>=<nilai isian>`), mis. diklat per
   * `jenis_diklat`. Bentuk tunggal (lama): opsi baru dimuat setelah isian pemicu terisi. Bentuk jamak (MAKE-018):
   * parameter yang isiannya kosong atau `when`-nya tidak terpenuhi tidak dikirim. Pemicu berubah → opsi dimuat ulang dan
   * nilai isian ini dikosongkan.
   */
  optionsFilter?: { param: string; field: string } | readonly FilterOpsi[]
  /**
   * `ref`/`multiselect`: sumber opsi kustom (MAKE-018) — path API (respons `[{id, nama, parent}]`) dari nilai form;
   * null = belum bisa dimuat (opsi kosong). Path berubah → dimuat ulang dan nilai dikosongkan. Menggantikan `entity`.
   */
  optionsSource?: (values: Record<string, string>) => string | null
  /** `multiselect`: muat ulang nilai saat Edit dengan mencocokkan teks kolom baris ke opsi (MAKE-018). */
  loadFrom?: LoadFromDef
  /** `ref`: filter master tetap (`?<kolom>=<nilai>`), mis. jabatan JF tertentu `{ id_group_jabatan: '2' }` (AK SIASN). */
  optionsQuery?: Readonly<Record<string, string>>
  /** `ref`: opsi tambahan di akhir daftar master, mis. baris sistem LAIN-LAIN wilayah yang tidak ikut `options`. */
  extraOptions?: readonly FieldOption[]
  /** `text`: saran nilai dari nama master (`<datalist>`); nilai bebas tetap boleh (mis. bidang/instansi kursus). */
  suggestEntity?: string
  /**
   * Isian hitung di form (hanya-baca): nilainya dihitung dari isian lain setiap kali berubah, mis. nilai SKP 60% dan
   * kategori (legacy menghitungnya di peramban, `skp/form.php`). Hasil '' = kosong; `null` = tidak dihitung untuk nilai
   * ini (nilai tetap, isian bisa diisi), mis. periode SKP hanya dikunci untuk tahun 2022. `baris` = baris yang diedit
   * (null saat tambah), mis. AK Current (`jenis_ak` 1) tidak dihitung ulang. Saat Edit, hitung baru berjalan sesudah
   * pengguna mengubah isian — nilai tersimpan baris legacy tidak ditimpa saat form dibuka.
   */
  compute?: (values: Record<string, string>, baris: Readonly<Record<string, unknown>> | null) => string | null
  /** Batas atas angka yang bergantung isian lain (mis. nilai SKP maks 120 mulai 2021, 100 sebelumnya); null = tanpa batas. */
  batasAtas?: (values: Record<string, string>) => number | null
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

/** Berkas yang disimpan di kolom tabel riwayat (`berkas[<kolom>]`, `RiwayatDefinisi::kolomBerkas()`, MAKE-007). */
export interface BerkasKolomDef {
  kolom: string
  label: string
  wajib: boolean
  /** Wajib hanya bila syarat ini terpenuhi (salinan `kolomBerkasWajib()`). */
  wajibBila?: FieldCondition
  /** Batas & ekstensi (salinan AturanLampiran kolom itu); kosong = ikut pemanggil. */
  batas_mb?: number
  ekstensi?: string[]
}

/**
 * Pratinjau isian yang diisi server saat TAMBAH (`GET pegawai/{nip}/riwayat/{jenis}/pratinjau`, hook backend
 * `pratinjauTambah()`, MAKE-017). Dimuat saat form tambah dibuka dan saat isian `query` berubah.
 */
export interface PratinjauDef {
  /** Isian form yang dikirim sebagai query (mis. `tgl_pak` → masa kerja sampai tanggal PAK). */
  query: readonly string[]
  /** Isian form ← kunci pratinjau yang diisi otomatis selama isian masih kosong (mis. AK Lama). */
  isiOtomatis: Readonly<Record<string, string>>
  /** Baris tampilan hanya-baca (label + nilai dari hasil pratinjau). */
  tampil: readonly { label: string; nilai: (p: Readonly<Record<string, unknown>>) => string }[]
}

/** Aturan lampiran + label isian berkas. Kunci form/422 = `berkas.<id_riwayat>`. */
export interface LampiranDef extends AturanLampiran {
  label: string
  /** `wajib` hanya bila syarat ini terpenuhi (salinan `RiwayatDefinisi::lampiranWajib()`, mis. KTP bukan untuk kantor). */
  wajibBila?: FieldCondition
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
  /** Jenis punya sub-resource anak (`keluarga`): item ⋮ "Anak" membuka KeluargaAnakDialog. */
  kelolaAnak?: boolean
  /**
   * Berkas kolom Definisi (`kolomBerkas()`, MAKE-008, mis. arsip SKP periodik): diunggah lewat form Edit
   * (`berkas[<kolom>]`), diunduh lewat item ⋮ "Berkas". Jenis tanpa isian tetapi ber-berkas kolom tetap punya Edit.
   */
  berkasKolom?: BerkasKolomDef[]
  /** Pratinjau isian turunan di form tambah (MAKE-017, mis. AK: jabatan, pangkat, masa kerja, AK kumulatif, AK lama). */
  pratinjau?: PratinjauDef
}

/**
 * Isian tampil menurut `showWhen` terhadap nilai form saat ini (tanpa `showWhen` = selalu tampil). Isian syarat yang
 * masih kosong = belum tampil (mis. bidang pendidikan baru muncul setelah jenjang dipilih).
 */
export function fieldVisible(field: FieldDef, values: Record<string, string | undefined>): boolean {
  const cond = field.showWhen
  if (!cond) return true
  return cocokKondisi(cond, String(values[cond.field] ?? ''))
}

/** Syarat `cond` terpenuhi oleh nilai `nilai` (nilai isian, atau atribut opsi untuk `cond.opsi`); kosong = tidak. */
export function cocokKondisi(cond: FieldCondition, nilai: string): boolean {
  const value = nilai.trim()
  if (value === '') return false
  if (cond.in && !cond.in.includes(value)) return false
  if (cond.notIn && cond.notIn.includes(value)) return false
  if (cond.min !== undefined || cond.max !== undefined) {
    const angka = Number(value)
    if (!Number.isFinite(angka)) return false
    if (cond.min !== undefined && angka < cond.min) return false
    if (cond.max !== undefined && angka > cond.max) return false
  }
  return true
}

export const T = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'text', ...extra })
export const D = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'date', ...extra })
export const N = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'number', ...extra })
export const A = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'textarea', wide: true, ...extra })
export const R = (name: string, label: string, entity: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'ref', entity, ...extra })
export const S = (name: string, label: string, options: FieldOption[], extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'select', options, ...extra })
/** Pilihan jamak opsi master (MAKE-018): nilai form = daftar ID, dikirim `kolom[]`; wajib = minimal satu. */
export const M = (name: string, label: string, entity: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'multiselect', entity, ...extra })

export const defineRiwayatJenis = (config: RiwayatJenisConfig): RiwayatJenisConfig => config
