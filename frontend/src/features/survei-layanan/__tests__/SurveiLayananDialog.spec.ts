/**
 * MAKE-084 — dialog SKL: tidak bisa ditutup, rating & kritik wajib di form (legacy `required`), label rating legacy, butir
 * berikutnya setelah tersimpan, `selesai` pada butir terakhir, galat → tetap di butir itu.
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../services/surveiLayanan.service', () => ({ surveiLayananService: { kirim: vi.fn() } }))

import SurveiLayananDialog from '../components/SurveiLayananDialog.vue'
import { surveiLayananService } from '../services/surveiLayanan.service'
import type { TagihanSkl } from '../types'

const svc = vi.mocked(surveiLayananService)
const q = <T extends Element = HTMLElement>(sel: string) => document.body.querySelector<T>(sel)
const tagihan: TagihanSkl[] = [
  { jenis_rwy: 1, id_rwy: 4, jenis_layanan: 'Cuti', info: 'Info cuti' },
  { jenis_rwy: 3, id_rwy: 9, jenis_layanan: 'KARPEG', info: 'Info karpeg' },
]

async function isi(rate: number, kritik: string): Promise<void> {
  q<HTMLElement>(`[data-testid="skl-rate-${rate}"]`)!.click()
  const ta = q<HTMLTextAreaElement>('[data-testid="skl-dialog"] textarea')!
  ta.value = kritik
  ta.dispatchEvent(new Event('input'))
  await flushPromises()
}

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

describe('SurveiLayananDialog', () => {
  it('tidak bisa ditutup dan memakai label rating legacy', async () => {
    mount(SurveiLayananDialog, { props: { tagihan }, attachTo: document.body })
    await flushPromises()

    expect(q('[aria-label="Tutup"]')).toBeNull()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(q('[data-testid="skl-dialog"]')).not.toBeNull()
    expect(q('[data-testid="skl-dialog"]')!.textContent).toContain('5 — Sangat Memuaskan')
    expect(q('[data-testid="skl-dialog"]')!.textContent).toContain('1 — Tidak Memuaskan')
  })

  it('wajib rating & kritik, lalu butir berikutnya dan selesai pada butir terakhir', async () => {
    svc.kirim.mockResolvedValue({ status: 'tercatat' })
    const w = mount(SurveiLayananDialog, { props: { tagihan }, attachTo: document.body })
    await flushPromises()

    q<HTMLButtonElement>('[data-testid="skl-simpan"]')!.click()
    await flushPromises()
    expect(svc.kirim).not.toHaveBeenCalled()
    expect(q('[data-testid="skl-dialog"]')!.textContent).toContain('Pilih tingkat kepuasan.')

    await isi(5, 'Cepat')
    q<HTMLButtonElement>('[data-testid="skl-simpan"]')!.click()
    await flushPromises()
    expect(svc.kirim).toHaveBeenLastCalledWith({ jenis_rwy: 1, id_rwy: 4, rate: 5, kritik_saran: 'Cepat' })
    expect(q('[data-testid="skl-info"]')!.textContent).toContain('Info karpeg')
    expect(w.emitted('selesai')).toBeUndefined()

    await isi(4, 'Baik')
    q<HTMLButtonElement>('[data-testid="skl-simpan"]')!.click()
    await flushPromises()
    expect(w.emitted('selesai')).toHaveLength(1)
  })

  it('galat → pesan dan tetap di butir yang sama', async () => {
    svc.kirim.mockRejectedValue({ status: 500, message: 'Server sibuk', errors: null, isNetworkError: false, original: new AxiosError('x') })
    const w = mount(SurveiLayananDialog, { props: { tagihan }, attachTo: document.body })
    await flushPromises()
    await isi(3, 'Uji')
    q<HTMLButtonElement>('[data-testid="skl-simpan"]')!.click()
    await flushPromises()

    expect(q('[data-testid="skl-dialog"]')!.textContent).toContain('Server sibuk')
    expect(q('[data-testid="skl-info"]')!.textContent).toContain('Info cuti')
    expect(w.emitted('selesai')).toBeUndefined()
  })
})
