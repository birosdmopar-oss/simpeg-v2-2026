<script setup lang="ts">
/**
 * Detail Cuti (PRD §6.11.1): data pengajuan, stepper tahap (dari `tahap[]` server), catatan approver, ketua tim,
 * lampiran bukti, dan surat. Aksi lewat satu menu ⋮ (`cuti-actions-<id>`) yang disaring `aksi {}` server. 404 → "Data
 * tidak ditemukan"; 403 → "Anda tidak berhak …" (describeApiError).
 */
import { formatTanggalIndonesia as tgl } from '@/shared/utils/dateTime'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiButton, UiCard, UiNotice, UiStepper } from '@/shared/ui'

import CutiFormDialog from '../components/CutiFormDialog.vue'
import CutiProsesDialog from '../components/CutiProsesDialog.vue'
import StatusLayananBadge from '../components/StatusLayananBadge.vue'
import { useCutiAksi } from '../composables/useCutiAksi'
import { aksiBarisCuti, labelAksiCuti } from '../rowActionsCuti'
import { LAYANAN_ROUTE } from '../routes.layanan'
import { cutiService } from '../services/cuti.service'
import { nadaStepper, tahapKeSteps } from '../tahapStepper'
import type { CutiDetail } from '../types'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const detail = ref<CutiDetail | null>(null)
const memuat = ref(true)
const galat = ref('')

const crumbs = computed(() => [
  { label: 'Home', to: { name: 'home' } },
  { label: 'Cuti', to: { name: LAYANAN_ROUTE.CUTI } },
  { label: 'Detail Cuti' },
])

async function muat(): Promise<void> {
  memuat.value = true
  galat.value = ''
  try {
    detail.value = await cutiService.detail(id.value)
  } catch (e) {
    detail.value = null
    galat.value = describeApiError(e).message
  } finally {
    memuat.value = false
  }
}

const aksi = useCutiAksi(async (jenis) => {
  if (jenis === 'hapus') {
    await router.replace({ name: LAYANAN_ROUTE.CUTI })
    return
  }
  await muat()
})

onMounted(() => void muat())

const periode = computed(() => {
  const d = detail.value
  if (!d) return ''
  return d.tgl_mulai === d.tgl_akhir ? tgl(d.tgl_mulai) : `${tgl(d.tgl_mulai)} s.d. ${tgl(d.tgl_akhir)}`
})

async function unduhLampiran(): Promise<void> {
  if (!detail.value) return
  try {
    simpanBerkas(await cutiService.lampiran(detail.value.id))
  } catch (e) {
    aksi.notice.value = { tone: 'danger', text: describeApiError(e).message }
  }
}

const formOpen = ref(false)

function onAction(key: string): void {
  if (!detail.value) return
  if (key === 'edit') formOpen.value = true
  else aksi.pilih(key, detail.value)
}

function onSaved(): void {
  aksi.notice.value = { tone: 'success', text: 'Pengajuan cuti disimpan.' }
  void muat()
}

const subjekProses = computed(() => (detail.value ? `cuti ${detail.value.nama} (${detail.value.nama_jenis_cuti})` : ''))
const catatan = computed(() => {
  const r = detail.value?.riwayat_approval
  if (!r) return []
  return [
    { label: 'Catatan Petugas', teks: r.keterangan_petugas_approval },
    { label: 'Alasan Atasan Langsung', teks: r.reason_note_al },
    { label: 'Alasan Pejabat Berwenang', teks: r.reason_note_pyb },
  ].filter((c) => c.teks)
})
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <UiNotice v-if="galat" tone="danger" class="mb-4" data-testid="cuti-detail-error">{{ galat }}</UiNotice>
    <UiNotice v-if="aksi.notice.value" :tone="aksi.notice.value.tone" class="mb-4" data-testid="cuti-detail-notice">{{ aksi.notice.value.text }}</UiNotice>
    <p v-if="memuat && !detail" class="py-10 text-center text-body2 text-slate-500">Memuat…</p>

    <template v-if="detail">
      <UiCard :title="`${detail.nama_jenis_cuti} — ${detail.nama}`" :subtitle="`NIP ${detail.nip}`">
        <template #actions>
          <div class="flex items-center gap-3">
            <StatusLayananBadge :label="detail.label_status" :nada="detail.nada_status" />
            <RowActionsMenu
              :actions="aksiBarisCuti(detail, { busy: aksi.busyId.value === detail.id, detail: false })"
              :label="labelAksiCuti(detail, periode, true)"
              :testid="`cuti-actions-${detail.id}`"
              @select="onAction"
            />
          </div>
        </template>

        <div class="mb-6 overflow-x-auto" data-testid="cuti-stepper">
          <UiStepper :steps="tahapKeSteps(detail.tahap)" :tone="nadaStepper(detail.nada_status)" />
        </div>

        <dl class="grid gap-x-8 gap-y-3 text-body2 md:grid-cols-2" data-testid="cuti-detail-data">
          <div><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ periode }}</dd></div>
          <div><dt class="text-slate-500">Lama</dt><dd class="text-slate-900">{{ detail.lama_cuti }} hari</dd></div>
          <div><dt class="text-slate-500">Atasan Langsung</dt><dd class="text-slate-900">{{ detail.nama_atasan_langsung ?? '—' }}</dd></div>
          <div><dt class="text-slate-500">Pejabat Berwenang</dt><dd class="text-slate-900">{{ detail.nama_yang_menyetujui ?? '—' }}</dd></div>
          <div><dt class="text-slate-500">No. Telepon Saat Cuti</dt><dd class="text-slate-900">{{ detail.no_telp_saat_cuti || '—' }}</dd></div>
          <div><dt class="text-slate-500">Alamat Saat Cuti</dt><dd class="text-slate-900">{{ detail.alamat_saat_cuti || '—' }}</dd></div>
          <div class="md:col-span-2"><dt class="text-slate-500">Keterangan</dt><dd class="whitespace-pre-line text-slate-900">{{ detail.keterangan || '—' }}</dd></div>
          <div v-if="detail.ketua_tim_detail.length" class="md:col-span-2">
            <dt class="text-slate-500">Tembusan Ketua Tim</dt>
            <dd class="text-slate-900">{{ detail.ketua_tim_detail.map((k) => k.nama).join(', ') }}</dd>
          </div>
        </dl>
      </UiCard>

      <UiCard v-if="catatan.length" title="Catatan Persetujuan" class="mt-4">
        <dl class="space-y-3 text-body2" data-testid="cuti-catatan">
          <div v-for="c in catatan" :key="c.label"><dt class="text-slate-500">{{ c.label }}</dt><dd class="whitespace-pre-line text-slate-900">{{ c.teks }}</dd></div>
        </dl>
      </UiCard>

      <UiCard title="Berkas" class="mt-4">
        <ul class="space-y-2 text-body2" data-testid="cuti-berkas">
          <li v-if="detail.lampiran.length" class="flex items-center justify-between gap-3">
            <span class="text-slate-900">Lampiran bukti: {{ detail.lampiran[0]?.display_name ?? 'berkas' }}</span>
            <UiButton size="sm" appearance="soft" variant="secondary" data-testid="cuti-unduh-lampiran" @click="unduhLampiran">Unduh</UiButton>
          </li>
          <li v-for="s in detail.surat" :key="s.id_attachment" class="text-slate-900">
            Surat cuti {{ s.jenis === 'tte' ? '(sudah TTE)' : '(belum TTE)' }}
          </li>
          <li v-if="!detail.lampiran.length && !detail.surat.length" class="text-slate-500">Belum ada berkas.</li>
        </ul>
      </UiCard>
    </template>

    <CutiFormDialog v-if="detail" v-model:open="formOpen" :row="detail" @saved="onSaved" />

    <CutiProsesDialog
      v-if="aksi.prosesRow.value"
      :open="aksi.prosesOpen.value"
      :aksi="aksi.prosesAksi.value"
      :atas-nama="aksi.prosesAtasNama.value"
      :subject="subjekProses"
      :loading="aksi.prosesBusy.value"
      :error="aksi.prosesError.value"
      @update:open="(v) => (aksi.prosesOpen.value = v)"
      @confirm="aksi.proses"
    />

    <ConfirmDialog
      :open="aksi.hapusOpen.value"
      title="Hapus pengajuan cuti?"
      description="Pengajuan akan dihapus; saldo yang terpotong dikembalikan."
      confirm-label="Ya, hapus"
      danger
      @update:open="(v) => (aksi.hapusOpen.value = v)"
      @confirm="aksi.hapus"
    />
  </RedesignShell>
</template>
