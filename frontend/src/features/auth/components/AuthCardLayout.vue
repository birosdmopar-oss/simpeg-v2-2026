<script setup lang="ts">
/**
 * Kerangka halaman tamu Modul A (login, lupa password, reset password) — CR-190, Figma SIMPEG V5 "Liquid Glass" A-11:
 * foto latar penuh + overlay putih lembut + cahaya di belakang kartu, lalu kartu glass 460 px (radius 30) berisi
 * blok brand (lockup logo Kemenpar | Wonderful Indonesia, wordmark SIMPEG), pembatas, isi halaman dari slot, dan
 * footer sertifikat elektronik BSrE.
 *
 * - Font Inter (self-host, @fontsource-variable/inter) hanya berlaku di dalam layout ini (`font-v5`).
 * - Foto latar: `<picture>` AVIF → WebP → JPG; layar ≤ 1024 px memakai WebP 960 px. Foto Gunung Bromo dari Unsplash
 *   (1k7YRkRaXc0) masih aset sementara sampai ada foto resmi.
 * - Kaca: isi putih 72% (surface/card), `backdrop-filter: blur(44px) saturate(1.2)` (+ prefiks -webkit-). Fallback isi putih solid + border nyata bila
 *   backdrop-filter tidak didukung, pengguna meminta transparansi dikurangi, atau mode kontras paksa aktif.
 * - Lebar < 480 px: kartu selebar layar dengan margin 16 px; footer BSrE dirapatkan (logo 73 px, teks 12 px) agar
 *   teks sertifikat tidak terlipat.
 */
import '@fontsource-variable/inter'

import bgJpg from '@/assets/auth/login-bg.jpg'
import bgAvif from '@/assets/auth/login-bg.avif'
import bgWebp from '@/assets/auth/login-bg.webp'
import bgWebp960 from '@/assets/auth/login-bg-960.webp'
import logoBsre from '@/assets/auth/logo-bsre.png'
import logoKemenpar from '@/assets/auth/logo-kemenpar-128.png'
import logoKemenpar256 from '@/assets/auth/logo-kemenpar-256.png'
import logoWonderful from '@/assets/auth/logo-wonderful-indonesia.png'
</script>

<template>
  <main
    class="relative isolate min-h-screen overflow-hidden bg-v5-page font-v5 text-v5-text antialiased"
    data-testid="auth-layout"
  >
    <div class="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
      <picture class="block h-full w-full" data-testid="auth-bg">
        <source media="(max-width: 1024px)" :srcset="bgWebp960" type="image/webp" />
        <source :srcset="bgAvif" type="image/avif" />
        <source :srcset="bgWebp" type="image/webp" />
        <img :src="bgJpg" alt="" class="h-full w-full object-cover" decoding="async" fetchpriority="high" />
      </picture>
      <div class="auth-overlay absolute inset-0" />
      <div class="auth-light absolute left-1/2 top-1/2 h-[820px] w-[900px] -translate-x-1/2 -translate-y-1/2" />
    </div>

    <div class="flex min-h-screen items-center justify-center px-4 py-10">
      <section
        class="auth-glass w-full max-w-[460px] overflow-hidden rounded-v5-2xl"
        data-testid="auth-card"
      >
        <div class="flex flex-col gap-6 px-6 pb-7 pt-9 min-[480px]:px-10">
          <header class="flex flex-col items-center gap-4 text-center">
            <div class="flex items-center gap-5">
              <img
                :src="logoKemenpar"
                :srcset="`${logoKemenpar} 2x, ${logoKemenpar256} 4x`"
                alt="Logo Kementerian Pariwisata Republik Indonesia"
                class="h-16 w-16 object-contain"
                width="64"
                height="64"
              />
              <span class="h-11 w-px bg-v5-border" aria-hidden="true" />
              <img
                :src="logoWonderful"
                alt="Logo Wonderful Indonesia"
                class="h-14 w-[140px] object-contain"
                width="140"
                height="56"
              />
            </div>
            <div class="flex flex-col items-center gap-1">
              <h1 class="text-[30px] font-bold leading-9 tracking-[-0.9px] text-v5-primary">SIMPEG</h1>
              <p class="text-[11px] font-bold uppercase leading-4 tracking-[0.88px] text-v5-text-muted">
                Sistem Informasi Kepegawaian
              </p>
              <p class="text-[13px] leading-5 tracking-[-0.05px] text-v5-text-muted">
                Kementerian Pariwisata Republik Indonesia
              </p>
            </div>
          </header>

          <div class="h-px w-full bg-v5-border-subtle" role="presentation" />

          <slot />
        </div>

        <footer
          class="flex items-center justify-center gap-2 border-t border-v5-border-subtle bg-white px-4 py-4 min-[480px]:gap-3 min-[480px]:px-10"
          data-testid="auth-footer"
        >
          <img
            :src="logoBsre"
            alt="Logo Balai Besar Sertifikasi Elektronik"
            class="h-6 w-[73px] shrink-0 object-contain min-[480px]:h-8 min-[480px]:w-[97px]"
            width="97"
            height="32"
          />
          <span class="h-7 w-px shrink-0 bg-v5-border" aria-hidden="true" />
          <div class="min-w-0 text-left">
            <p class="text-[12px] font-medium leading-5 tracking-[-0.05px] text-v5-text min-[480px]:text-[13px]">Tersertifikasi Sertifikat Elektronik</p>
            <p class="text-[11px] leading-4 tracking-[0.05px] text-v5-text-muted">Badan Siber dan Sandi Negara (BSSN)</p>
          </div>
        </footer>
      </section>
    </div>
  </main>
</template>

<style scoped>
.auth-overlay {
  opacity: 0.45;
  background-image:
    linear-gradient(180deg, rgb(255 255 255 / 0.15) 0%, rgb(247 248 250 / 0.55) 60%, rgb(247 248 250 / 0.92) 100%),
    linear-gradient(90deg, rgb(255 255 255 / 0.38) 0%, rgb(255 255 255 / 0.38) 100%);
}

.auth-light {
  max-width: 100vw;
  background: radial-gradient(closest-side, rgb(255 255 255 / 0.55), rgb(255 255 255 / 0) 100%);
}

/* V5 Liquid Glass Card (node Figma 2130:65125): fill surface/card putih 72%, rim putih 75%, blur 44. */
.auth-glass {
  background: rgb(255 255 255 / 0.72);
  border: 1px solid rgb(255 255 255 / 0.75);
  box-shadow:
    inset 0 1px 0 0 rgb(255 255 255 / 0.95),
    0 30px 80px -16px rgb(28 33 64 / 0.28),
    0 2px 8px 0 rgb(28 33 64 / 0.06);
  -webkit-backdrop-filter: blur(44px) saturate(1.2);
  backdrop-filter: blur(44px) saturate(1.2);
}

/* Fallback: tanpa backdrop-filter, transparansi dikurangi, atau kontras paksa → isi solid + border nyata. */
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
  .auth-glass {
    background: #ffffff;
    border-color: rgb(60 60 67 / 0.16);
  }
}

@media (prefers-reduced-transparency: reduce) {
  .auth-glass {
    background: #ffffff;
    border-color: rgb(60 60 67 / 0.16);
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
  }

  .auth-overlay {
    opacity: 0.85;
  }
}

@media (forced-colors: active) {
  .auth-glass {
    background: Canvas;
    border: 1px solid CanvasText;
    -webkit-backdrop-filter: none;
    backdrop-filter: none;
  }
}
</style>
