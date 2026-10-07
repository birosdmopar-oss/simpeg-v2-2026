/**
 * Tipe Modul B — Kepegawaian Core (Fase 3). Nama field = nama kolom DDL tabelnya (snake_case persis, ADR-023;
 * README kontrak `backend/app/Controllers/Api/Kepegawaian/README.md`). Sumber DDL: migration
 * `2026-09-30-120000_CreatePegawai.php`, `120100_CreatePegawaiSnapshot.php`, `13*` (riwayat), `130900`
 * (`document_attachment`). Satu-satunya pengecualian snake_case: kunci `NIP` huruf besar pada baris lampiran.
 *
 * Kolom internal yang tidak dipakai UI (sinkron SIASN `id_rw_siasn`/`siasn_*`, notifikasi `show_*`/`notif_date`) tidak
 * dicantumkan; bila backend mengirimnya, field itu diabaikan.
 */
import type { ApprovalPayload } from '@/shared/components/approvalActions'
import type { StatusRiwayat } from '@/shared/components/statusRiwayat'

export { PEGAWAI_LIST_ROLES } from './roles'
export { STATUS_RIWAYAT, STATUS_RIWAYAT_LABELS, type StatusRiwayat } from '@/shared/components/statusRiwayat'

/* ----------------------------------------------------------------------------------------------------------------
 * Slug {jenis} & descriptor tab (beku S0-A MAKE-002)
 * ---------------------------------------------------------------------------------------------------------------- */

/** Slug `{jenis}` engine riwayat — sama persis dengan `JenisRiwayat::SLUG` backend (17 slug, beku). */
export const JENIS_ENGINE = [
  'jabatan',
  'kp',
  'kgb',
  'pendidikan',
  'diklat',
  'seminar',
  'skp',
  'skp-periodik',
  'hukdis',
  'ak',
  'ak-siasn',
  'keluarga',
  'alamat',
  'tanda-jasa',
  'organisasi',
  'karpeg',
  'kariskarsu',
] as const

export type JenisEngine = (typeof JENIS_ENGINE)[number]

/** Slug tab non-engine milik WS-2 (bentuk descriptor sama; endpoint datanya sendiri, bukan `riwayat/{jenis}`). */
export const JENIS_NON_ENGINE = ['lkh'] as const

export type JenisNonEngine = (typeof JENIS_NON_ENGINE)[number]

/** Nilai `jenis` pada `data.tabs` = slug engine ∪ slug non-engine. */
export type JenisTab = JenisEngine | JenisNonEngine

/** Jenis ber-alur usulan mandiri — tidak pernah menjadi tab Detail Pegawai. */
export const JENIS_USULAN: readonly JenisEngine[] = ['karpeg', 'kariskarsu']

export const isJenisEngine = (jenis: string): jenis is JenisEngine => (JENIS_ENGINE as readonly string[]).includes(jenis)

/** Descriptor tab dari `GET pegawai/{nip}` → `data.tabs` (beku). */
export interface RiwayatTabDescriptor {
  jenis: JenisTab
  label: string
  can_view: boolean
  can_create: boolean
  can_edit: boolean
  can_delete: boolean
  can_process: boolean
}

/** Satu item baris tab Detail Pegawai (Data Umum + tab dari descriptor). */
export interface RiwayatMenu {
  key: string
  label: string
}

/** Body `POST pegawai/{nip}/riwayat/{jenis}/{id}/process`. */
export type ProcessRiwayatPayload = ApprovalPayload

/* ----------------------------------------------------------------------------------------------------------------
 * Pegawai (B-01)
 * ---------------------------------------------------------------------------------------------------------------- */

/** Kolom tabel `pegawai` (PK `nip`). `jenis_kelamin` 1 = laki-laki, 2 = perempuan; `status` 1/2/10. */
export interface Pegawai {
  nip: string
  id_provinsi_lahir: string | null
  id_kabupaten_kota_lahir: string | null
  id_agama: number | null
  id_jenis_pegawai: number | null
  id_jenis_status: number | null
  nip_lama: string | null
  nama: string
  glr_awal: string | null
  glr_akhir: string | null
  tgl_lahir: string
  provinsi_lahir: string | null
  provinsi_lahir_lain: string | null
  kabupaten_kota_lahir: string | null
  kabupaten_kota_lahir_lain: string | null
  agama: string | null
  jenis_kelamin: 1 | 2
  npwp: string | null
  nik: string | null
  bpjs_kes: string | null
  bpjs_ket: string | null
  no_taspen: string | null
  no_hp: string | null
  jenis_kerabat: string | null
  no_telp_kerabat: string | null
  status_pernikahan: number | null
  jenis_pegawai: string | null
  jenis_status: string | null
  tmt_status: string | null
  foto: string | null
  keterangan: string | null
  status: number
  flag_update: number
  id_pns_siasn: string | null
}

/**
 * `GET pegawai/{nip}` → `data`. Yang dibekukan S0-A hanya `tabs`; field biodata = kolom `pegawai`.
 * TODO(B-20, WS-2): field tambahan (snapshot jabatan/pangkat untuk kartu identitas, foto) ditetapkan saat endpoint dibuat.
 */
export interface PegawaiDetail extends Pegawai {
  tabs: RiwayatTabDescriptor[]
}

/**
 * Satu baris `GET pegawai`. Kolom `pegawai` saja yang pasti ada.
 * TODO(B-20, WS-2): kolom snapshot (jabatan, unit, satker, gol_ruang) & parameter filter ditetapkan saat endpoint dibuat.
 */
export type PegawaiListItem = Pick<
  Pegawai,
  'nip' | 'nip_lama' | 'nama' | 'glr_awal' | 'glr_akhir' | 'jenis_kelamin' | 'agama' | 'jenis_pegawai' | 'jenis_status' | 'tmt_status'
>

export interface PegawaiListQuery {
  page: number
  per_page: number
  /** Pencarian nama/NIP. TODO(B-20): nama parameter dikonfirmasi saat endpoint dibuat. */
  search?: string
}

/** Konvensi daftar ber-paginasi proyek (sama dengan Manajemen Akun/Master Data). TODO(B-20): konfirmasi. */
export interface PegawaiListResponse {
  items: PegawaiListItem[]
  total: number
  page: number
  per_page: number
}

/** Simpul bagan struktur organisasi (B-19). TODO(B-19, WS-2): bentuk & endpoint ditetapkan saat dibuat. */
export interface OrgNode {
  id: string
  jabatan: string
  nama: string
  nama_lengkap: string
  children: OrgNode[]
}

export interface SelectOptionItem {
  value: string
  label: string
}

/* ----------------------------------------------------------------------------------------------------------------
 * Riwayat (B-02) — satu interface per tabel `riwayat_*`
 * ---------------------------------------------------------------------------------------------------------------- */

/** Kolom bersama baris riwayat engine (alur verifikasi). */
export interface RiwayatBase {
  nip: string
  status: StatusRiwayat
  reason_note: string | null
  created_at?: string | null
  updated_at?: string | null
  updated_by?: number | null
  approved_by?: number | null
}

/** `riwayat_mutasi_jabatan` (slug `jabatan`). */
export interface RiwayatMutasiJabatan extends RiwayatBase {
  id_riwayat_mutasi_jabatan: number
  id_group_jabatan: number | null
  id_sub_group_jabatan: number | null
  id_unit: number | null
  id_satker: number | null
  id_atasan_es_1: number | null
  id_atasan_es_2: number | null
  id_atasan_es_3: number | null
  id_atasan_es_3_koord: number | null
  id_atasan_es_4: number | null
  id_atasan_es_4_koord: number | null
  id_jabatan: number | null
  id_jabatan_koord: number | null
  id_gol_pppk: number | null
  id_rumpun_jabatan: number | null
  jenis_jabatan: number
  jenis_jabatan_koord: number
  jenis_mutasi: number
  tmtsk: string
  no_sk: string | null
  tgl_sk: string | null
  pmk: number | null
  mhpk_mulai: string | null
  mhpk_akhir: string | null
  gol_pppk: string | null
  nama_instansi: string | null
  group_jabatan: string | null
  sub_group_jabatan: string | null
  unit: string | null
  satker: string | null
  atasan_es_1: string | null
  atasan_es_2: string | null
  atasan_es_3: string | null
  atasan_es_3_koord: string | null
  atasan_es_4: string | null
  atasan_es_4_koord: string | null
  jabatan: string | null
  jabatan_koord: string | null
  jabatan_lain: string | null
  rumpun_jabatan: string | null
  subrumpun_jabatan: string | null
  kelas_jabatan: number | null
  kredit_jft: number | null
  no_induk_jft: string | null
  status_jft: number | null
  ser_dosen: string | null
  f_spmt: string | null
  f_bapel: string | null
  keterangan: string | null
}

/** `riwayat_kp` (slug `kp`). */
export interface RiwayatKp extends RiwayatBase {
  id_riwayat_kp: number
  id_jenis_kp: number | null
  id_pangkat: number | null
  tmtsk: string
  tgl_sk: string | null
  no_sk: string | null
  tgl_pertek_bkn: string | null
  no_pertek_bkn: string | null
  jumlah_kredit_utama: number | null
  jumlah_kredit_tambahan: number | null
  jenis_kp: string | null
  gol: string | null
  ruang: string | null
  gol_ruang: string | null
  pangkat: string | null
  mker_th: number | null
  mker_bl: number | null
  gaji_pokok: number | null
  keterangan: string | null
}

/** `riwayat_kgb` (slug `kgb`). */
export interface RiwayatKgb extends RiwayatBase {
  id_riwayat_kgb: number
  id_gol_pppk: number | null
  tmtsk: string
  no_sk: string | null
  tgl_sk: string
  gol_pppk: string | null
  mker_gol_th: number | null
  gaji_pokok: number | null
  jym: string | null
  keterangan: string | null
}

/** `riwayat_pendidikan` (slug `pendidikan`). */
export interface RiwayatPendidikan extends RiwayatBase {
  id_riwayat_pendidikan: number
  id_jenjang_pendidikan: number | null
  id_bidang_pendidikan: number | null
  id_jurusan_pendidikan: number | null
  tgl_lulus: string
  no_ijazah: string | null
  no_sk_penc_glr: string | null
  institusi_pendidikan: string | null
  jenjang_pendidikan_singkat: string | null
  bidang_pendidikan: string | null
  bidang_pendidikan_lain: string | null
  jurusan_pendidikan: string | null
  jurusan_pendidikan_lain: string | null
  nem: number | string | null
  ipk: number | string | null
  keterangan: string | null
  glr_awal: string | null
  glr_akhir: string | null
}

/** `riwayat_diklat` (slug `diklat`). */
export interface RiwayatDiklat extends RiwayatBase {
  id_riwayat_diklat: number
  id_diklat: number | null
  id_sub_group_jabatan: number | null
  id_rumpun_sertifikasi: number | null
  id_lembaga_sertifikasi: number | null
  jenis_diklat: number | null
  rumpun_sertifikasi: string | null
  tgl_sertifikat: string | null
  mb_sertifikat_awal: string | null
  mb_sertifikat_akhir: string | null
  no_sertifikat: string | null
  jumlah_jp: number | null
  nama_diklat: string | null
  nama_diklat_lain: string | null
  sub_group_jabatan: string | null
  deskripsi: string | null
  instansi_penyelenggara: string | null
  keterangan: string | null
}

/** `riwayat_seminar` (slug `seminar`). */
export interface RiwayatSeminar extends RiwayatBase {
  id_riwayat_seminar: number
  jenis_seminar: number
  bidang_seminar: string | null
  nama_seminar: string
  tgl_sertifikat: string | null
  no_sertifikat: string | null
  jumlah_jp: number | null
  instansi_penyelenggara: string | null
  keterangan: string | null
}

/** `riwayat_skp` (slug `skp`). */
export interface RiwayatSkp extends RiwayatBase {
  id_riwayat_skp: number
  tahun: number
  tgl_mulai: string | null
  tgl_akhir: string | null
  nip_penilai: string | null
  nama_penilai: string | null
  jabatan_penilai: string | null
  nip_atasan_penilai: string | null
  nama_atasan_penilai: string | null
  jabatan_atasan_penilai: string | null
  nilai_skp: number | string | null
  nilai_skp_60_persen: number | string | null
  rating_skp: number | null
  nilai_perilaku: number | string | null
  nilai_perilaku_40_persen: number | string | null
  rating_perilaku: number | null
  nilai_prestasi_kerja: number | string | null
  kategori_nilai_prestasi: string | null
  keterangan: string | null
  gol_ruang_penilai: string | null
  unor_penilai: string | null
  status_penilai: string | null
}

/** `riwayat_skp_periodik` (slug `skp-periodik`; tanpa `reason_note` di DDL, status 0/1/2/10). */
export interface RiwayatSkpPeriodik extends Omit<RiwayatBase, 'reason_note'> {
  id_riwayat_skp_periodik: number
  id_pns: string
  periode_id: string | null
  skp_id: string | null
  skp_penilaian_id: string | null
  jenis: number | null
  tahun_skp: number | null
  nama: string
  periode_awal_skp: string | null
  periode_akhir_skp: string | null
  skp_unor_id: string | null
  skp_unor: string | null
  skp_unor_induk: string | null
  skp_jabatan: string | null
  skp_jenis_jabatan: number | null
  is_skp_plt_plh_pjb: number | null
  hasil_kerja: string | null
  perilaku_kerja: string | null
  hasil_akhir: string | null
  pegawai_atasan_id: string | null
  pegawai_atasan_nip: string | null
  pegawai_atasan_nama: string | null
  pegawai_atasan_unor_id: string | null
  pegawai_atasan_unor: string | null
  pegawai_atasan_jabatan: string | null
  pegawai_atasan_golru: string | null
  waktu_dinilai: string | null
  pegawai_penilai_id: string | null
  golru: string | null
  f_arsip_1: string | null
  f_arsip_2: string | null
}

/** `riwayat_hukdis` (slug `hukdis`). */
export interface RiwayatHukdis extends RiwayatBase {
  id_riwayat_hukdis: number
  id_tingkat_hukdis: number | null
  id_jenis_hukdis: number | null
  id_pangkat: number | null
  tmtsk: string
  tgl_sk: string | null
  no_sk: string | null
  tingkat_hukdis: string | null
  jenis_hukdis: string | null
  gol: string | null
  ruang: string | null
  gol_ruang: string | null
  pangkat: string | null
  masa_hukuman: string | null
  akhir_hukdis: string | null
  aturan_dilanggar: string | null
  alasan_hukuman: string | null
  keterangan: string | null
}

/** `riwayat_ak` (slug `ak`). */
export interface RiwayatAk extends RiwayatBase {
  id_riwayat_ak: number
  id_jabatan: number | null
  id_pangkat: number | null
  jenis_ak: number
  nama: string | null
  no_induk_jf: string | null
  no_hpak: string | null
  tgl_pak: string | null
  periode_awal: string | null
  periode_akhir: string | null
  ak_kum_kp: number | null
  ak_kum_next_jen: number | null
  ak_terakhir: number
  pengajuan_ak: number
  kredit_utama_baru: number
  kredit_penunjang_baru: number
  nilai_ak: number
  nama_pym: string | null
  jab_pym: string | null
  flag_instansi_ym: number
  instansi_ym: string | null
  jabatan: string | null
  tmt_jab: string | null
  mker_th_jab: number | null
  mker_bl_jab: number | null
  pangkat: string | null
  gol_ruang: string | null
  tmt_pang: string | null
  mker_th_pang: number | null
  mker_bl_pang: number | null
  keterangan: string | null
  file_pak: string | null
}

/** `riwayat_ak_siasn` (slug `ak-siasn`; tanpa `reason_note` di DDL). */
export interface RiwayatAkSiasn extends Omit<RiwayatBase, 'reason_note'> {
  id_riwayat_ak_siasn: number
  id_pns: string | null
  id_jabatan: number | null
  id_pangkat: number | null
  no_sk: string | null
  tgl_sk: string | null
  bulan_mulai_penilaian: number | null
  tahun_mulai_penilaian: number | null
  bulan_selesai_penilaian: number | null
  tahun_selesai_penilaian: number | null
  kredit_utama_baru: number | string | null
  kredit_penunjang_baru: number | string | null
  kredit_baru_total: number | string | null
  id_rw_jabatan_siasn: string | null
  nama_jabatan: string | null
  is_angka_kredit_pertama: string | null
  is_integrasi: string | null
  is_konversi: string | null
  f_pak_siasn: string | null
  f_pak: string | null
  sumber: string | null
  is_pemenuhan_kp: string | null
  keterangan: string | null
}

/** `riwayat_keluarga` (slug `keluarga`). */
export interface RiwayatKeluarga extends RiwayatBase {
  id_riwayat_keluarga: number
  urutan_perkawinan: number
  tgl_perkawinan: string
  kota_perkawinan: string | null
  nama_pasangan: string
  jumlah_anak: number
  keterangan: string | null
}

/** `riwayat_alamat` (slug `alamat`). `alamat_utama` 1/2, `jenis_alamat` 1/2 (legacy). */
export interface RiwayatAlamat extends RiwayatBase {
  id_riwayat_alamat: number
  id_provinsi: string | null
  id_kabupaten_kota: string | null
  id_kecamatan: string | null
  id_kelurahan: string | null
  alamat_utama: number
  jenis_alamat: number
  provinsi: string | null
  provinsi_lain: string | null
  kabupaten_kota: string | null
  kabupaten_kota_lain: string | null
  kecamatan: string | null
  kecamatan_lain: string | null
  kelurahan: string | null
  kelurahan_lain: string | null
  kd_pos: string | null
  alamat: string | null
  keterangan: string | null
  KdPos: string | null
  nama_kantor: string | null
  lantai: string | null
  telp: string | null
  faks: string | null
}

/** `riwayat_tanda_jasa` (slug `tanda-jasa`). */
export interface RiwayatTandaJasa extends RiwayatBase {
  id_riwayat_tanda_jasa: number
  id_tanda_jasa: number | null
  tgl_sertifikat: string
  no_sertifikat: string | null
  tanda_jasa: string | null
  tanda_jasa_lain: string | null
  negara: string | null
  keterangan: string | null
}

/** `riwayat_organisasi` (slug `organisasi`). */
export interface RiwayatOrganisasi extends RiwayatBase {
  id_riwayat_organisasi: number
  nama_organisasi: string | null
  kedudukan: string | null
  tgl_mulai: string | null
  tgl_akhir: string | null
  keterangan: string | null
}

/** `riwayat_karpeg` (slug `karpeg`, usulan mandiri). Berkas `file_*` = path internal, tidak dipakai klien. */
export interface RiwayatKarpeg extends RiwayatBase {
  id_riwayat_karpeg: number
  jenis_permohonan: string
  rated: number
  show_pl: number
}

/** `riwayat_kariskarsu` (slug `kariskarsu`, usulan mandiri). */
export interface RiwayatKariskarsu extends RiwayatBase {
  id_riwayat_kariskarsu: number
  rated: number
  show_pl: number
}

/** `riwayat_lckh` (tab non-engine `lkh`, WS-2). */
export interface RiwayatLckh {
  id_riwayat_lckh: number
  id_unit: number | null
  id_satker: number | null
  nip: string
  tgl_laporan: string
  nama: string
  unit: string | null
  satker: string | null
  nip_atasan: string | null
  nama_atasan: string
  kegiatan: string
  output: string
  jumlah_diselesaikan: string | null
  jam_mulai: string | null
  jam_selesai: string | null
  catatan: string | null
  status: number
}

/** Baris riwayat per slug engine. */
export interface RiwayatRowByJenis {
  jabatan: RiwayatMutasiJabatan
  kp: RiwayatKp
  kgb: RiwayatKgb
  pendidikan: RiwayatPendidikan
  diklat: RiwayatDiklat
  seminar: RiwayatSeminar
  skp: RiwayatSkp
  'skp-periodik': RiwayatSkpPeriodik
  hukdis: RiwayatHukdis
  ak: RiwayatAk
  'ak-siasn': RiwayatAkSiasn
  keluarga: RiwayatKeluarga
  alamat: RiwayatAlamat
  'tanda-jasa': RiwayatTandaJasa
  organisasi: RiwayatOrganisasi
  karpeg: RiwayatKarpeg
  kariskarsu: RiwayatKariskarsu
}

/** Baris riwayat generik (komponen engine membaca kolom lewat nama dari konfigurasi jenis). */
export type RiwayatRow = Record<string, unknown> & { status?: StatusRiwayat | number; reason_note?: string | null }

/* ----------------------------------------------------------------------------------------------------------------
 * Lampiran (B-18) — `document_attachment`
 * ---------------------------------------------------------------------------------------------------------------- */

/**
 * Baris `document_attachment` apa adanya; kunci `NIP` HURUF BESAR (pengecualian snake_case, nama kolom DDL).
 * `path` internal tidak dikirim backend.
 */
export interface DocumentAttachment {
  id_attachment: number
  NIP: string
  document_id: number | null
  filename: string | null
  id_riwayat: number | null
  nama_riwayat: string | null
  /** Kolom DDL varchar(100): PK baris riwayat sebagai teks. */
  id_entri: string | null
  tag: string | null
  url: string | null
  basename: string | null
  display_name: string | null
  file_size: number | null
  file_ext: string | null
  file_type: string | null
  created_at?: string | null
  updated_at?: string | null
}

/** Aturan lampiran per kode `jenis_rwy` (bentuk `AturanLampiran::toArray()` backend). */
export interface AturanLampiran {
  id_riwayat: number
  wajib: boolean
  /** 1, 2, atau 5 (ikut legacy). */
  batas_mb: 1 | 2 | 5
  /** Ekstensi tanpa titik, huruf kecil. */
  ekstensi: string[]
}
