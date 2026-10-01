/**
 * Percakapan Halo Simpeg — SEMENTARA disimpan di memori (mock) supaya drawer pegawai dan inbox admin saling terlihat pada
 * demo. Kontrak (`send`, `reply`, `list`) = yang akan diisi endpoint hr/support/halo_simpeg saat backend siap.
 */
import { reactive } from 'vue'

export type HaloSender = 'user' | 'admin'
export interface HaloMessage {
  id: number
  from: HaloSender
  text: string
  at: string
}
export interface HaloThread {
  id: number
  /** Nama tampilan pengirim (data contoh). */
  nama: string
  unit: string
  seed: string
  messages: HaloMessage[]
  /** Jumlah pesan pengguna yang belum dibalas admin. */
  unread: number
}

let seq = 100
const nowIso = (): string => new Date().toISOString()

const state = reactive<{ threads: HaloThread[]; mine: HaloThread | null }>({
  threads: [
    {
      id: 1,
      nama: 'Contoh Pegawai A',
      unit: 'Sekretariat Kementerian',
      seed: 'halo-a',
      unread: 1,
      messages: [{ id: 1, from: 'user', text: 'Selamat pagi, bagaimana cara mengubah data keluarga?', at: nowIso() }],
    },
    {
      id: 2,
      nama: 'Contoh Pegawai B',
      unit: 'Deputi Bidang Pemasaran',
      seed: 'halo-b',
      unread: 0,
      messages: [
        { id: 2, from: 'user', text: 'Kapan jadwal KGB saya?', at: nowIso() },
        { id: 3, from: 'admin', text: 'Silakan cek menu Riwayat KGB pada Data Pegawai.', at: nowIso() },
      ],
    },
  ],
  mine: null,
})

export const haloStore = {
  state,
  /** Percakapan milik pengguna yang sedang login (dibuat saat pesan pertama). */
  mine(): HaloThread | null {
    return state.mine
  },
  send(text: string): void {
    const body = text.trim()
    if (!body) return
    if (!state.mine) {
      state.mine = { id: ++seq, nama: 'Saya', unit: '-', seed: 'halo-saya', messages: [], unread: 0 }
      state.threads.unshift(state.mine)
    }
    state.mine.messages.push({ id: ++seq, from: 'user', text: body, at: nowIso() })
    state.mine.unread += 1
  },
  reply(threadId: number, text: string): void {
    const body = text.trim()
    const thread = state.threads.find((t) => t.id === threadId)
    if (!body || !thread) return
    thread.messages.push({ id: ++seq, from: 'admin', text: body, at: nowIso() })
    thread.unread = 0
  },
  markRead(threadId: number): void {
    const thread = state.threads.find((t) => t.id === threadId)
    if (thread) thread.unread = 0
  },
}
