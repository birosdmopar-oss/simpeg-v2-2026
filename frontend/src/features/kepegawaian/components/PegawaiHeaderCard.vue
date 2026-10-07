<script setup lang="ts">
/**
 * Kartu identitas Detail Pegawai (§4.1.6, Gambar 22–24): pita navy→oranye di atas, foto, nama (gelar akhir biru) +
 * badge status, ringkasan NIP/jenis/tanggal lahir, lalu tiga kelompok aksi yang DIPISAHKAN sesuai anotasi dokumen:
 *  - "Arsip Kepegawaian" (tombol biru sendiri),
 *  - "Cetak" (menu: Cetak Data Umum, Cetak DRH — role 1,2,3,4,5),
 *  - "⋯" khusus "Hapus Pegawai" (role 1) karena jarang dipakai dan berisiko.
 * ASET SEMENTARA: foto memakai avatar placeholder dari registry (lihat placeholderAssets.ts).
 */
import { Calendar, FolderOpen, IdCard, MoreHorizontal, Trash2, Upload, UserRound } from 'lucide-vue-next'
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuTrigger,
} from 'radix-vue'
import { computed } from 'vue'

import { UiAvatar, UiBadge, UiButton } from '@/shared/ui'
import { placeholderAvatar } from '@/shared/ui/placeholderAssets'

import type { PegawaiDetail } from '../types'

const props = defineProps<{ detail: PegawaiDetail; canPrint: boolean; canDelete: boolean }>()

const emit = defineEmits<{
  arsip: []
  print: [kind: 'data-umum' | 'drh']
  delete: []
}>()

const du = computed(() => props.detail.data_umum)
const statusTone = computed(() =>
  props.detail.status_pegawai === 'Aktif' ? 'success' : props.detail.status_pegawai === 'Tugas Belajar' ? 'info' : 'neutral',
)

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
        :name="du.nama"
        :src="placeholderAvatar(detail.foto_seed)"
        size="2xl"
        shape="rounded"
        alt=""
        class="-mt-3"
      />

      <div class="min-w-0 flex-1 pt-3">
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="text-h5 text-slate-900" data-testid="pegawai-name">
            {{ [du.gelar_awal, du.nama].filter(Boolean).join(' ') }}<template v-if="du.gelar_akhir"
              >, <span class="text-brand-tertiary">{{ du.gelar_akhir }}</span></template
            >
          </h1>
          <UiBadge :tone="statusTone">{{ detail.status_pegawai }}</UiBadge>
        </div>

        <ul class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-body2 text-slate-600">
          <li class="inline-flex items-center gap-2"><IdCard class="h-4 w-4 text-slate-400" aria-hidden="true" />{{ detail.nip }}</li>
          <li class="inline-flex items-center gap-2"><UserRound class="h-4 w-4 text-slate-400" aria-hidden="true" />{{ detail.jenis_pegawai }}</li>
          <li class="inline-flex items-center gap-2"><Calendar class="h-4 w-4 text-slate-400" aria-hidden="true" />{{ detail.tanggal_lahir_label }}</li>
        </ul>
      </div>

      <div class="flex flex-wrap items-center gap-2 pt-3">
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
              <DropdownMenuItem :class="menuItem" data-action="cetak-data-umum" @select="emit('print', 'data-umum')">Cetak Data Umum</DropdownMenuItem>
              <DropdownMenuItem :class="menuItem" data-action="cetak-drh" @select="emit('print', 'drh')">Cetak DRH</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenuPortal>
        </DropdownMenuRoot>

        <DropdownMenuRoot v-if="canDelete" :modal="false">
          <DropdownMenuTrigger
            class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-500 transition hover:bg-slate-200 data-[state=open]:bg-slate-200"
            aria-label="Menu lainnya"
            data-testid="btn-more"
          >
            <MoreHorizontal class="h-4 w-4" aria-hidden="true" />
          </DropdownMenuTrigger>
          <DropdownMenuPortal>
            <DropdownMenuContent align="end" :side-offset="6" class="z-50 min-w-48 rounded-xl border border-slate-200 bg-white p-1 text-body2 shadow-panel">
              <DropdownMenuItem
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
