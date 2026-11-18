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
   * Keterangan opsional di bawah label (teks sekunder kecil, tanpa opacity), mis. alasan item `disabled` ("Petugas ini
   * petugas terakhir…"). Dihubungkan lewat `aria-describedby`; nama item tetap = label (`aria-labelledby`). Item `disabled`
   * yang berketerangan tetap bisa difokus dengan panah (`aria-disabled="true"`, memilihnya ditolak) supaya alasannya
   * terbaca (MAKE-073 review S1); item `disabled` tanpa keterangan dilewati seperti biasa. Tanpa keterangan, DOM item tidak
   * berubah (aditif, review B3 R2).
   */
  keterangan?: string
  hidden?: boolean
}
