<script setup lang="ts">
/**
 * Modal "Rincian Komponen Slip Gaji" admin [K `admin_list.php:436-506`]: identitas (Nama, NIP, Pangkat, Jabatan,
 * Periode, Satker/Unit, status dibuka, IP akses), seluruh kolom GPP dan TK apa adanya (termasuk Pajak & Tunjangan
 * Pajak — CR-067 hanya mengubah slip pegawai), lalu uang makan dan total. Membuka rincian tidak mengubah status slip
 * (server). "Cetak PDF" memakai PDF admin (tanpa gerbang, tanpa ubah status).
 */
import { Printer, X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'radix-vue'

import { UiBadge, UiButton, UiNotice } from '@/shared/ui'

import { formatRupiah } from '../services/slipGaji.service'
import { labelPeriode } from '../services/slipGajiAdmin.service'
import { KOLOM_GPP, KOLOM_TK, LABEL_KOLOM, type SlipAdmin } from '../types'

defineProps<{ open: boolean; slip: SlipAdmin | null; memuat?: boolean; error?: string; mencetak?: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; cetak: [] }>()
</script>

<template>
  <DialogRoot :open="open" @update:open="emit('update:open', $event)">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-slate-900/50" />
      <DialogContent
        class="scrollbar-slim fixed left-1/2 top-1/2 z-50 max-h-[calc(100vh-2rem)] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-2xl bg-white p-6 shadow-panel focus:outline-none"
        data-testid="slip-detail-dialog"
      >
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <DialogTitle class="text-h5 text-slate-900">Rincian Komponen Slip Gaji</DialogTitle>
            <DialogDescription class="text-body2 text-slate-500">Melihat rincian tidak mengubah status slip.</DialogDescription>
          </div>
          <DialogClose class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Tutup">
            <X class="h-5 w-5" aria-hidden="true" />
          </DialogClose>
        </div>

        <p v-if="memuat" class="py-8 text-center text-body2 text-slate-500" role="status">Memuat rincian…</p>
        <UiNotice v-else-if="error" tone="danger" data-testid="slip-detail-error">{{ error }}</UiNotice>

        <template v-else-if="slip">
          <dl class="grid gap-x-6 gap-y-1 rounded-xl bg-slate-50 p-4 text-body2 sm:grid-cols-2" data-testid="slip-detail-identitas">
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-slate-500">Nama</dt><dd class="font-semibold text-slate-900">{{ slip.pegawai.nama ?? '-' }}</dd></div>
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-slate-500">Periode</dt><dd class="font-semibold text-slate-900">{{ slip.label || labelPeriode(slip.periode) }}</dd></div>
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-slate-500">NIP</dt><dd class="font-semibold tabular-nums text-slate-900">{{ slip.nip }}</dd></div>
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-slate-500">Satker/Unit</dt><dd class="text-slate-800">{{ slip.pegawai.satker !== '-' ? slip.pegawai.satker : slip.pegawai.unit }}</dd></div>
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-slate-500">Pangkat/Gol</dt><dd class="text-slate-800">{{ slip.pegawai.pangkat }}</dd></div>
            <div class="flex gap-2">
              <dt class="w-28 shrink-0 text-slate-500">Status Slip</dt>
              <dd data-testid="slip-detail-status">
                <UiBadge v-if="slip.dibuka" tone="success" size="sm">Sudah Dibuka ({{ slip.viewed_at_wib ?? '-' }})</UiBadge>
                <UiBadge v-else tone="warning" size="sm">Belum Dibuka</UiBadge>
              </dd>
            </div>
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-slate-500">Jabatan</dt><dd class="text-slate-800">{{ slip.pegawai.jabatan }}</dd></div>
            <div class="flex gap-2"><dt class="w-28 shrink-0 text-slate-500">IP Akses</dt><dd class="tabular-nums text-slate-800">{{ slip.viewed_ip ?? '-' }}</dd></div>
          </dl>

          <div class="mt-4 grid gap-6 md:grid-cols-2">
            <section aria-labelledby="slip-detail-gpp">
              <h3 id="slip-detail-gpp" class="mb-1 border-b border-slate-200 pb-1 text-caption font-semibold uppercase tracking-wide text-brand-primary">GPP</h3>
              <div v-for="k in KOLOM_GPP" :key="k" class="flex justify-between gap-3 py-0.5 text-body2" :data-testid="`slip-detail-${k}`">
                <span class="text-slate-700">{{ LABEL_KOLOM[k] }}</span>
                <span class="whitespace-nowrap tabular-nums text-slate-900">{{ formatRupiah(slip.rincian[k] ?? '0.00') }}</span>
              </div>
            </section>
            <div>
              <section aria-labelledby="slip-detail-tk">
                <h3 id="slip-detail-tk" class="mb-1 border-b border-slate-200 pb-1 text-caption font-semibold uppercase tracking-wide text-success">TK</h3>
                <div v-for="k in KOLOM_TK" :key="k" class="flex justify-between gap-3 py-0.5 text-body2" :data-testid="`slip-detail-${k}`">
                  <span class="text-slate-700">{{ LABEL_KOLOM[k] }}</span>
                  <span class="whitespace-nowrap tabular-nums text-slate-900">{{ formatRupiah(slip.rincian[k] ?? '0.00') }}</span>
                </div>
              </section>
              <section class="mt-4" aria-labelledby="slip-detail-ringkasan">
                <h3 id="slip-detail-ringkasan" class="mb-1 border-b border-slate-200 pb-1 text-caption font-semibold uppercase tracking-wide text-slate-700">Ringkasan</h3>
                <div class="flex justify-between gap-3 py-0.5 text-body2" data-testid="slip-detail-uang-makan">
                  <span class="text-slate-700">
                    Uang Makan<template v-if="slip.uang_makan.periode_kehadiran"> (presensi {{ labelPeriode(slip.uang_makan.periode_kehadiran) }}, {{ slip.uang_makan.hari ?? 0 }} hari)</template>
                  </span>
                  <span class="whitespace-nowrap tabular-nums text-slate-900">{{ formatRupiah(slip.ringkasan.uang_makan) }}</span>
                </div>
                <div class="mt-1 flex justify-between gap-3 rounded-lg bg-success-soft px-2 py-1.5 text-body2 font-semibold text-slate-900" data-testid="slip-detail-total">
                  <span>Total Penghasilan (Bersih GPP + Bersih TK + Uang Makan)</span>
                  <span class="whitespace-nowrap tabular-nums">{{ formatRupiah(slip.ringkasan.total_penghasilan) }}</span>
                </div>
              </section>
            </div>
          </div>
        </template>

        <div class="mt-6 flex flex-wrap justify-end gap-2">
          <UiButton variant="secondary" appearance="outline" @click="emit('update:open', false)">Tutup</UiButton>
          <UiButton v-if="slip?.aksi.cetak" :loading="mencetak" data-testid="slip-detail-cetak" @click="emit('cetak')">
            <Printer class="h-4 w-4" aria-hidden="true" /> Cetak PDF
          </UiButton>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
