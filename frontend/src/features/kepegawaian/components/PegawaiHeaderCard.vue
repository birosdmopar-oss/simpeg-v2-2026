<script setup lang="ts">
/**
 * Kartu identitas Detail Pegawai (§4.1.6, Gambar 22–24): pita navy→oranye di atas, foto, nama (gelar akhir biru) +
 * badge status, ringkasan NIP/jenis/tanggal lahir, lalu tiga kelompok aksi yang DIPISAHKAN sesuai anotasi dokumen:
 *  - "Arsip Kepegawaian" (tombol biru sendiri),
 *  - "Cetak" (menu: Cetak Data Umum, Cetak DRH — role 1,2,3,4,5),
 *  - "⋯" untuk aksi jarang & berisiko (role 1): "Koreksi NIP" (B-06) lalu "Hapus Pegawai" paling bawah (merah).
 * Foto: `GET pegawai/{nip}/foto` (blob → object URL) bila `ada_foto`; gagal/tidak ada → avatar inisial. Status = kolom
 * `pegawai.status` (1 Aktif, 2 Tidak Aktif, 10 Dihapus — COMMENT DDL).
 */
import { Calendar, FolderOpen, IdCard, MoreHorizontal, PencilLine, Trash2, Upload, UserRound } from 'lucide-vue-next'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuTrigger,
} from 'radix-vue'
import QRCode from 'qrcode'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import { UiAvatar, UiBadge, UiButton } from '@/shared/ui'

import { nadaJenisPegawai } from '../columns'
import { pegawaiService } from '../services/pegawai.service'
import type { Pegawai } from '../types'

const props = withDefaults(
  defineProps<{
    detail: Pegawai
    canPrint: boolean
    canDelete: boolean
    canKoreksiNip?: boolean
    deleting?: boolean
    /** Jenis cetak yang tersedia (aditif MAKE-014: DRH tersambung; Cetak Data Umum belum). */
    printKinds?: Array<'data-umum' | 'drh'>
  }>(),
  {
    canKoreksiNip: false,
    deleting: false,
    printKinds: () => ['data-umum', 'drh'],
  },
)

const emit = defineEmits<{
  arsip: []
  print: [kind: 'data-umum' | 'drh']
  'koreksi-nip': []
  delete: []
}>()

const fotoUrl = ref<string | null>(null)
let fotoToken = 0

function lepasFoto(): void {
  if (fotoUrl.value) URL.revokeObjectURL(fotoUrl.value)
  fotoUrl.value = null
}

async function muatFoto(): Promise<void> {
  const token = ++fotoToken
  lepasFoto()
  if (!props.detail.ada_foto) return
  try {
    const blob = await pegawaiService.foto(props.detail.nip)
    if (token === fotoToken) fotoUrl.value = URL.createObjectURL(blob)
  } catch {
    // Foto arsip legacy yang belum dipindah (404) atau galat lain → avatar inisial.
  }
}

watch(() => [props.detail.nip, props.detail.ada_foto, props.detail.flag_update], () => void muatFoto(), { immediate: true })
onBeforeUnmount(() => {
  // Batalkan muatan foto yang masih berjalan agar tidak membuat object URL sesudah komponen dilepas.
  fotoToken++
  lepasFoto()
})

const STATUS: Record<number, { label: string; tone: 'success' | 'neutral' }> = {
  1: { label: 'Aktif', tone: 'success' },
  2: { label: 'Tidak Aktif', tone: 'neutral' },
  10: { label: 'Dihapus', tone: 'neutral' },
}
const status = computed(() => STATUS[props.detail.status] ?? null)

/** QR berisi NIP (data URL PNG, lokal). Gagal dibangkitkan → tanpa QR. */
const qrNip = ref<string | null>(null)
watch(
  () => props.detail.nip,
  async (nip) => {
    try {
      qrNip.value = nip ? await QRCode.toDataURL(nip, { margin: 0, width: 144, errorCorrectionLevel: 'M' }) : null
    } catch {
      qrNip.value = null
    }
  },
  { immediate: true },
)
const tanggalLahir = computed(() => {
  const d = new Date(`${props.detail.tgl_lahir.slice(0, 10)}T00:00:00`)
  return Number.isNaN(d.getTime()) ? props.detail.tgl_lahir : d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })
})

const menuItem =
  'flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-slate-700 outline-none data-[highlighted]:bg-slate-100'
</script>

<template>
  <section class="overflow-hidden rounded-card border border-slate-200 bg-white shadow-card" data-testid="pegawai-header">
    <!-- Pita identitas: navy di kiri melengkung ke oranye. -->
    <div class="relative h-6 bg-warning" aria-hidden="true">
      <div class="absolute inset-y-0 left-0 w-[44%] rounded-br-[1.25rem] bg-brand-primary" />
    </div>

    <div class="flex flex-wrap items-start gap-5 px-5 pb-5">
      <UiAvatar
        :name="detail.nama"
        :src="fotoUrl"
        size="2xl"
        shape="rounded"
        alt=""
        class="-mt-3"
      />

      <!-- CR-049 D-F9: kolom identitas minimal 14rem; tombol aksi turun ke baris sendiri di layar sempit. -->
      <div class="min-w-[14rem] flex-1 pt-3">
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="break-words text-h5 text-slate-900" data-testid="pegawai-name">
            {{ [detail.glr_awal, detail.nama].filter(Boolean).join(' ') }}<template v-if="detail.glr_akhir"
              >, <span class="text-brand-tertiary">{{ detail.glr_akhir }}</span></template
            >
          </h1>
          <UiBadge v-if="status" :tone="status.tone" data-testid="pegawai-status">{{ status.label }}</UiBadge>
        </div>

        <ul class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-body2 text-slate-600">
          <li class="inline-flex items-center gap-2"><IdCard class="h-4 w-4 text-slate-400" aria-hidden="true" />{{ detail.nip }}</li>
          <li class="inline-flex min-w-0 max-w-full items-center gap-2">
            <UserRound class="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
            <UiBadge
              v-if="detail.jenis_pegawai"
              :tone="nadaJenisPegawai(detail.id_jenis_pegawai)"
              :title="detail.jenis_pegawai"
              data-testid="pegawai-jenis"
            >{{ detail.jenis_pegawai }}</UiBadge>
            <template v-else>—</template>
          </li>
          <li class="inline-flex items-center gap-2"><Calendar class="h-4 w-4 text-slate-400" aria-hidden="true" />{{ tanggalLahir }}</li>
        </ul>
      </div>

      <!-- QR NIP (CR-049 D-F8): legacy kartu profil tanpa QR → isi = NIP saja, dibangkitkan lokal (lib qrcode, tanpa layanan luar). -->
      <img
        v-if="qrNip"
        :src="qrNip"
        :alt="`Kode QR NIP ${detail.nip}`"
        width="72"
        height="72"
        class="mt-3 h-[72px] w-[72px] shrink-0 rounded border border-slate-200 bg-white p-1"
        data-testid="pegawai-qr"
      />

      <div class="flex w-full flex-wrap items-center gap-2 pt-3 lg:w-auto">
        <UiButton data-testid="btn-arsip" @click="emit('arsip')">
          <template #icon-left><FolderOpen class="h-4 w-4" aria-hidden="true" /></template>
          Arsip Kepegawaian
        </UiButton>

        <DropdownMenuRoot v-if="canPrint" :modal="false">
          <DropdownMenuTrigger as-child>
            <UiButton variant="secondary" appearance="soft" data-testid="btn-cetak">
              <template #icon-left><Upload class="h-4 w-4" aria-hidden="true" /></template>
              Cetak
            </UiButton>
          </DropdownMenuTrigger>
          <DropdownMenuPortal>
            <DropdownMenuContent align="end" :side-offset="6" class="z-50 min-w-48 rounded-xl border border-slate-200 bg-white p-1 text-body2 shadow-panel">
              <DropdownMenuItem v-if="printKinds.includes('data-umum')" :class="menuItem" data-action="cetak-data-umum" @select="emit('print', 'data-umum')">Cetak Data Umum</DropdownMenuItem>
              <DropdownMenuItem v-if="printKinds.includes('drh')" :class="menuItem" data-action="cetak-drh" @select="emit('print', 'drh')">Cetak DRH</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenuPortal>
        </DropdownMenuRoot>

        <DropdownMenuRoot v-if="canDelete || canKoreksiNip" :modal="false">
          <DropdownMenuTrigger
            class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition hover:bg-slate-200 data-[state=open]:bg-slate-200"
            aria-label="Menu lainnya"
            data-testid="btn-more"
          >
            <MoreHorizontal class="h-4 w-4" aria-hidden="true" />
          </DropdownMenuTrigger>
          <DropdownMenuPortal>
            <DropdownMenuContent align="end" :side-offset="6" class="z-50 min-w-48 rounded-xl border border-slate-200 bg-white p-1 text-body2 shadow-panel">
              <DropdownMenuItem v-if="canKoreksiNip" :class="menuItem" data-action="koreksi-nip" @select="emit('koreksi-nip')">
                <PencilLine class="h-4 w-4" aria-hidden="true" /> Koreksi NIP
              </DropdownMenuItem>
              <DropdownMenuItem
                v-if="canDelete"
                :disabled="deleting"
                class="flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 text-danger outline-none data-[highlighted]:bg-danger-soft"
                data-action="hapus-pegawai"
                @select="emit('delete')"
              >
                <Trash2 class="h-4 w-4" aria-hidden="true" /> Hapus Pegawai
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenuPortal>
        </DropdownMenuRoot>
      </div>
    </div>
  </section>
</template>
