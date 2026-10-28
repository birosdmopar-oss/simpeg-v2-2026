<script setup lang="ts">
/**
 * Detail Tugas Belajar / Izin Belajar (berparameter `jenis`). Stepper dari `tahap[]` server; data dari `GET {jenis}/{id}`.
 * Tombol halaman (Ubah, Setujui, Tolak, Perpanjangan, Hapus) mengikuti `aksi {}` server. Lampiran dan perpanjangan
 * memakai ⋮ per baris (AGENTS §1). Label slot berkas dari `GET {jenis}/opsi`, bukan dari konfigurasi FE.
 */
import { Plus } from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { describeApiError } from '@/features/kepegawaian/services/apiErrors'
import { simpanBerkas } from '@/features/kepegawaian/services/berkasUnduhan'
import ConfirmDialog from '@/shared/components/ConfirmDialog.vue'
import RowActionsMenu from '@/shared/components/RowActionsMenu.vue'
import type { RowAction } from '@/shared/components/rowActions'
import RedesignShell from '@/shared/layouts/RedesignShell.vue'
import { UiBadge, UiButton, UiCard, UiNotice, UiStepper } from '@/shared/ui'

import StudiFormDialog from '../components/StudiFormDialog.vue'
import StudiPerpanjanganDialog from '../components/StudiPerpanjanganDialog.vue'
import StudiProsesDialog, { type AksiProsesStudi } from '../components/StudiProsesDialog.vue'
import { STUDI_CONFIG } from '../studiConfig'
import { tahapKeSteps, nadaStepper } from '../tahapStepper'
import { ibService, tbService, type PerpanjanganStudi, type StudiDetail, type StudiJenis, type StudiOpsi, type StudiService } from '../services/studi.service'

const props = defineProps<{ jenis: StudiJenis }>()

const cfg = STUDI_CONFIG[props.jenis]
const service: StudiService = props.jenis === 'tb' ? tbService : ibService
const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))

const crumbs = computed(() => [
  { label: 'Home', to: { name: 'home' } },
  { label: 'Layanan' },
  { label: cfg.judul, to: { name: cfg.routeList } },
  { label: detail.value?.nama ?? `#${id.value}` },
])

const detail = ref<StudiDetail | null>(null)
const opsi = ref<StudiOpsi | null>(null)
const memuat = ref(false)
const failure = ref<string | null>(null)
const notice = ref('')

async function muat(): Promise<void> {
  memuat.value = true
  failure.value = null
  try {
    detail.value = await service.detail(id.value)
  } catch (err) {
    detail.value = null
    failure.value = describeApiError(err).message
  } finally {
    memuat.value = false
  }
}

onMounted(async () => {
  try {
    opsi.value = await service.opsi()
  } catch {
    opsi.value = null
  }
  void muat()
})

const labelSlot = (tag: string | null): string => opsi.value?.slot_berkas.find((s) => s.tag === tag)?.label ?? tag ?? 'Berkas'
const tanggal = (t: string): string => (t ? t.split('-').reverse().join('/') : '-')

// --- Dialog ----------------------------------------------------------------------------------------------------------
const formOpen = ref(false)
const proses = ref<{ open: boolean; aksi: AksiProsesStudi }>({ open: false, aksi: 'setujui' })
const perpan = ref<{ open: boolean; row: PerpanjanganStudi | null }>({ open: false, row: null })
const hapus = ref<{ open: boolean; loading: boolean; perpanjangan: PerpanjanganStudi | null }>({ open: false, loading: false, perpanjangan: null })
const hapusInduk = ref(false)

function onSelesai(pesan: string): void {
  notice.value = pesan
  void muat()
}

async function unduhLampiran(idAttachment: number, tag: string | null): Promise<void> {
  try {
    simpanBerkas(await service.lampiran(id.value, idAttachment, `${cfg.jenis}-${labelSlot(tag)}`))
  } catch (err) {
    failure.value = describeApiError(err).message
  }
}

async function unduhSurat(): Promise<void> {
  try {
    simpanBerkas(await service.surat(id.value, `${cfg.jenis}-surat`))
  } catch (err) {
    failure.value = describeApiError(err).message
  }
}

const AKSI_LAMPIRAN: RowAction[] = [{ key: 'unduh', label: 'Unduh' }]

function aksiPerpan(): RowAction[] {
  return [
    { key: 'edit', label: 'Edit' },
    { key: 'hapus', label: 'Hapus', danger: true },
  ]
}

function onPerpanAksi(p: PerpanjanganStudi, key: string): void {
  if (key === 'edit') perpan.value = { open: true, row: p }
  else if (key === 'hapus') hapus.value = { open: true, loading: false, perpanjangan: p }
}

async function konfirmasiHapus(): Promise<void> {
  hapus.value.loading = true
  try {
    if (hapus.value.perpanjangan) {
      await service.hapusPerpanjangan(hapus.value.perpanjangan.id)
      notice.value = 'Perpanjangan dihapus.'
    }
  } catch (err) {
    failure.value = describeApiError(err).message
  } finally {
    hapus.value = { open: false, loading: false, perpanjangan: null }
    void muat()
  }
}

async function hapusPengajuan(): Promise<void> {
  try {
    await service.hapus(id.value)
    await router.push({ name: cfg.routeList })
  } catch (err) {
    failure.value = describeApiError(err).message
    hapusInduk.value = false
  }
}

const bolehPerpanjangan = computed(() => detail.value?.aksi.perpanjangan === true && cfg.perpanjanganDiizinkan)
</script>

<template>
  <RedesignShell :breadcrumbs="crumbs">
    <div class="page-fill flex flex-col gap-4" :data-testid="`${cfg.testid}-detail-page`">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-h2 font-semibold text-slate-900">{{ cfg.judul }}</h1>
          <p class="text-body2 text-slate-500">{{ detail?.nama ?? detail?.nip ?? '' }}</p>
        </div>
        <div v-if="detail" class="flex flex-wrap gap-2">
          <UiButton v-if="detail.aksi.ubah" variant="secondary" appearance="outline" :data-testid="`${cfg.testid}-ubah`" @click="formOpen = true">Ubah</UiButton>
          <UiButton v-if="detail.aksi.proses" variant="secondary" appearance="outline" :data-testid="`${cfg.testid}-setujui`" @click="proses = { open: true, aksi: 'setujui' }">Setujui</UiButton>
          <UiButton v-if="detail.aksi.proses" variant="secondary" appearance="outline" :data-testid="`${cfg.testid}-tolak`" @click="proses = { open: true, aksi: 'tolak' }">Tolak</UiButton>
          <UiButton v-if="detail.aksi.unduh_surat" variant="secondary" appearance="outline" :data-testid="`${cfg.testid}-unduh-surat`" @click="unduhSurat">Unduh surat</UiButton>
          <UiButton v-if="detail.aksi.hapus" variant="secondary" appearance="outline" :data-testid="`${cfg.testid}-hapus`" @click="hapusInduk = true">Hapus</UiButton>
        </div>
      </div>

      <UiNotice v-if="notice" tone="success" :data-testid="`${cfg.testid}-detail-notice`">{{ notice }}</UiNotice>
      <UiNotice v-if="failure" tone="danger" :data-testid="`${cfg.testid}-detail-failure`">{{ failure }}</UiNotice>
      <p v-if="memuat" class="py-6 text-center text-body2 text-slate-500">Memuat…</p>

      <template v-if="detail">
        <UiCard title="Status" class="shrink-0">
          <div class="flex flex-wrap items-center gap-3">
            <UiBadge :tone="nadaStepper(detail.nada_status) === 'warning' ? 'warning' : 'info'" size="sm">{{ detail.label_status }}</UiBadge>
          </div>
          <div class="mt-4 overflow-x-auto">
            <UiStepper :steps="tahapKeSteps(detail.tahap)" :tone="nadaStepper(detail.nada_status)" />
          </div>
        </UiCard>

        <UiCard title="Data Pengajuan" class="shrink-0">
          <dl class="grid gap-x-8 gap-y-3 text-body2 md:grid-cols-2" :data-testid="`${cfg.testid}-data`">
            <div><dt class="text-slate-500">NIP</dt><dd class="text-slate-800">{{ detail.nip }}</dd></div>
            <div><dt class="text-slate-500">Nama</dt><dd class="text-slate-800">{{ detail.nama ?? '-' }}</dd></div>
            <div><dt class="text-slate-500">Institusi</dt><dd class="text-slate-800">{{ detail.institusi_pendidikan }}</dd></div>
            <div><dt class="text-slate-500">Jurusan</dt><dd class="text-slate-800">{{ detail.jurusan_pendidikan }}</dd></div>
            <div><dt class="text-slate-500">Lokasi Pendidikan</dt><dd class="text-slate-800">{{ detail.lokasi_pendidikan }}</dd></div>
            <div><dt class="text-slate-500">Lembaga Pendana</dt><dd class="text-slate-800">{{ detail.lembaga_yang_mendanai }}</dd></div>
            <div><dt class="text-slate-500">Periode</dt><dd class="text-slate-800">{{ tanggal(detail.tgl_mulai) }} – {{ tanggal(detail.tgl_akhir) }}</dd></div>
            <div><dt class="text-slate-500">Unit Pendidikan</dt><dd class="text-slate-800">{{ detail.up }}</dd></div>
            <div v-if="detail.sk.no_sk"><dt class="text-slate-500">No. SK</dt><dd class="text-slate-800">{{ detail.sk.no_sk }} ({{ tanggal(detail.sk.tgl_sk ?? '') }})</dd></div>
            <div v-if="detail.reason_note"><dt class="text-slate-500">Alasan</dt><dd class="text-slate-800">{{ detail.reason_note }}</dd></div>
          </dl>
        </UiCard>

        <UiCard title="Berkas Lampiran" flush class="shrink-0">
          <div v-if="detail.lampiran.length === 0" class="p-5 text-body2 text-slate-500" :data-testid="`${cfg.testid}-lampiran-kosong`">Belum ada berkas.</div>
          <ul v-else class="divide-y divide-slate-100">
            <li v-for="l in detail.lampiran" :key="l.id_attachment" class="flex items-center justify-between gap-3 px-5 py-3 text-body2">
              <span class="text-slate-700">{{ labelSlot(l.tag) }}<span class="text-slate-400"> · {{ l.display_name ?? '' }}</span></span>
              <RowActionsMenu
                :actions="AKSI_LAMPIRAN"
                :label="`Aksi untuk berkas ${labelSlot(l.tag)}`"
                :testid="`${cfg.testid}-lampiran-actions-${l.id_attachment}`"
                @select="unduhLampiran(l.id_attachment, l.tag)"
              />
            </li>
          </ul>
        </UiCard>

        <UiCard v-if="cfg.perpanjanganDiizinkan" title="Perpanjangan" flush class="shrink-0">
          <template #actions>
            <UiButton v-if="bolehPerpanjangan" variant="secondary" appearance="outline" :data-testid="`${cfg.testid}-perpanjangan-tambah`" @click="perpan = { open: true, row: null }">
              <Plus class="h-4 w-4" aria-hidden="true" /> Tambah
            </UiButton>
          </template>
          <div v-if="detail.perpanjangan.length === 0" class="p-5 text-body2 text-slate-500">Belum ada perpanjangan.</div>
          <ul v-else class="divide-y divide-slate-100">
            <li v-for="p in detail.perpanjangan" :key="p.id" class="flex items-center justify-between gap-3 px-5 py-3 text-body2" :data-testid="`${cfg.testid}-perpan-${p.id}`">
              <span class="text-slate-700">{{ tanggal(p.tgl_mulai_perpan) }} – {{ tanggal(p.tgl_akhir_perpan) }}</span>
              <RowActionsMenu :actions="aksiPerpan()" :label="`Aksi untuk perpanjangan ${tanggal(p.tgl_akhir_perpan)}`" :testid="`${cfg.testid}-perpan-actions-${p.id}`" @select="onPerpanAksi(p, $event)" />
            </li>
          </ul>
        </UiCard>
      </template>

      <StudiFormDialog v-if="detail" v-model:open="formOpen" :row="detail" :cfg="cfg" :service="service" @saved="onSelesai('Data diperbarui.')" />
      <StudiProsesDialog
        v-if="detail"
        v-model:open="proses.open"
        :row="detail"
        :aksi="proses.aksi"
        :cfg="cfg"
        :service="service"
        @saved="onSelesai(proses.aksi === 'setujui' ? 'Pengajuan disetujui.' : 'Pengajuan ditolak.')"
      />
      <StudiPerpanjanganDialog
        v-if="detail"
        v-model:open="perpan.open"
        :induk="detail"
        :row="perpan.row"
        :service="service"
        @saved="onSelesai('Perpanjangan disimpan.')"
      />
      <ConfirmDialog
        :open="hapus.open"
        title="Hapus Perpanjangan"
        description="Hapus perpanjangan ini? Berkasnya dihapus permanen dan tercatat di audit."
        confirm-label="Ya, hapus"
        danger
        :loading="hapus.loading"
        @update:open="(v) => !hapus.loading && (hapus.open = v)"
        @confirm="konfirmasiHapus"
      />
      <ConfirmDialog
        :open="hapusInduk"
        :title="`Hapus ${cfg.judul}`"
        description="Hapus pengajuan ini beserta berkasnya? Data dihapus permanen dan tercatat di audit."
        confirm-label="Ya, hapus"
        danger
        @update:open="(v) => (hapusInduk = v)"
        @confirm="hapusPengajuan"
      />
    </div>
  </RedesignShell>
</template>
