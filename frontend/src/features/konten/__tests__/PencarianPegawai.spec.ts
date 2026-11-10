/**
 * Pencarian pegawai sisi-server untuk pemilih target: tidak mencari di bawah 3 huruf, debounce 300 ms, memilih saran
 * memancarkan pilih(nip, label) dan mengosongkan kolom; hasil lama yang terlambat diabaikan.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import PencarianPegawai from '../components/PencarianPegawai.vue'

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

const hasil = [{ value: '198001', label: '198001 - Budi' }]

describe('PencarianPegawai', () => {
  it('tidak mencari di bawah 3 huruf; mencari setelah debounce', async () => {
    const cari = vi.fn(async () => hasil)
    const w = mount(PencarianPegawai, { props: { cari, label: 'Cari' } })
    const input = w.get('input')

    await input.setValue('bu')
    await vi.advanceTimersByTimeAsync(400)
    expect(cari).not.toHaveBeenCalled()

    await input.setValue('bud')
    await vi.advanceTimersByTimeAsync(299)
    expect(cari).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(2)
    expect(cari).toHaveBeenCalledTimes(1)
    expect(cari).toHaveBeenCalledWith('bud')
    w.unmount()
  })

  it('mengetik cepat hanya memanggil pencarian terakhir', async () => {
    const cari = vi.fn(async () => hasil)
    const w = mount(PencarianPegawai, { props: { cari, label: 'Cari' } })
    const input = w.get('input')

    await input.setValue('bud')
    await vi.advanceTimersByTimeAsync(100)
    await input.setValue('budi')
    await vi.advanceTimersByTimeAsync(400)
    expect(cari).toHaveBeenCalledTimes(1)
    expect(cari).toHaveBeenCalledWith('budi')
    w.unmount()
  })

  it('mengetik teks yang sama dengan label saran memancarkan pilih lalu mengosongkan kolom', async () => {
    const cari = vi.fn(async () => hasil)
    const w = mount(PencarianPegawai, { props: { cari, label: 'Cari' } })
    const input = w.get('input')

    await input.setValue('bud')
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()
    await input.setValue('198001 - Budi')

    expect(w.emitted('pilih')).toEqual([['198001', '198001 - Budi']])
    expect((w.get('input').element as HTMLInputElement).value).toBe('')
    w.unmount()
  })

  it('galat pencarian tidak memunculkan saran dan tidak melempar', async () => {
    const cari = vi.fn(async () => {
      throw new Error('x')
    })
    const w = mount(PencarianPegawai, { props: { cari, label: 'Cari' } })

    await w.get('input').setValue('bud')
    await vi.advanceTimersByTimeAsync(400)
    await flushPromises()
    expect(w.emitted('pilih')).toBeUndefined()
    w.unmount()
  })
})
