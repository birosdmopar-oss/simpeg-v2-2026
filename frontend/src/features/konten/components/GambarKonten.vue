<script setup lang="ts">
/**
 * Gambar popup/banner yang dilindungi (PRD Fase 7 v2.0 Q7-14): berkas privat diambil lewat klien `api` (cookie httpOnly,
 * bukan `<img src>` langsung yang tidak menjangkau API dari origin FE) sebagai blob, lalu ditampilkan lewat object URL
 * yang dilepas saat komponen dilepas/path berganti. `path` = `gambar_url` dari API (relatif terhadap base API).
 * Selama memuat, `<img>` tetap ada (piksel transparan) agar tata letak tidak melompat; gagal → teks pengganti.
 */
import { onBeforeUnmount, ref, watch } from 'vue'

import { kontenService } from '../services/konten.service'

const props = defineProps<{ path: string; alt: string }>()

const PIKSEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'

const src = ref<string | null>(null)
const gagal = ref(false)
let urlObjek: string | null = null
let token = 0

function lepas(): void {
  if (urlObjek) URL.revokeObjectURL(urlObjek)
  urlObjek = null
}

async function muat(path: string): Promise<void> {
  const saya = ++token
  gagal.value = false
  try {
    const blob = await kontenService.gambarBlob(path)
    if (saya !== token) return
    lepas()
    urlObjek = URL.createObjectURL(blob)
    src.value = urlObjek
  } catch {
    if (saya === token) {
      gagal.value = true
      src.value = null
    }
  }
}

watch(() => props.path, (p) => void muat(p), { immediate: true })
onBeforeUnmount(() => {
  token++
  lepas()
})
</script>

<template>
  <span v-if="gagal" role="img" :aria-label="alt" class="flex min-h-16 items-center justify-center bg-slate-100 text-sm text-slate-500" data-testid="gambar-konten-gagal">
    Gambar tidak tersedia
  </span>
  <img v-else :src="src ?? PIKSEL" :alt="alt" :data-gambar="path" />
</template>
