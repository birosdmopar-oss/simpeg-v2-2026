import type { Component } from 'vue'

/** Satu item menu aksi baris tabel (RowActionsMenu, aturan UI "aksi baris lewat menu titik tiga"). */
export interface RowAction {
  /** Kunci unik aksi, dikirim lewat event `select`. */
  key: string
  /** Teks item menu (kata kerja, mis. "Edit", "Nonaktifkan", "Hapus"). */
  label: string
  icon?: Component
  /** Aksi destruktif (mis. Hapus): warna merah + pemisah di atasnya; letakkan paling akhir. */
  danger?: boolean
  disabled?: boolean
  /**
   * Keterangan opsional di bawah label (teks kecil redup), mis. alasan item `disabled` ("Petugas ini petugas terakhir…").
   * Dihubungkan ke item lewat `aria-describedby` agar pembaca layar membacakannya saat item difokus. Tanpa keterangan, DOM
   * item tidak berubah (aditif, MAKE-073 review B3 R2).
   */
  keterangan?: string
  hidden?: boolean
}
