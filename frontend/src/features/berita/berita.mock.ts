/**
 * DATA CONTOH (MOCK) Portal Berita — endpoint hr/news belum ada. Semua judul, penulis, dan isi FIKTIF. Deterministik.
 * Hanya dipakai berita.service.ts.
 */
import { BERITA_KATEGORI, type BeritaItem } from './types'

const JUDUL = [
  'Pembukaan Pendaftaran Beasiswa Pascasarjana Dalam Negeri',
  'Sosialisasi Pemutakhiran Data Kepegawaian Semester II',
  'Diklat Kepemimpinan Tingkat III Angkatan Baru',
  'Siaran Pers: Capaian Kinerja Triwulan III',
  'Kebijakan Baru Jam Kerja Fleksibel bagi Pegawai',
  'Seminar Nasional Transformasi Digital Birokrasi',
  'Pemberitahuan Jadwal Verifikasi Berkas Kenaikan Pangkat',
  'Workshop Penyusunan Sasaran Kinerja Pegawai',
  'Pengumuman Seleksi Terbuka Jabatan Pimpinan Tinggi',
  'Pelatihan Pengelolaan Arsip Digital',
  'Kebijakan Pengajuan Cuti Online Melalui SIMPEG',
  'Beasiswa Tugas Belajar Luar Negeri Tahun Akademik Berikutnya',
]
const PENULIS = ['Wahyu Nurcahyo, ST, MM', 'Dian Permata, S.Sos', 'Bagas Pratama, S.Kom', 'Kurnia Dewi, M.Si']
const RINGKAS = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim…'

export const BERITA_ITEMS: BeritaItem[] = Array.from({ length: 28 }, (_, i) => {
  const day = 28 - Math.floor(i / 2)
  const hour = 8 + ((i * 3) % 12)
  return {
    id: 100 - i,
    judul: JUDUL[i % JUDUL.length],
    ringkasan: RINGKAS,
    penulis: PENULIS[i % PENULIS.length],
    kategori: BERITA_KATEGORI[i % BERITA_KATEGORI.length],
    tanggal: `2026-09-${String(Math.max(1, day)).padStart(2, '0')}T${String(hour).padStart(2, '0')}:${i % 2 ? '44' : '14'}:00`,
    foto_index: i % 3,
  }
})
