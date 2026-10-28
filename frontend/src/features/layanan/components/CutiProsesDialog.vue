<script setup lang="ts">
/**
 * Dialog proses cuti (PRD §6.11.1): membungkus `ApprovalDialog` untuk Setujui/Tolak dan `TangguhkanDialog` untuk
 * Tangguhkan, tanpa mengubah keduanya. `atasNama` (Super Admin memproses atas nama approver yang tak terjangkau):
 * server mewajibkan `reason_note` untuk SEMUA aksi, jadi alasan kosong ditolak di sini sebelum dikirim.
 * Emit `confirm` = body `POST cuti/{id}/process`. Pemanggil menutup dialog setelah request berhasil.
 */
import { computed, ref, watch } from 'vue'

import ApprovalDialog from '@/shared/components/ApprovalDialog.vue'
import type { ApprovalPayload } from '@/shared/components/approvalActions'

import type { AksiProsesCuti, CutiProsesPayload } from '../types'

import TangguhkanDialog from './TangguhkanDialog.vue'

const props = withDefaults(
  defineProps<{ open: boolean; aksi: AksiProsesCuti; subject?: string; atasNama?: boolean; loading?: boolean; error?: string }>(),
  { subject: '', atasNama: false, loading: false, error: '' },
)

const emit = defineEmits<{ 'update:open': [value: boolean]; confirm: [payload: CutiProsesPayload] }>()

const lokalError = ref('')
watch(
  () => props.open,
  () => (lokalError.value = ''),
)

const pesan = computed(() => lokalError.value || props.error)
const deskripsi = computed(() => (props.atasNama ? 'Anda memproses atas nama approver; alasan wajib diisi dan dicatat.' : ''))

function kirim(aksi: AksiProsesCuti, reason: string): void {
  const reason_note = reason.trim()
  if (props.atasNama && reason_note === '') {
    lokalError.value = 'Alasan wajib diisi saat memproses atas nama.'
    return
  }
  lokalError.value = ''
  emit('confirm', { aksi, reason_note, ...(props.atasNama ? { atas_nama: true } : {}) })
}

function onApproval(payload: ApprovalPayload): void {
  kirim(payload.aksi, payload.reason_note)
}
</script>

<template>
  <TangguhkanDialog
    v-if="aksi === 'tangguhkan'"
    :open="open"
    :subject="subject"
    :alasan-wajib="atasNama"
    :loading="loading"
    :error="pesan"
    @update:open="emit('update:open', $event)"
    @confirm="kirim('tangguhkan', $event.reason_note)"
  />
  <ApprovalDialog
    v-else
    :open="open"
    :mode="aksi"
    :subject="subject"
    :description="deskripsi"
    :loading="loading"
    :error="pesan"
    @update:open="emit('update:open', $event)"
    @confirm="onApproval"
  />
</template>
