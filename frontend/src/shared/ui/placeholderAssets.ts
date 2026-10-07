/**
 * ⚠️ ASET SEMENTARA (PLACEHOLDER) — BUKAN ASET FINAL.
 *
 * Laporan Redesign memakai foto/ilustrasi/avatar yang belum tersedia sebagai aset resmi Kementerian
 * Pariwisata. Selama pengembangan UI, semua aset visual diambil dari sumber gratis dan dimuat lewat URL
 * (tidak ada berkas yang dikomit ke repo), supaya penggantian nanti cukup menyentuh berkas ini.
 *
 * Aturan pakai:
 *  - Jangan impor URL ini langsung di komponen; selalu lewat konstanta di berkas ini agar mudah dilacak.
 *  - Setiap entri wajib punya `source`, `credit`, dan `license`.
 *  - Saat aset final datang: ganti isi berkas ini dengan path `@/assets/...` dan pindahkan kartu Trello
 *    terkait dari "UI Complete — Temporary Asset" ke "UI Complete".
 *
 * Board tracking: Trello "Frontend-UI" → label 🟠 (Asset: Temporary) dan 🔴 (Needs Final Asset).
 */

export type PlaceholderAsset = {
  url: string
  alt: string
  /** Situs asal (Unsplash / Popsy / DiceBear). */
  source: string
  /** Halaman kredit fotografer/ilustrator, untuk atribusi. */
  credit: string
  license: string
}

/** Foto berita — Portal Berita §4.2.3 dan kartu "Berita Terbaru" Dashboard Admin §4.1.1. */
export const NEWS_PHOTOS: PlaceholderAsset[] = [
  {
    url: 'https://images.unsplash.com/photo-1616388580990-94bf64da5ab8?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&q=80&w=800',
    alt: 'Sejumlah jurnalis sedang meliput kegiatan',
    source: 'Unsplash',
    credit: 'Adrian Hartanto — https://unsplash.com/photos/SspHIqF_tUA',
    license: 'Unsplash License (gratis, komersial, tanpa atribusi wajib)',
  },
  {
    url: 'https://images.unsplash.com/photo-1625236239092-8d15fbff5420?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&q=80&w=800',
    alt: 'Suasana gedung perkantoran dengan pengunjung',
    source: 'Unsplash',
    credit: 'Ruben Sukatendel — https://unsplash.com/photos/VsPGJqafmTk',
    license: 'Unsplash License',
  },
  {
    url: 'https://images.unsplash.com/photo-1592220769343-8a128527c5f1?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&q=80&w=800',
    alt: 'Peserta pelatihan sedang mengikuti sesi kelas',
    source: 'Unsplash',
    credit: 'UX Hours — https://unsplash.com/photos/xPbN9bOPO8g',
    license: 'Unsplash License',
  },
]

/** Ilustrasi datar bergaya mirip mockup (rocket, tim, chat). Popsy: gratis untuk pemakaian komersial. */
export const ILLUSTRATIONS = {
  /** Kartu "Berita Terbaru" §4.1.1 — pada mockup: roket + ikon-ikon informasi. */
  news: {
    url: 'https://illustrations.popsy.co/blue/paper-plane.svg',
    alt: '',
    source: 'Popsy Illustrations',
    credit: 'https://popsy.co/illustrations',
    license: 'Gratis untuk pemakaian pribadi dan komersial',
  },
  /** Sambutan Dashboard Pengguna §4.2.1 — pada mockup: karakter 3D mengacungkan jempol. */
  greeting: {
    url: 'https://illustrations.popsy.co/blue/man-riding-a-rocket.svg',
    alt: '',
    source: 'Popsy Illustrations',
    credit: 'https://popsy.co/illustrations',
    license: 'Gratis untuk pemakaian pribadi dan komersial',
  },
  /** Hero Pusat Bantuan / Halo Simpeg §4.2.5 — pada mockup: gelembung percakapan 3D. */
  support: {
    url: 'https://illustrations.popsy.co/blue/communication.svg',
    alt: '',
    source: 'Popsy Illustrations',
    credit: 'https://popsy.co/illustrations',
    license: 'Gratis untuk pemakaian pribadi dan komersial',
  },
  /** Keadaan kosong (tidak ada data / hasil pencarian nihil). */
  empty: {
    url: 'https://illustrations.popsy.co/blue/falling.svg',
    alt: '',
    source: 'Popsy Illustrations',
    credit: 'https://popsy.co/illustrations',
    license: 'Gratis untuk pemakaian pribadi dan komersial',
  },
} satisfies Record<string, PlaceholderAsset>

/**
 * Avatar pegawai. Mockup memakai ilustrasi karakter; foto pegawai asli belum ada di basis data.
 * DiceBear men-generate SVG deterministik dari `seed` — cocok sebagai placeholder karena pegawai yang
 * sama selalu mendapat gambar yang sama.
 */
export function placeholderAvatar(seed: string): string {
  return `https://api.dicebear.com/9.x/notionists/svg?seed=${encodeURIComponent(seed)}&backgroundColor=d9e7ff,ffe6c9,e5f8ef&radius=50`
}

export const AVATAR_ASSET_INFO: Omit<PlaceholderAsset, 'url' | 'alt'> = {
  source: 'DiceBear 9.x — koleksi "notionists"',
  credit: 'https://www.dicebear.com/styles/notionists/',
  license: 'Kode DiceBear MIT; koleksi notionists CC0 1.0',
}
