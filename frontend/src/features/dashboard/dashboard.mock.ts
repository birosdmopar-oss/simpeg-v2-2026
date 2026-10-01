/**
 * DATA CONTOH (MOCK) dashboard — endpoint user/dashboard belum ada. Angka ilustrasi dari mockup (Gambar 12/25/26),
 * bukan data pegawai nyata. Hanya dipakai komponen dashboard.
 */
export const ADMIN_STATS = [
  { key: 'pns', label: 'Total PNS', value: 1826, tone: 'violet', trend: [12, 14, 13, 17, 16, 19, 21] },
  { key: 'pppk', label: 'Total PPPK', value: 310, tone: 'success', trend: [8, 7, 9, 9, 11, 10, 12] },
  { key: 'ptt', label: 'Total PTT', value: 64, tone: 'warning', trend: [9, 8, 8, 7, 7, 6, 6] },
  { key: 'pensiun', label: 'Pensiun 1 Tahun', value: 87, tone: 'danger', trend: [3, 4, 4, 6, 5, 7, 8] },
] as const

export const KOMPOSISI = [
  { label: 'PNS', value: 1826, color: '#224A8A' },
  { label: 'PPPK', value: 310, color: '#217AFF' },
  { label: 'PTT', value: 64, color: '#FFC043' },
]

export const ULANG_TAHUN = [
  { nama: 'Contoh Pegawai A', unit: 'Sekretariat Kementerian', tanggal: 'Hari ini', seed: 'ultah-a' },
  { nama: 'Contoh Pegawai B', unit: 'Deputi Bidang Pemasaran', tanggal: 'Besok', seed: 'ultah-b' },
  { nama: 'Contoh Pegawai C', unit: 'Unit Pelaksana Teknis', tanggal: '3 hari lagi', seed: 'ultah-c' },
]

export const USER_STATS = [
  { key: 'kgb', label: 'KGB Berikutnya', value: '01 Mar 2027', tone: 'primary', progress: 72 },
  { key: 'pangkat', label: 'Kenaikan Pangkat', value: '01 Okt 2027', tone: 'success', progress: 45 },
  { key: 'cuti', label: 'Sisa Cuti', value: '9 hari', tone: 'warning', progress: 60 },
  { key: 'ak', label: 'Angka Kredit', value: '84%', tone: 'info', progress: 84 },
] as const

export const KEHADIRAN_TIM = [
  { id: 1, nama: 'Contoh Anggota 1', jabatan: 'Analis Kepegawaian', status: 'Hadir', masuk: '07:52', keluar: '-' },
  { id: 2, nama: 'Contoh Anggota 2', jabatan: 'Pranata Komputer', status: 'Hadir', masuk: '08:03', keluar: '-' },
  { id: 3, nama: 'Contoh Anggota 3', jabatan: 'Pengelola Data', status: 'Cuti', masuk: '-', keluar: '-' },
  { id: 4, nama: 'Contoh Anggota 4', jabatan: 'Arsiparis', status: 'Dinas Luar', masuk: '-', keluar: '-' },
  { id: 5, nama: 'Contoh Anggota 5', jabatan: 'Analis SDM', status: 'Belum Hadir', masuk: '-', keluar: '-' },
] as const

export const PENGUMUMAN = {
  judul: 'Pemutakhiran Data Kepegawaian',
  isi: 'Mohon lengkapi dan perbarui data kepegawaian Anda paling lambat akhir bulan ini. Data contoh pengumuman.',
}
