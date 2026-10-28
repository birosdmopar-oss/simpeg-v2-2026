/**
 * CR-071 — dialog proses Setujui TB dan IB menandai wajib (*) sesuai kontrak README `POST {jenis}/{id}/process`:
 * no_sk, tgl_sk, ds_{jenis}, dan no_ds_{jenis} wajib; tgl_ds_{jenis} dan perihal_ds_{jenis} opsional. Pagar FE hanya
 * penanda; validasi tetap di server (422 per kolom).
 */
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { STUDI_CONFIG } from '../studiConfig'
import StudiProsesDialog from '../components/StudiProsesDialog.vue'
import type { StudiService } from '../services/studi.service'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

const tanpaService = {} as StudiService

/** Teks label field tanpa tanda bintang, dicari dari elemen label di dalam dialog. */
function labelDialog(nama: string): HTMLElement | undefined {
  return [...document.body.querySelectorAll<HTMLElement>('label, span, p')].find((el) => el.textContent?.trim().startsWith(nama)) ?? undefined
}

async function bukaSetujui(jenis: 'tb' | 'ib') {
  mount(StudiProsesDialog, {
    props: { open: true, row: null, aksi: 'setujui', cfg: STUDI_CONFIG[jenis], service: tanpaService },
    attachTo: document.body,
  })
  await flushPromises()
}

describe('CR-071 — kolom SK wajib di dialog Setujui', () => {
  for (const jenis of ['tb', 'ib'] as const) {
    it(`${jenis.toUpperCase()}: no_sk, tgl_sk, ds, dan no_ds bertanda wajib (*)`, async () => {
      await bukaSetujui(jenis)

      for (const nama of ['No. SK', 'Tanggal SK', 'Dasar SK', 'No. Dasar']) {
        const el = labelDialog(nama)
        expect(el, `label "${nama}" harus ada`).toBeDefined()
        expect(el?.textContent ?? '', `"${nama}" harus bertanda *`).toMatch(/\*/)
      }
    })

    it(`${jenis.toUpperCase()}: tgl_ds dan perihal_ds tetap opsional (tanpa *)`, async () => {
      await bukaSetujui(jenis)

      for (const nama of ['Tanggal Dasar', 'Perihal Dasar']) {
        const el = labelDialog(nama)
        expect(el, `label "${nama}" harus ada`).toBeDefined()
        expect(el?.textContent ?? '', `"${nama}" tidak boleh bertanda *`).not.toMatch(/\*/)
      }
    })
  }
})
