/**
 * Konfigurasi 15 tab riwayat Detail Pegawai (§4.1.6, B-07…B-18). Satu mesin generik (RiwayatListSection /
 * RiwayatRecordSection / RiwayatFieldForm) dirender dari konfigurasi ini, sehingga semua tab seragam.
 *
 * ASUMSI: dokumen redesign hanya menggambarkan tab "Data Umum". Kolom dan isian di bawah adalah TEBAKAN dari nama
 * riwayat legacy + Tech Spec 03-Kepegawaian.md dan HARUS dicocokkan dengan kontrak API saat task B-07…B-18 dikerjakan.
 * Pola tabel + dialog mengikuti halaman lain (aksi baris lewat menu ⋮, AGENTS.md §1).
 */

export type FieldType = 'text' | 'date' | 'number' | 'select' | 'textarea'

export interface FieldDef {
  name: string
  label: string
  type: FieldType
  required?: boolean
  options?: string[]
  /** Panjang maksimum teks (bawaan 255; textarea 1000). */
  max?: number
  placeholder?: string
  /** Lebar penuh (2 kolom) pada form. */
  wide?: boolean
}

export interface ColumnDef {
  key: string
  label: string
  /** `date` = "1 Januari 2026"; bawaan teks apa adanya. */
  format?: 'date'
}

export interface RiwayatConfig {
  key: string
  title: string
  subtitle: string
  /** Task Tech Spec yang akan menyambungkan tab ini ke backend. */
  task: string
  /** `record` = satu rekaman (form langsung, mis. Data Alamat); `list` = daftar riwayat + tambah/ubah/hapus. */
  kind: 'list' | 'record'
  /** Kata benda untuk tombol "Tambah …" dan judul dialog. */
  singular: string
  columns: ColumnDef[]
  fields: FieldDef[]
}

const opts = (...v: string[]): string[] => v

const T = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'text', ...extra })
const D = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'date', ...extra })
const N = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'number', ...extra })
const S = (name: string, label: string, options: string[], extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'select', options, ...extra })
const A = (name: string, label: string, extra: Partial<FieldDef> = {}): FieldDef => ({ name, label, type: 'textarea', wide: true, ...extra })

export const RIWAYAT_CONFIGS: RiwayatConfig[] = [
  {
    key: 'data-alamat', title: 'Data Alamat', subtitle: 'Alamat sesuai KTP dan domisili. Kolom bertanda (*) wajib diisi.', task: 'B-16', kind: 'record', singular: 'alamat',
    columns: [],
    fields: [
      A('alamat_ktp', 'Alamat (sesuai KTP)', { required: true }),
      T('rt', 'RT', { max: 3 }), T('rw', 'RW', { max: 3 }),
      T('kelurahan', 'Kelurahan/Desa', { required: true }), T('kecamatan', 'Kecamatan', { required: true }),
      T('kota', 'Kab./Kota', { required: true }), T('provinsi', 'Provinsi', { required: true }),
      T('kode_pos', 'Kode Pos', { max: 5 }), T('no_telp_rumah', 'No. Telp. Rumah', { max: 20 }),
      A('alamat_domisili', 'Alamat domisili (bila berbeda)'),
    ],
  },
  {
    key: 'data-keluarga', title: 'Data Keluarga', subtitle: 'Pasangan, anak, dan orang tua.', task: 'B-16', kind: 'list', singular: 'anggota keluarga',
    columns: [{ key: 'nama', label: 'Nama' }, { key: 'hubungan', label: 'Hubungan' }, { key: 'tanggal_lahir', label: 'Tanggal Lahir', format: 'date' }, { key: 'pekerjaan', label: 'Pekerjaan' }, { key: 'tanggungan', label: 'Tanggungan' }],
    fields: [
      T('nama', 'Nama', { required: true }), S('hubungan', 'Hubungan', opts('Suami', 'Istri', 'Anak', 'Ayah', 'Ibu'), { required: true }),
      T('tempat_lahir', 'Tempat Lahir'), D('tanggal_lahir', 'Tanggal Lahir', { required: true }),
      T('pekerjaan', 'Pekerjaan'), S('tanggungan', 'Tanggungan', opts('Ya', 'Tidak'), { required: true }),
    ],
  },
  {
    key: 'riwayat-pendidikan', title: 'Riwayat Pendidikan', subtitle: 'Pendidikan formal yang pernah ditempuh.', task: 'B-10', kind: 'list', singular: 'pendidikan',
    columns: [{ key: 'jenjang', label: 'Jenjang' }, { key: 'institusi', label: 'Institusi' }, { key: 'jurusan', label: 'Jurusan' }, { key: 'tahun_lulus', label: 'Tahun Lulus' }, { key: 'no_ijazah', label: 'No. Ijazah' }],
    fields: [
      S('jenjang', 'Jenjang', opts('SMA/SMK', 'D3', 'D4', 'S1', 'S2', 'S3'), { required: true }), T('institusi', 'Institusi', { required: true }),
      T('jurusan', 'Jurusan/Program Studi'), T('gelar', 'Gelar', { max: 30 }),
      N('tahun_masuk', 'Tahun Masuk'), N('tahun_lulus', 'Tahun Lulus', { required: true }),
      T('no_ijazah', 'No. Ijazah'),
    ],
  },
  {
    key: 'riwayat-pelatihan', title: 'Riwayat Pelatihan', subtitle: 'Diklat struktural, teknis, fungsional, dan prajabatan.', task: 'B-11', kind: 'list', singular: 'pelatihan',
    columns: [{ key: 'nama_diklat', label: 'Nama Diklat' }, { key: 'jenis_diklat', label: 'Jenis' }, { key: 'penyelenggara', label: 'Penyelenggara' }, { key: 'tanggal_mulai', label: 'Mulai', format: 'date' }, { key: 'jumlah_jam', label: 'Jam' }],
    fields: [
      T('nama_diklat', 'Nama Diklat', { required: true, wide: true }), S('jenis_diklat', 'Jenis Diklat', opts('Struktural', 'Teknis', 'Fungsional', 'Prajabatan'), { required: true }),
      T('penyelenggara', 'Penyelenggara'), D('tanggal_mulai', 'Tanggal Mulai', { required: true }), D('tanggal_selesai', 'Tanggal Selesai', { required: true }),
      N('jumlah_jam', 'Jumlah Jam'), T('no_sertifikat', 'No. Sertifikat'),
    ],
  },
  {
    key: 'kursus-seminar', title: 'Kursus/Seminar', subtitle: 'Kursus, seminar, dan workshop yang diikuti.', task: 'B-11', kind: 'list', singular: 'kursus/seminar',
    columns: [{ key: 'nama', label: 'Nama Kegiatan' }, { key: 'jenis', label: 'Jenis' }, { key: 'penyelenggara', label: 'Penyelenggara' }, { key: 'tanggal', label: 'Tanggal', format: 'date' }, { key: 'tempat', label: 'Tempat' }],
    fields: [
      T('nama', 'Nama Kegiatan', { required: true, wide: true }), S('jenis', 'Jenis', opts('Kursus', 'Seminar', 'Workshop'), { required: true }),
      T('penyelenggara', 'Penyelenggara'), D('tanggal', 'Tanggal', { required: true }), T('tempat', 'Tempat'), T('no_sertifikat', 'No. Sertifikat'),
    ],
  },
  {
    key: 'riwayat-organisasi', title: 'Riwayat Organisasi', subtitle: 'Keanggotaan dan jabatan dalam organisasi.', task: 'B-17', kind: 'list', singular: 'organisasi',
    columns: [{ key: 'nama_organisasi', label: 'Organisasi' }, { key: 'jabatan', label: 'Jabatan' }, { key: 'tingkat', label: 'Tingkat' }, { key: 'tahun_mulai', label: 'Mulai' }, { key: 'tahun_selesai', label: 'Selesai' }],
    fields: [
      T('nama_organisasi', 'Nama Organisasi', { required: true, wide: true }), T('jabatan', 'Jabatan', { required: true }), S('tingkat', 'Tingkat', opts('Lokal', 'Nasional', 'Internasional')),
      N('tahun_mulai', 'Tahun Mulai', { required: true }), N('tahun_selesai', 'Tahun Selesai'), T('pimpinan', 'Pimpinan Organisasi'),
    ],
  },
  {
    key: 'laporan-kerja-harian', title: 'Laporan Kerja Harian', subtitle: 'Catatan kegiatan harian (LKH).', task: 'B-12', kind: 'list', singular: 'laporan kerja',
    columns: [{ key: 'tanggal', label: 'Tanggal', format: 'date' }, { key: 'uraian', label: 'Uraian Kegiatan' }, { key: 'output', label: 'Output' }, { key: 'volume', label: 'Volume' }],
    fields: [
      D('tanggal', 'Tanggal', { required: true }), N('volume', 'Volume'),
      A('uraian', 'Uraian Kegiatan', { required: true }), T('output', 'Output'), T('satuan', 'Satuan'),
    ],
  },
  {
    key: 'skp-tahunan', title: 'SKP Tahunan', subtitle: 'Sasaran Kinerja Pegawai per tahun.', task: 'B-12', kind: 'list', singular: 'SKP tahunan',
    columns: [{ key: 'tahun', label: 'Tahun' }, { key: 'nilai_skp', label: 'Nilai SKP' }, { key: 'predikat', label: 'Predikat' }, { key: 'pejabat_penilai', label: 'Pejabat Penilai' }],
    fields: [
      N('tahun', 'Tahun', { required: true }), N('nilai_skp', 'Nilai SKP', { required: true }),
      S('predikat', 'Predikat', opts('Sangat Baik', 'Baik', 'Cukup', 'Kurang', 'Buruk'), { required: true }), T('pejabat_penilai', 'Pejabat Penilai'),
    ],
  },
  {
    key: 'skp-periodik', title: 'SKP Periodik', subtitle: 'Penilaian kinerja per periode.', task: 'B-12', kind: 'list', singular: 'SKP periodik',
    columns: [{ key: 'periode', label: 'Periode' }, { key: 'nilai_sasaran', label: 'Nilai Sasaran Kerja' }, { key: 'nilai_perilaku', label: 'Nilai Perilaku Kerja' }, { key: 'nilai_akhir', label: 'Nilai Akhir' }, { key: 'predikat', label: 'Predikat' }],
    fields: [
      T('periode', 'Periode', { required: true, placeholder: 'Contoh: Semester I 2026' }), S('predikat', 'Predikat', opts('Sangat Baik', 'Baik', 'Cukup', 'Kurang', 'Buruk'), { required: true }),
      N('nilai_sasaran', 'Nilai Sasaran Kerja Periodik'), N('nilai_perilaku', 'Nilai Perilaku Kerja Periodik'), N('nilai_akhir', 'Nilai Akhir', { required: true }),
    ],
  },
  {
    key: 'riwayat-jabatan', title: 'Riwayat Jabatan', subtitle: 'Mutasi jabatan struktural, fungsional, dan pelaksana.', task: 'B-07', kind: 'list', singular: 'jabatan',
    columns: [{ key: 'nama_jabatan', label: 'Jabatan' }, { key: 'jenis_jabatan', label: 'Jenis' }, { key: 'unit_kerja', label: 'Unit Kerja' }, { key: 'no_sk', label: 'No. SK' }, { key: 'tmt_jabatan', label: 'TMT', format: 'date' }],
    fields: [
      S('jenis_jabatan', 'Jenis Jabatan', opts('Struktural', 'Fungsional', 'Pelaksana'), { required: true }), T('nama_jabatan', 'Nama Jabatan', { required: true }),
      T('unit_kerja', 'Unit Kerja', { required: true }), T('eselon', 'Eselon', { max: 10 }),
      T('no_sk', 'No. SK', { required: true }), D('tanggal_sk', 'Tanggal SK', { required: true }), D('tmt_jabatan', 'TMT Jabatan', { required: true }),
    ],
  },
  {
    key: 'riwayat-pangkat', title: 'Riwayat Pangkat', subtitle: 'Kenaikan pangkat (KP) golongan ruang.', task: 'B-08', kind: 'list', singular: 'pangkat',
    columns: [{ key: 'golongan', label: 'Golongan' }, { key: 'pangkat', label: 'Pangkat' }, { key: 'jenis_kp', label: 'Jenis KP' }, { key: 'no_sk', label: 'No. SK' }, { key: 'tmt_golongan', label: 'TMT', format: 'date' }],
    fields: [
      T('golongan', 'Golongan/Ruang', { required: true, max: 10 }), T('pangkat', 'Pangkat'),
      S('jenis_kp', 'Jenis KP', opts('Reguler', 'Pilihan', 'Penyesuaian Ijazah')),
      T('no_sk', 'No. SK', { required: true }), D('tanggal_sk', 'Tanggal SK', { required: true }), D('tmt_golongan', 'TMT Golongan', { required: true }),
    ],
  },
  {
    key: 'riwayat-kgb', title: 'Riwayat KGB', subtitle: 'Kenaikan gaji berkala.', task: 'B-09', kind: 'list', singular: 'KGB',
    columns: [{ key: 'gaji_pokok', label: 'Gaji Pokok' }, { key: 'masa_kerja_tahun', label: 'Masa Kerja (thn)' }, { key: 'no_sk', label: 'No. SK' }, { key: 'tmt_kgb', label: 'TMT', format: 'date' }],
    fields: [
      N('gaji_pokok', 'Gaji Pokok', { required: true }), T('no_sk', 'No. SK', { required: true }),
      N('masa_kerja_tahun', 'Masa Kerja (tahun)'), N('masa_kerja_bulan', 'Masa Kerja (bulan)'),
      D('tanggal_sk', 'Tanggal SK', { required: true }), D('tmt_kgb', 'TMT KGB', { required: true }),
    ],
  },
  {
    key: 'riwayat-hukdis', title: 'Riwayat Hukdis', subtitle: 'Hukuman disiplin.', task: 'B-14', kind: 'list', singular: 'hukuman disiplin',
    columns: [{ key: 'jenis_hukdis', label: 'Jenis Hukuman' }, { key: 'no_sk', label: 'No. SK' }, { key: 'tmt_mulai', label: 'Mulai', format: 'date' }, { key: 'masa_sanksi', label: 'Masa Sanksi (bln)' }, { key: 'akhir_hukdis', label: 'Berakhir', format: 'date' }],
    fields: [
      T('jenis_hukdis', 'Jenis Hukuman', { required: true }), T('no_sk', 'No. SK', { required: true }),
      D('tanggal_sk', 'Tanggal SK', { required: true }), D('tmt_mulai', 'TMT Mulai', { required: true }),
      N('masa_sanksi', 'Masa Sanksi (bulan)'), D('akhir_hukdis', 'Tanggal Berakhir'), A('keterangan', 'Keterangan'),
    ],
  },
  {
    key: 'angka-kredit', title: 'Angka Kredit', subtitle: 'Penetapan angka kredit jabatan fungsional.', task: 'B-15', kind: 'list', singular: 'angka kredit',
    columns: [{ key: 'tahun', label: 'Tahun' }, { key: 'ak_lama', label: 'AK Lama' }, { key: 'ak_baru', label: 'AK Baru' }, { key: 'ak_total', label: 'AK Total' }, { key: 'no_pak', label: 'No. PAK' }],
    fields: [
      N('tahun', 'Tahun', { required: true }), T('no_pak', 'No. PAK'),
      N('ak_lama', 'AK Lama'), N('ak_baru', 'AK Baru', { required: true }), N('ak_total', 'AK Total'),
    ],
  },
  {
    key: 'tanda-jasa', title: 'Tanda Jasa', subtitle: 'Penghargaan satyalancana dan tanda jasa lainnya.', task: 'B-17', kind: 'list', singular: 'tanda jasa',
    columns: [{ key: 'nama_tanda_jasa', label: 'Tanda Jasa' }, { key: 'no_keppres', label: 'No. Keppres' }, { key: 'tanggal', label: 'Tanggal', format: 'date' }, { key: 'tahun', label: 'Tahun' }],
    fields: [
      T('nama_tanda_jasa', 'Nama Tanda Jasa', { required: true, wide: true }), T('no_keppres', 'No. Keppres', { required: true }),
      D('tanggal', 'Tanggal', { required: true }), N('tahun', 'Tahun'),
    ],
  },
]

export const RIWAYAT_BY_KEY: Record<string, RiwayatConfig> = Object.fromEntries(RIWAYAT_CONFIGS.map((c) => [c.key, c]))

/** Status verifikasi draft perubahan (B-04): badge pada tiap baris riwayat. */
export const VERIFIKASI_STATUS = ['Disetujui', 'Menunggu Verifikasi', 'Ditolak'] as const
export type VerifikasiStatus = (typeof VERIFIKASI_STATUS)[number]
