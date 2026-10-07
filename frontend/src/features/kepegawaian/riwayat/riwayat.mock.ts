/**
 * DATA CONTOH (MOCK) untuk 15 tab riwayat — backend B-07…B-18 belum ada. Semua isi FIKTIF. Deterministik.
 * Hanya dipakai riwayat.service.ts. Perubahan (tambah/ubah/hapus) hanya hidup di memori sesi halaman.
 */
import { VERIFIKASI_STATUS, type VerifikasiStatus } from './riwayat.config'

export type RiwayatRow = { id: number } & { status_verifikasi: VerifikasiStatus } & Record<string, string | number>

type Sample = Record<string, string>

const r = (v: Sample): Sample => v

/** Contoh isi per tab (2–4 baris; tab "record" = tepat 1 baris). */
export const SAMPLES: Record<string, Sample[]> = {
  'data-alamat': [
    r({ alamat_ktp: 'Jl. Merdeka No. 17', rt: '003', rw: '005', kelurahan: 'Cikini', kecamatan: 'Menteng', kota: 'Jakarta Pusat', provinsi: 'DKI Jakarta', kode_pos: '10330', no_telp_rumah: '0215551234', alamat_domisili: '' }),
  ],
  'data-keluarga': [
    r({ nama: 'Ratna Sari', hubungan: 'Istri', tempat_lahir: 'Bandung', tanggal_lahir: '1986-04-12', pekerjaan: 'Guru', tanggungan: 'Ya' }),
    r({ nama: 'Aldi Pratama', hubungan: 'Anak', tempat_lahir: 'Jakarta', tanggal_lahir: '2014-08-02', pekerjaan: 'Pelajar', tanggungan: 'Ya' }),
    r({ nama: 'Maya Pratama', hubungan: 'Anak', tempat_lahir: 'Jakarta', tanggal_lahir: '2017-01-21', pekerjaan: 'Pelajar', tanggungan: 'Ya' }),
  ],
  'riwayat-pendidikan': [
    r({ jenjang: 'SMA/SMK', institusi: 'SMA Negeri 1 Contoh', jurusan: 'IPA', gelar: '', tahun_masuk: '1999', tahun_lulus: '2002', no_ijazah: 'DN-01/2002/0142' }),
    r({ jenjang: 'S1', institusi: 'Universitas Contoh Nusantara', jurusan: 'Ilmu Komputer', gelar: 'S.Kom', tahun_masuk: '2002', tahun_lulus: '2006', no_ijazah: 'UCN/S1/2006/0871' }),
    r({ jenjang: 'S2', institusi: 'Institut Teknologi Contoh', jurusan: 'Sistem Informasi', gelar: 'M.T.', tahun_masuk: '2012', tahun_lulus: '2014', no_ijazah: 'ITC/S2/2014/0234' }),
  ],
  'riwayat-pelatihan': [
    r({ nama_diklat: 'Diklat Prajabatan Golongan III', jenis_diklat: 'Prajabatan', penyelenggara: 'Pusdiklat Contoh', tanggal_mulai: '2008-03-03', tanggal_selesai: '2008-03-28', jumlah_jam: '120', no_sertifikat: 'PRJ/2008/0113' }),
    r({ nama_diklat: 'Diklat Kepemimpinan Tingkat IV', jenis_diklat: 'Struktural', penyelenggara: 'Badan Diklat Contoh', tanggal_mulai: '2019-05-06', tanggal_selesai: '2019-08-16', jumlah_jam: '324', no_sertifikat: 'PKP4/2019/0876' }),
    r({ nama_diklat: 'Pelatihan Pengelolaan Data Kepegawaian', jenis_diklat: 'Teknis', penyelenggara: 'Biro SDM', tanggal_mulai: '2022-09-12', tanggal_selesai: '2022-09-16', jumlah_jam: '36', no_sertifikat: 'TKN/2022/0042' }),
  ],
  'kursus-seminar': [
    r({ nama: 'Seminar Transformasi Digital Birokrasi', jenis: 'Seminar', penyelenggara: 'Kementerian Contoh', tanggal: '2023-04-18', tempat: 'Jakarta', no_sertifikat: 'SEM/2023/318' }),
    r({ nama: 'Kursus Bahasa Inggris Profesional', jenis: 'Kursus', penyelenggara: 'Lembaga Bahasa Contoh', tanggal: '2021-10-05', tempat: 'Bandung', no_sertifikat: 'KRS/2021/0091' }),
  ],
  'riwayat-organisasi': [
    r({ nama_organisasi: 'Korps Pegawai Contoh', jabatan: 'Sekretaris', tingkat: 'Nasional', tahun_mulai: '2018', tahun_selesai: '2021', pimpinan: 'Drs. Contoh Wijaya' }),
    r({ nama_organisasi: 'Ikatan Alumni Universitas Contoh', jabatan: 'Anggota', tingkat: 'Lokal', tahun_mulai: '2010', tahun_selesai: '', pimpinan: '' }),
  ],
  'laporan-kerja-harian': [
    r({ tanggal: '2026-09-28', uraian: 'Menyusun rekapitulasi data pegawai per satuan kerja', output: 'Dokumen rekapitulasi', volume: '1', satuan: 'dokumen' }),
    r({ tanggal: '2026-09-25', uraian: 'Verifikasi berkas usulan kenaikan pangkat', output: 'Berkas terverifikasi', volume: '12', satuan: 'berkas' }),
    r({ tanggal: '2026-09-24', uraian: 'Rapat koordinasi pemutakhiran data kepegawaian', output: 'Notulen rapat', volume: '1', satuan: 'notulen' }),
  ],
  'skp-tahunan': [
    r({ tahun: '2025', nilai_skp: '91.5', predikat: 'Sangat Baik', pejabat_penilai: 'Kepala Biro Umum dan SDM' }),
    r({ tahun: '2024', nilai_skp: '88.0', predikat: 'Baik', pejabat_penilai: 'Kepala Biro Umum dan SDM' }),
    r({ tahun: '2023', nilai_skp: '86.4', predikat: 'Baik', pejabat_penilai: 'Kepala Bagian Kepegawaian' }),
  ],
  'skp-periodik': [
    r({ periode: 'Semester I 2026', nilai_sasaran: '90', nilai_perilaku: '92', nilai_akhir: '91', predikat: 'Sangat Baik' }),
    r({ periode: 'Semester II 2025', nilai_sasaran: '87', nilai_perilaku: '90', nilai_akhir: '88.5', predikat: 'Baik' }),
  ],
  'riwayat-jabatan': [
    r({ jenis_jabatan: 'Pelaksana', nama_jabatan: 'Pengelola Data Kepegawaian', unit_kerja: 'Biro Umum dan SDM', eselon: '', no_sk: 'KEP-112/2010', tanggal_sk: '2010-02-10', tmt_jabatan: '2010-03-01' }),
    r({ jenis_jabatan: 'Fungsional', nama_jabatan: 'Analis Kepegawaian Ahli Muda', unit_kerja: 'Biro Umum dan SDM', eselon: '', no_sk: 'KEP-045/2018', tanggal_sk: '2018-01-15', tmt_jabatan: '2018-02-01' }),
    r({ jenis_jabatan: 'Struktural', nama_jabatan: 'Kepala Subbagian Mutasi', unit_kerja: 'Biro Umum dan SDM', eselon: 'IV.a', no_sk: 'KEP-207/2022', tanggal_sk: '2022-06-20', tmt_jabatan: '2022-07-01' }),
  ],
  'riwayat-pangkat': [
    r({ golongan: 'III/a', pangkat: 'Penata Muda', jenis_kp: 'Reguler', no_sk: 'KP-0231/2009', tanggal_sk: '2009-03-12', tmt_golongan: '2009-04-01' }),
    r({ golongan: 'III/c', pangkat: 'Penata', jenis_kp: 'Reguler', no_sk: 'KP-0788/2015', tanggal_sk: '2015-03-10', tmt_golongan: '2015-04-01' }),
    r({ golongan: 'III/d', pangkat: 'Penata Tingkat I', jenis_kp: 'Pilihan', no_sk: 'KP-0412/2020', tanggal_sk: '2020-09-14', tmt_golongan: '2020-10-01' }),
  ],
  'riwayat-kgb': [
    r({ gaji_pokok: '4250000', no_sk: 'KGB-118/2022', masa_kerja_tahun: '14', masa_kerja_bulan: '0', tanggal_sk: '2022-02-11', tmt_kgb: '2022-03-01' }),
    r({ gaji_pokok: '4480000', no_sk: 'KGB-097/2024', masa_kerja_tahun: '16', masa_kerja_bulan: '0', tanggal_sk: '2024-02-09', tmt_kgb: '2024-03-01' }),
  ],
  'riwayat-hukdis': [
    r({ jenis_hukdis: 'Teguran Tertulis', no_sk: 'HD-014/2017', tanggal_sk: '2017-05-22', tmt_mulai: '2017-06-01', masa_sanksi: '6', akhir_hukdis: '2017-12-01', keterangan: 'Contoh data — tidak mewakili pegawai nyata' }),
  ],
  'angka-kredit': [
    r({ tahun: '2025', ak_lama: '88.4', ak_baru: '12.6', ak_total: '101.0', no_pak: 'PAK-054/2025' }),
    r({ tahun: '2023', ak_lama: '70.1', ak_baru: '18.3', ak_total: '88.4', no_pak: 'PAK-021/2023' }),
  ],
  'tanda-jasa': [
    r({ nama_tanda_jasa: 'Satyalancana Karya Satya X Tahun', no_keppres: '032/TK/Tahun 2019', tanggal: '2019-08-17', tahun: '2019' }),
    r({ nama_tanda_jasa: 'Satyalancana Karya Satya XX Tahun', no_keppres: '017/TK/Tahun 2024', tanggal: '2024-08-17', tahun: '2024' }),
  ],
}

/** Isi awal sebuah (nip, tab): baris contoh dengan id & status verifikasi bergilir (Disetujui dulu). */
export function seedRows(key: string): RiwayatRow[] {
  return (SAMPLES[key] ?? []).map((row, i) => ({
    ...row,
    id: i + 1,
    status_verifikasi: VERIFIKASI_STATUS[i === 0 ? 0 : (i + 1) % 3 === 0 ? 2 : 1] as VerifikasiStatus,
  }))
}
