// Tab riwayat `jabatan` — tabel `riwayat_mutasi_jabatan`, B-07 (pemilik WS-2). Isian dan lampiran disalin dari Definisi
// backend `Riwayat/Definisi/Jabatan.php` (MAKE-012; legacy `L_jabatan.php` validate_param()/set_param()). Nama master
// (unit, satker, jabatan, atasan, rumpun) disalin server; aturan wajib bersyarat yang mengikat tetap 422 backend.
// Atasan Es I–IV diturunkan server dari struktur_jabatan (tidak diisi di form). Sub rumpun hanya-baca dan jabatan disaring
// satu filter (sub group) sampai hook FE WS-1 MAKE-018 (multi-select, filter jamak) masuk.
import { Role } from '@/features/auth/types'

import { A, D, N, R, S, T, defineRiwayatJenis } from '../riwayat.config'
import type { FieldCondition } from '../riwayat.config'

const EKSTENSI = ['jpg', 'jpeg', 'png', 'gif', 'pdf']

const JENIS_JABATAN = [
  { value: '1', label: 'Nomenklatur' },
  { value: '2', label: 'Instansi Lain' },
  { value: '3', label: 'Plt' },
]

const NOMENKLATUR_PLT: FieldCondition = { field: 'jenis_jabatan', in: ['1', '3'] }
const INSTANSI_LAIN: FieldCondition = { field: 'jenis_jabatan', in: ['2'] }
const JFT: FieldCondition = { field: 'id_group_jabatan', in: ['2'] }

export default defineRiwayatJenis({
  jenis: 'jabatan',
  engine: true,
  title: 'Riwayat Jabatan',
  subtitle: 'Riwayat mutasi jabatan struktural, fungsional, dan pelaksana.',
  task: 'B-07',
  singular: 'jabatan',
  primaryKey: 'id_riwayat_mutasi_jabatan',
  columns: [
    { key: 'jabatan', label: 'Jabatan' },
    { key: 'unit', label: 'Unit' },
    { key: 'satker', label: 'Satker' },
    // Atasan diturunkan server dari struktur jabatan periode aktif (hanya-baca di FE, keputusan reviewer Q3 M4).
    { key: 'atasan_es_3', label: 'Atasan Es. III' },
    { key: 'atasan_es_4', label: 'Atasan Es. IV' },
    { key: 'jenis_jabatan', label: 'Jenis', options: JENIS_JABATAN },
    { key: 'tmtsk', label: 'TMT', format: 'date' },
    { key: 'no_sk', label: 'No. SK' },
  ],
  fields: [
    S('jenis_jabatan', 'Jenis Jabatan', JENIS_JABATAN, { required: true }),
    S('jenis_mutasi', 'Jenis Mutasi', [
      { value: '1', label: 'Mutasi Jabatan' },
      { value: '2', label: 'Mutasi Unor' },
    ], { required: true, showWhen: { field: 'jenis_jabatan', in: ['1'] } }),
    D('tmtsk', 'TMT SK', { required: true }),
    T('no_sk', 'Nomor SK', { required: true, max: 100 }),
    D('tgl_sk', 'Tanggal SK', { required: true }),
    // Nomenklatur / Plt — rujukan master G-02.
    R('id_group_jabatan', 'Group Jabatan', 'group-jabatan', { required: true, showWhen: NOMENKLATUR_PLT }),
    R('id_sub_group_jabatan', 'Sub Group Jabatan', 'sub-group-jabatan', { required: true, dependsOn: 'id_group_jabatan', showWhen: NOMENKLATUR_PLT }),
    S('jenis_jabatan_koord', 'Tugas Koordinasi', [
      { value: '1', label: 'Koordinator' },
      { value: '2', label: 'Subkoordinator' },
      { value: '3', label: 'Tidak Ada' },
    ], { showWhen: { field: 'id_group_jabatan', in: ['2', '3'] }, hint: 'Wajib untuk jabatan fungsional.' }),
    R('id_unit', 'Unit Kerja', 'unit', { required: true, showWhen: NOMENKLATUR_PLT }),
    R('id_satker', 'Satuan Kerja', 'satker', { required: true, dependsOn: 'id_unit', showWhen: NOMENKLATUR_PLT }),
    R('id_jabatan', 'Jabatan', 'jabatan', {
      required: true,
      wide: true,
      showWhen: NOMENKLATUR_PLT,
      optionsFilter: { param: 'id_sub_group_jabatan', field: 'id_sub_group_jabatan' },
    }),
    R('id_rumpun_jabatan', 'Rumpun Jabatan', 'rumpun-jabatan', { required: true, showWhen: NOMENKLATUR_PLT }),
    T('jabatan_lain', 'Jabatan Lain', { max: 255, showWhen: NOMENKLATUR_PLT, hint: 'Contoh: Pembantu Rektor.' }),
    T('no_induk_jft', 'No. Induk JFT', { required: true, max: 50, showWhen: JFT }),
    S('status_jft', 'Status JFT', [
      { value: '1', label: 'Aktif' },
      { value: '2', label: 'Pembebasan Sementara' },
    ], { required: true, showWhen: JFT }),
    // Khusus pegawai PPPK (server membuangnya untuk pegawai lain).
    R('id_gol_pppk', 'Golongan PPPK', 'gol-pppk', { showWhen: NOMENKLATUR_PLT, hint: 'Wajib untuk pegawai PPPK.' }),
    N('pmk', 'Tahun Perjanjian Masa Kerja', { showWhen: NOMENKLATUR_PLT, hint: 'Khusus PPPK.' }),
    D('mhpk_mulai', 'Mulai Masa Perjanjian Kerja', { showWhen: NOMENKLATUR_PLT }),
    D('mhpk_akhir', 'Akhir Masa Perjanjian Kerja', { showWhen: NOMENKLATUR_PLT }),
    // Instansi lain — teks bebas.
    T('nama_instansi', 'Kementerian / Lembaga', { required: true, max: 255, wide: true, showWhen: INSTANSI_LAIN }),
    T('unit', 'Unit Kerja', { required: true, max: 255, showWhen: INSTANSI_LAIN }),
    T('satker', 'Satuan Kerja', { required: true, max: 255, showWhen: INSTANSI_LAIN }),
    T('jabatan', 'Nama Jabatan', { required: true, max: 255, wide: true, showWhen: INSTANSI_LAIN }),
    A('keterangan', 'Keterangan', { max: 65535 }),
  ],
  // Jabatan::lampiran(): SK wajib; SK pembebasan sementara JFT wajib bila group JFT + status 2; perjanjian kerja opsional.
  lampiran: [
    { id_riwayat: 9, label: 'SK Jabatan', wajib: true, batas_mb: 5, ekstensi: EKSTENSI },
    { id_riwayat: 36, label: 'SK Pembebasan Sementara JFT', wajib: true, wajibBila: { field: 'status_jft', in: ['2'] }, batas_mb: 5, ekstensi: EKSTENSI },
    { id_riwayat: 41, label: 'Perjanjian Kerja (PPPK)', wajib: false, batas_mb: 5, ekstensi: EKSTENSI },
  ],
  // Jabatan::kolomBerkas(): opsional, 5 MB.
  berkasKolom: [
    { kolom: 'ser_dosen', label: 'Sertifikat Dosen', wajib: false, batas_mb: 5, ekstensi: EKSTENSI },
    { kolom: 'f_spmt', label: 'SPMT', wajib: false, batas_mb: 5, ekstensi: EKSTENSI },
    { kolom: 'f_bapel', label: 'BA Pelantikan', wajib: false, batas_mb: 5, ekstensi: EKSTENSI },
  ],
  // Jabatan::rolePilihStatusSaatUbah() — legacy jabatan/form.php:59-74.
  pilihStatusSaatUbah: [Role.SUPER_ADMIN],
  // RiwayatDefinisi::roleKunciHapusDisetujui() bawaan [3].
  kunciHapusDisetujuiRole: [Role.ADMIN_SATKER],
})
