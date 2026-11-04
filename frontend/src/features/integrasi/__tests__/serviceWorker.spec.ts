/**
 * MAKE-066 (F6-13) — `public/firebase-messaging-sw.js`: pesan FCM `{notification, data}` ditampilkan; klik membuka
 * `data.link` relatif se-origin di jendela baru (tab terbuka TIDAK dinavigasi, CR-099 P5); tautan ke host lain diabaikan
 * [K legacy `notificationclick`]. Dijalankan dalam lingkup `self` palsu.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { beforeEach, describe, expect, it, vi } from 'vitest'

type Handler = (event: Record<string, unknown>) => void

const kode = readFileSync(resolve(__dirname, '../../../../public/firebase-messaging-sw.js'), 'utf8')

let handler: Record<string, Handler>
let lingkup: {
  addEventListener: (nama: string, fn: Handler) => void
  skipWaiting: () => void
  location: { origin: string }
  registration: { showNotification: ReturnType<typeof vi.fn> }
  clients: { claim: () => Promise<void>; matchAll: ReturnType<typeof vi.fn>; openWindow: ReturnType<typeof vi.fn> }
}

function jalankan(nama: string, event: Record<string, unknown>): Promise<unknown> {
  let tunggu: Promise<unknown> = Promise.resolve()
  handler[nama]?.({ ...event, waitUntil: (p: Promise<unknown>) => (tunggu = p) })
  return tunggu
}

beforeEach(() => {
  handler = {}
  lingkup = {
    addEventListener: (nama, fn) => (handler[nama] = fn),
    skipWaiting: () => {},
    location: { origin: 'https://simpeg.contoh' },
    registration: { showNotification: vi.fn(async () => {}) },
    clients: { claim: async () => {}, matchAll: vi.fn(async () => []), openWindow: vi.fn(async () => null) },
  }
  new Function('self', kode)(lingkup)
})

describe('firebase-messaging-sw.js', () => {
  it('push FCM ditampilkan dengan judul, isi, ikon, dan tautan', async () => {
    const payload = { notification: { title: 'Riwayat KGB Disetujui', body: 'Data: x', icon: '/apple-touch-icon.png' }, data: { link: '/pegawai/1/riwayat?tab=kgb' } }
    await jalankan('push', { data: { json: () => payload } })

    expect(lingkup.registration.showNotification).toHaveBeenCalledWith('Riwayat KGB Disetujui', {
      body: 'Data: x',
      icon: '/apple-touch-icon.png',
      data: { link: '/pegawai/1/riwayat?tab=kgb' },
    })
  })

  it('payload rusak → notifikasi bawaan tanpa galat; data.url dipakai bila link tidak ada', async () => {
    await jalankan('push', { data: { json: () => JSON.parse('{rusak') } })
    await jalankan('push', { data: { json: () => ({ notification: { title: 'Cuti' }, data: { url: '/layanan/cuti/7' } }) } })

    expect(lingkup.registration.showNotification.mock.calls.map((c) => [c[0], (c[1] as { data: unknown }).data])).toEqual([
      ['SIMPEG', { link: '/' }],
      ['Cuti', { link: '/layanan/cuti/7' }],
    ])
  })

  it('klik: selalu jendela baru tanpa menavigasi tab terbuka; host lain diabaikan', async () => {
    const close = vi.fn()

    await jalankan('notificationclick', { notification: { close, data: { link: '/konket' } } })
    expect(close).toHaveBeenCalled()
    expect(lingkup.clients.openWindow).toHaveBeenCalledWith('https://simpeg.contoh/konket')
    expect(lingkup.clients.matchAll).not.toHaveBeenCalled()

    await jalankan('notificationclick', { notification: { close, data: { link: 'https://jahat.contoh/x' } } })
    expect(lingkup.clients.openWindow).toHaveBeenCalledOnce()
  })
})
