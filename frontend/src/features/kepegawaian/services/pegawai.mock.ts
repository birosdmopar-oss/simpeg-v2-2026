/**
 * DATA CONTOH (MOCK) — backend Modul B (B-03, B-19, B-20) belum ada, jadi UI Fase 3 berjalan di atas data
 * deterministik ini. Semua nama, NIP, email, dan nomor adalah FIKTIF (email memakai domain terpesan `.test`);
 * jangan gunakan data asli di sini.
 *
 * Deterministik (tanpa Math.random) supaya test dan tangkapan layar stabil.
 * Dipakai HANYA oleh pegawai.service.ts — jangan diimpor langsung dari komponen.
 */
import type {
  ArsipItem,
  DataUmum,
  JenisPegawai,
  OrgNode,
  PegawaiDetail,
  PegawaiFacets,
  PegawaiRow,
  StatusPegawai,
} from '../types'

const MALE = ['Yanto', 'Agung', 'Budi', 'Hendra', 'Andi', 'Fajar', 'Rizky', 'Doni', 'Bayu', 'Eko']
const FEMALE = ['Sri', 'Dewi', 'Rina', 'Siti', 'Lestari', 'Maya', 'Putri', 'Ayu', 'Nur', 'Wulan']
const FAMILY = ['Subagyo', 'Wahyuni', 'Putro', 'Anggraini', 'Santoso', 'Kusuma', 'Pratama', 'Hidayat', 'Nugroho', 'Saputra', 'Maharani', 'Wibowo', 'Rahayu', 'Setiawan', 'Utami']
const AGAMA = ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha']
const GOLONGAN_PNS = ['III/a', 'III/b', 'III/c', 'III/d', 'IV/a', 'IV/b', 'IV/c', 'IV/d', 'IV/e']

const SATKER: Array<{ nama: string; units: string[] }> = [
  { nama: 'Sekretariat Kementerian', units: ['Biro Perencanaan dan Keuangan', 'Biro Umum dan SDM', 'Biro Hukum'] },
  { nama: 'Deputi Bidang Pemasaran', units: ['Asisten Deputi Pemasaran I', 'Asisten Deputi Pemasaran II'] },
  { nama: 'Deputi Bidang Industri dan Investasi', units: ['Asisten Deputi Industri', 'Asisten Deputi Investasi'] },
  { nama: 'Inspektorat', units: ['Inspektorat Wilayah I'] },
]

const JABATAN: Array<{ nama: string; group: string; sub: string; eselon: string }> = [
  { nama: 'Arsiparis', group: 'Jabatan Fungsional', sub: 'Ahli Pertama', eselon: '-' },
  { nama: 'Pranata Humas', group: 'Jabatan Fungsional', sub: 'Ahli Muda', eselon: '-' },
  { nama: 'Pranata Komputer', group: 'Jabatan Fungsional', sub: 'Ahli Pertama', eselon: '-' },
  { nama: 'Analis Kebijakan', group: 'Jabatan Fungsional', sub: 'Ahli Muda', eselon: '-' },
  { nama: 'Analis Kepegawaian', group: 'Jabatan Fungsional', sub: 'Ahli Pertama', eselon: '-' },
  { nama: 'Pengadministrasi Umum', group: 'Jabatan Pelaksana', sub: 'Pelaksana', eselon: '-' },
  { nama: 'Kepala Subbagian', group: 'Jabatan Struktural', sub: 'Eselon IV', eselon: 'IV.a' },
]

const pick = <T>(list: readonly T[], i: number, step: number): T => list[(i * step + 3) % list.length]
const pad = (n: number, len: number): string => String(n).padStart(len, '0')

function buildRow(i: number): PegawaiRow {
  const female = i % 2 === 1
  const first = female ? pick(FEMALE, i, 3) : pick(MALE, i, 3)
  const family = pick(FAMILY, i, 5)
  const satker = pick(SATKER, i, 3)
  const jabatan = pick(JABATAN, i, 5)

  const birthYear = 1970 + ((i * 3) % 30)
  const birthMonth = 1 + ((i * 5) % 12)
  const birthDay = 1 + ((i * 7) % 28)
  const nip = `${birthYear}${pad(birthMonth, 2)}${pad(birthDay, 2)}${birthYear + 25}${pad(1 + ((i * 2) % 12), 2)}${female ? 2 : 1}${pad(1 + i, 3)}`

  const jenis: JenisPegawai = i % 7 === 6 ? 'PPPK' : i % 11 === 10 ? 'PTT' : 'PNS'
  const status: StatusPegawai = i % 23 === 22 ? 'Pensiun' : i % 19 === 18 ? 'Tugas Belajar' : 'Aktif'
  const golongan = jenis === 'PNS' ? pick(GOLONGAN_PNS, i, 7) : jenis === 'PPPK' ? 'IX' : '-'

  const titles = ['', 'Dr.', 'Drs.', 'Dra.', 'Ir.']
  return {
    nip,
    nip_lama: `${70000000 + i * 137}`,
    nama: `${first} ${family}`,
    gelar_awal: i % 3 === 0 ? (female ? 'Dra.' : pick(titles, i, 1)) : '',
    gelar_akhir: i % 2 === 0 ? pick(['S.Kom', 'S.Sos', 'M.Si', 'S.E.', 'M.M.'], i, 1) : '',
    agama: pick(AGAMA, i, 1),
    jenis_kelamin: female ? 'Perempuan' : 'Laki-Laki',
    alamat: `Jl. Contoh No. ${1 + (i % 90)}, Jakarta`,
    email: `${first}.${family}${i}@contoh.test`.toLowerCase(),
    eselon: jabatan.eselon,
    golongan,
    tmt_golongan: `${pad(1 + (i % 28), 2)} ${i % 2 ? 'April' : 'Oktober'} ${2015 + (i % 10)}`,
    jabatan: jabatan.nama,
    group_jabatan: jabatan.group,
    sub_group_jabatan: jabatan.sub,
    satuan_kerja: satker.nama,
    unit: pick(satker.units, i, 1),
    jenis_pegawai: jenis,
    status_pegawai: status,
    foto_seed: `pegawai-${i}`,
  }
}

export const PEGAWAI_ROWS: PegawaiRow[] = Array.from({ length: 57 }, (_, i) => buildRow(i))

const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']

export const FACETS: PegawaiFacets = {
  // Periode SKP: 6 bulan terakhir sampai September 2025 (bulan pada mockup).
  periode_skp: Array.from({ length: 6 }, (_, k) => {
    const idx = 8 - k
    return { value: `2025-${pad(idx + 1, 2)}`, label: `${MONTHS[idx]} 2025` }
  }),
  unit: SATKER.map((s) => ({ value: s.nama, label: s.nama })),
  status_pegawai: (['Aktif', 'Tugas Belajar', 'Pensiun'] as const).map((v) => ({ value: v, label: v })),
  jenis_pegawai: (['PNS', 'PPPK', 'PTT'] as const).map((v) => ({ value: v, label: v })),
  group_jabatan: ['Jabatan Fungsional', 'Jabatan Pelaksana', 'Jabatan Struktural'].map((v) => ({ value: v, label: v })),
  sub_group_jabatan: {
    'Jabatan Fungsional': ['Ahli Pertama', 'Ahli Muda'].map((v) => ({ value: v, label: v })),
    'Jabatan Pelaksana': [{ value: 'Pelaksana', label: 'Pelaksana' }],
    'Jabatan Struktural': [{ value: 'Eselon IV', label: 'Eselon IV' }],
  },
}

/* ---------------------------------- Detail ---------------------------------- */

function formatTanggal(year: number, month: number, day: number): string {
  return `${day} ${MONTHS[month - 1]} ${year}`
}

export function buildDetail(nip: string): PegawaiDetail | null {
  const index = PEGAWAI_ROWS.findIndex((r) => r.nip === nip)
  if (index === -1) return null
  const row = PEGAWAI_ROWS[index]

  const year = Number(nip.slice(0, 4))
  const month = Number(nip.slice(4, 6))
  const day = Number(nip.slice(6, 8))
  const tanggal = formatTanggal(year, month, day)

  const dataUmum: DataUmum = {
    nama: row.nama,
    gelar_awal: row.gelar_awal,
    gelar_akhir: row.gelar_akhir,
    tanggal_lahir: `${year}-${pad(month, 2)}-${pad(day, 2)}`,
    provinsi_lahir: 'Jawa Barat',
    kota_lahir: 'Kota Bandung',
    jenis_kelamin: row.jenis_kelamin,
    status_perkawinan: index % 3 === 0 ? 'Tidak Menikah' : 'Menikah',
    nik: `3273${pad(index * 7919, 12)}`.slice(0, 16),
    npwp: `${pad(index * 104729, 15)}`.slice(0, 15),
    no_bpjs_kesehatan: `0001${pad(index * 3571, 9)}`.slice(0, 13),
    no_bpjs_ketenagakerjaan: `${pad(index * 2731, 11)}`,
    no_taspen: `${pad(index * 6607, 12)}`,
    agama: row.agama,
    email: row.email,
    no_hp: `0812${pad(index * 1013, 8)}`.slice(0, 12),
    jenis_kerabat: 'Adik',
    no_telp_kerabat: `0813${pad(index * 1231, 8)}`.slice(0, 12),
    jenis_pegawai: row.jenis_pegawai,
    status_pegawai: row.status_pegawai,
    jenis_status: 'Biasa',
    tmt_status: '',
  }

  const arsip: ArsipItem[] = ['KTP', 'Kartu Keluarga', 'BPJS', 'Karpeg'].map((jenis, k) => ({ id: index * 10 + k + 1, jenis }))

  return {
    nip,
    foto_seed: `pegawai-${index}`,
    jenis_pegawai: row.jenis_pegawai,
    status_pegawai: row.status_pegawai,
    tanggal_lahir_label: tanggal,
    data_umum: dataUmum,
    arsip,
  }
}

/* ------------------------------ Struktur Organisasi ------------------------------ */

function node(id: string, jabatan: string, nama: string, namaLengkap: string, children: OrgNode[] = []): OrgNode {
  return { id, jabatan, nama, nama_lengkap: namaLengkap, foto_seed: `org-${id}`, children }
}

export const ORG_TREE: OrgNode = node('menteri', 'Menteri Pariwisata', 'Ratna Kusuma', 'Ratna Kusuma Wardani', [
  node('wamen', 'Wakil Menteri Pariwisata', 'Bagas Pratama', 'Bagas Pratama Nugraha', [
    node('dep-pemasaran', 'Deputi Bidang Pemasaran', 'Lukman Hakim', 'Lukman Hakim Santosa'),
    node('dep-industri', 'Deputi Bidang Industri dan Investasi', 'Retno Wulandari', 'Retno Wulandari Sari'),
    node('dep-destinasi', 'Deputi Bidang Pengembangan Destinasi', 'Yusuf Rahman', 'Yusuf Rahman Hidayat'),
  ]),
  node('sekmen', 'Sekretaris Kementerian', 'Dian Permata', 'Dian Permata Sari', [
    node('inspektorat', 'Inspektorat', 'Teguh Wibowo', 'Teguh Wibowo Aji'),
    node('biro-renkeu', 'Biro Perencanaan dan Keuangan', 'Melati Ayu', 'Melati Ayu Lestari'),
    node('biro-umum', 'Biro Umum dan SDM', 'Galih Pradipta', 'Galih Pradipta Utama'),
  ]),
  node('sa-birokrasi', 'Staf Ahli Bidang Birokrasi dan Regulasi', 'Kurnia Dewi', 'Kurnia Dewi Anggraini'),
  node('sa-konservasi', 'Staf Ahli Bidang Pembangunan Berkelanjutan dan Konservasi', 'Fransiskus Adi', 'Fransiskus Adi Nugroho'),
  node('sa-digital', 'Staf Ahli Bidang Transformasi Digital dan Inovasi Pariwisata', 'Masruri', 'Masruri Ihsan'),
])

/** Semua simpul (datar) — untuk dropdown "pilih unit" dan pencarian subpohon. */
export function flattenOrg(root: OrgNode = ORG_TREE): OrgNode[] {
  return [root, ...root.children.flatMap((c) => flattenOrg(c))]
}
