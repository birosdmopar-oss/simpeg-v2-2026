/**
 * DATA CONTOH (MOCK) laporan statistik — endpoint hr/chart/* belum ada. Angka Unit Kerja disalin dari mockup
 * (Gambar 15; total PNS 1.826 + PPPK 310 = 2.136) — itu data ilustrasi, bukan data pegawai nyata. Dua laporan lain
 * adalah angka fiktif dengan total yang sama supaya konsisten. Hanya dipakai laporan.service.ts.
 */
import type { LaporanData, LaporanSeries, LaporanTipe } from './types'

const NAVY: LaporanSeries = { label: 'PNS', color: '#224A8A', tone: 'navy' }
const SKY: LaporanSeries = { label: 'PPPK', color: '#A9C9FF', tone: 'sky' }

const UNITS = [
  'Sekretariat Kementerian',
  'Deputi Bidang Sumber Daya dan Kelembagaan',
  'Deputi Bidang Pengembangan Destinasi dan Infrastruktur',
  'Deputi Bidang Industri dan Investasi',
  'Deputi Bidang Pemasaran',
  'Deputi Bidang Pengembangan Penyelenggara Kegiatan (Events)',
  'Unit Pelaksana Teknis',
  'Lain-Lain',
]

export const LAPORAN: Record<LaporanTipe, LaporanData> = {
  'unit-kerja': {
    tipe: 'unit-kerja',
    title: 'Laporan Unit Kerja',
    kolomLabel: 'Unit Kerja Eselon 1',
    series: [NAVY, SKY],
    baris: UNITS.map((label, i) => ({ label, values: [[316, 160, 147, 116, 135, 116, 689, 147][i], [46, 36, 18, 24, 29, 36, 121, 0][i]] })),
    donatBaris: { title: 'Unit Kerja', subtitle: 'Jumlah pegawai per unit kerja' },
    donatSeries: { title: 'Jenis Pegawai', subtitle: 'Jumlah pegawai per jenis pegawai' },
  },
  'jenis-kelamin': {
    tipe: 'jenis-kelamin',
    title: 'Laporan Jenis Kelamin',
    kolomLabel: 'Unit Kerja Eselon 1',
    series: [
      { label: 'Laki-Laki', color: '#224A8A', tone: 'navy' },
      { label: 'Perempuan', color: '#A9C9FF', tone: 'sky' },
    ],
    baris: UNITS.map((label, i) => ({ label, values: [[201, 105, 96, 78, 74, 88, 502, 84][i], [161, 91, 69, 62, 90, 64, 308, 63][i]] })),
    donatBaris: { title: 'Unit Kerja', subtitle: 'Jumlah pegawai per unit kerja' },
    donatSeries: { title: 'Jenis Kelamin', subtitle: 'Jumlah pegawai per jenis kelamin' },
  },
  struktural: {
    tipe: 'struktural',
    title: 'Laporan Struktural',
    kolomLabel: 'Jenjang Jabatan',
    series: [NAVY, SKY],
    baris: [
      { label: 'Eselon I', values: [9, 0] },
      { label: 'Eselon II', values: [46, 1] },
      { label: 'Eselon III', values: [172, 6] },
      { label: 'Eselon IV', values: [418, 14] },
      { label: 'Jabatan Fungsional', values: [702, 39] },
      { label: 'Pelaksana', values: [479, 250] },
    ],
    donatBaris: { title: 'Jenjang Jabatan', subtitle: 'Jumlah pegawai per jenjang jabatan' },
    donatSeries: { title: 'Jenis Pegawai', subtitle: 'Jumlah pegawai per jenis pegawai' },
  },
}

/** Warna donat per baris — gradasi biru → hijau muda (Gambar 15/16). */
export const DONAT_PALET = ['#1A5C99', '#3B8AC4', '#2A9DC5', '#3BB5B5', '#4DB39A', '#7FCB7E', '#A9DB7B', '#D5E98A']
