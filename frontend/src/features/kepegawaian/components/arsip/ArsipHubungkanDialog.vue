<script setup lang="ts">
/**
 * Dialog "Hubungkan" arsip yatim (F-10 Tahap 2, MAKE-096; PRD §6.1, F10-28, F10-31, F10-38, AC2-05/06/13): dua langkah
 * dalam satu dialog. (1) Pilih jenis dari `aksi.jenis_hubungkan` (server menyempitkan per aktor; role 2/6/7 praktis hanya
 * Arsip Data Umum / kode 38). (2) Pilih data riwayat dari `GET …/opsi-hubungkan?id_riwayat=` (Disetujui, milik NIP);
 * kode 38 tanpa pilihan data. Lalu `POST …/berkas/{id}/hubungkan`. Pesan 403 (kunci baris Disetujui) / 404 / 422 dari server
 * tampil di dialog. Dialog tidak bisa ditutup selama proses.
 */
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'
import { computed, ref, watch } from 'vue'

import { UiButton, UiNotice, UiSelect } from '@/shared/ui'
import type { SelectOption } from '@/shared/ui'

import { urainGalatAksi } from '../../arsip.galat'
import { ID_ARSIP_DATA_UMUM, type ArsipBerkas, type ArsipOpsiRiwayat } from '../../arsip.types'
import { arsipService } from '../../services/arsip.service'

const props = defineProps<{
  open: boolean
  nip: string
  item: ArsipBerkas | null
  /** Kode tujuan yang boleh bagi aktor (`aksi.jenis_hubungkan` respons folder). */
  jenisHubungkan: number[]
  /** Label per kode `jenis_rwy` (dari folder / master); kode tanpa label tampil "Jenis <kode>". */
  labelJenis: Record<number, string>
}>()
const emit = defineEmits<{ 'update:open': [value: boolean]; done: [pesan: string] }>()

const jenis = ref<string>('')
const entri = ref<string>('')
const opsi = ref<ArsipOpsiRiwayat[]>([])
const memuatOpsi = ref(false)
const busy = ref(false)
const galat = ref('')

const kode = computed(() => (jenis.value === '' ? null : Number(jenis.value)))
const tanpaPilihanData = computed(() => kode.value === ID_ARSIP_DATA_UMUM)

const opsiJenis = computed<SelectOption[]>(() =>
  props.jenisHubungkan.map((k) => ({ value: String(k), label: props.labelJenis[k] ?? (k === ID_ARSIP_DATA_UMUM ? 'Arsip Data Umum' : `Jenis ${k}`) })),
)
const opsiEntri = computed<SelectOption[]>(() => opsi.value.map((o) => ({ value: o.id_entri, label: o.ringkasan })))

const bisaKirim = computed(() => kode.value !== null && !memuatOpsi.value && (tanpaPilihanData.value || entri.value !== ''))

watch(
  () => [props.open, props.item?.id_attachment] as const,
  ([terbuka]) => {
    if (!terbuka) return
    jenis.value = ''
    entri.value = ''
    opsi.value = []
    galat.value = ''
  },
  { immediate: true },
)

let permintaan = 0
watch(jenis, async (nilai) => {
  const ini = ++permintaan
  entri.value = ''
  opsi.value = []
  galat.value = ''
  if (nilai === '' || Number(nilai) === ID_ARSIP_DATA_UMUM) {
    memuatOpsi.value = false
    return
  }
  memuatOpsi.value = true
  try {
    const hasil = await arsipService.opsiHubungkan(props.nip, Number(nilai))
    if (ini !== permintaan) return
    opsi.value = hasil.items
  } catch (error) {
    if (ini !== permintaan) return
    galat.value = urainGalatAksi(error, ['id_riwayat']).umum || 'Data riwayat tidak dapat dimuat.'
  } finally {
    if (ini === permintaan) memuatOpsi.value = false
  }
})

async function hubungkan(): Promise<void> {
  const item = props.item
  if (!item || kode.value === null || !bisaKirim.value || busy.value) return
  busy.value = true
  galat.value = ''
  try {
    await arsipService.hubungkan(props.nip, item.id_attachment, tanpaPilihanData.value ? { id_riwayat: kode.value } : { id_riwayat: kode.value, id_entri: entri.value })
    emit('update:open', false)
    emit('done', 'Arsip dihubungkan.')
  } catch (error) {
    const hasil = urainGalatAksi(error, ['id_attachment', 'id_riwayat', 'id_entri'])
    galat.value = hasil.kolom.id_attachment ?? hasil.kolom.id_riwayat ?? hasil.kolom.id_entri ?? hasil.umum
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <DialogRoot :open="open" @update:open="(v) => !busy && emit('update:open', v)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="fixed left-1/2 top-1/2 z-50 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="arsip-hubungkan-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Hubungkan Arsip</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">{{ item?.nama_arsip }}</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <form class="space-y-4" novalidate @submit.prevent="hubungkan">
          <UiSelect v-model="jenis" label="Jenis Riwayat" placeholder="Pilih jenis riwayat" required :options="opsiJenis" data-testid="arsip-hubungkan-jenis" />

          <template v-if="kode !== null && !tanpaPilihanData">
            <p v-if="memuatOpsi" class="text-body2 text-slate-500" data-testid="arsip-hubungkan-memuat">Memuat data riwayat…</p>
            <p v-else-if="opsi.length === 0 && !galat" class="text-body2 text-slate-600" data-testid="arsip-hubungkan-kosong">
              Tidak ada data riwayat berstatus Disetujui untuk jenis ini.
            </p>
            <UiSelect v-else-if="opsi.length > 0" v-model="entri" label="Data Riwayat" placeholder="Pilih data riwayat" required :options="opsiEntri" data-testid="arsip-hubungkan-entri" />
          </template>
          <p v-else-if="tanpaPilihanData" class="text-body2 text-slate-600" data-testid="arsip-hubungkan-tanpa-data">Arsip Data Umum tidak memerlukan pilihan data riwayat.</p>

          <UiNotice v-if="galat" tone="danger" data-testid="arsip-hubungkan-galat">{{ galat }}</UiNotice>

          <div class="flex justify-end gap-3 pt-2">
            <UiButton variant="secondary" appearance="soft" :disabled="busy" @click="emit('update:open', false)">Batal</UiButton>
            <UiButton type="submit" :loading="busy" :disabled="!bisaKirim" data-testid="arsip-hubungkan-simpan">Hubungkan</UiButton>
          </div>
        </form>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
