/**
 * Konstanta chatbot "Vanya" (PRD Fase 8 v1.1 §5.2 F8-30/F8-32/F8-50, §7.10.3).
 */
import { Role, type RoleCode, UL_PEGAWAI } from '@/features/auth/types'

/** Gerbang rilis F8-50: widget dan menu Chatbot Manager terbuka saat `ACTIVE_PHASE >= 8`. */
export const FASE_CHATBOT = 8

/** Widget hanya untuk role pegawai (F8-30, K-8). */
export const CHATBOT_WIDGET_ROLES: readonly RoleCode[] = UL_PEGAWAI

/** Chatbot Manager hanya role 1 (F8-32, K-10). */
export const CHATBOT_ADMIN_ROLES: readonly RoleCode[] = [Role.SUPER_ADMIN]

/** Batas pesan jalur ketikan (F8-41); ditegakkan BE, di FE sebagai `maxlength` + pencegahan kirim. */
export const MAKS_PESAN = 300

/** Teks widget mengikuti view dev `dev:VP` (F8-24, K-2). */
export const TEKS_VANYA = {
  launcher: 'Chat dengan Vanya, asisten virtual SDMO',
  kartuSapaanTebal: 'Halo, Saya Vanya',
  kartuSapaan: ', asisten virtual SDMO, klik untuk mulai chat!',
  judulDialog: 'Chat dengan Vanya',
  nama: 'Vanya',
  peran: 'Asisten Virtual SDMO',
  online: 'Online',
  salam:
    'Halo, saya Vanya, asisten virtual SDMO. Saya siap membantu seputar cuti, SIMPEG, kinerja, dan manajemen talenta. Pilih topik di bawah, atau ketik pertanyaan Anda.',
  pengantarTopik: 'Pilih topik yang ingin Anda tanyakan:',
  pengantarPertanyaan: (topik: string): string => `Berikut pertanyaan yang sering ditanyakan seputar ${topik}. Silakan pilih:`,
  pengantarPopuler: 'Pertanyaan yang sering ditanyakan pegawai lain:',
  topikKosong: 'Belum ada pertanyaan pada topik ini.',
  populerKosong: 'Belum ada pertanyaan populer saat ini. Silakan ketik pertanyaan Anda.',
  penilaian: 'Apakah jawaban ini membantu?',
  membantu: 'Membantu',
  tidakMembantu: 'Tidak membantu',
  terimaKasihNaik: 'Terima kasih atas penilaiannya!',
  terimaKasihTurun: 'Terima kasih, masukan Anda membantu kami memperbaiki jawaban.',
  penilaianGagal: 'Penilaian gagal terkirim.',
  cobaLagi: 'Coba lagi:',
  galatMuat: 'Maaf, Vanya sedang tidak dapat memuat data. Silakan coba beberapa saat lagi.',
  galatKoneksi: 'Maaf, terjadi gangguan koneksi. Silakan coba lagi.',
  placeholder: 'Ketik pertanyaan Anda...',
  mengetik: 'Vanya sedang mengetik',
} as const

/** Peek kartu sapaan: muncul 1,5 detik sesudah halaman dimuat, tampil 4,5 detik (`dev:VP:413-422`). */
export const PEEK_TUNDA_MS = 1500
export const PEEK_LAMA_MS = 4500
