/**
 * Apakah viewport ≥ breakpoint desktop (`lg` Tailwind = 1024px) — satu sumber kebenaran untuk perilaku yang hanya berlaku
 * di desktop (mis. sidebar mode ciut, CR-060 butir I). Mendengarkan perubahan `matchMedia`; aman bila `matchMedia` tidak
 * ada (test jsdom/SSR) → dianggap desktop supaya perilaku desktop tetap teruji.
 */
import { onBeforeUnmount, ref, type Ref } from 'vue'

export const DESKTOP_QUERY = '(min-width: 1024px)'

export function useIsDesktop(query: string = DESKTOP_QUERY): Ref<boolean> {
  const media = typeof window !== 'undefined' && typeof window.matchMedia === 'function' ? window.matchMedia(query) : null
  const isDesktop = ref(media ? media.matches : true)

  if (media) {
    const onChange = (event: MediaQueryListEvent): void => {
      isDesktop.value = event.matches
    }
    if (typeof media.addEventListener === 'function') {
      media.addEventListener('change', onChange)
      onBeforeUnmount(() => media.removeEventListener('change', onChange))
    } else {
      // Safari lama.
      media.addListener(onChange)
      onBeforeUnmount(() => media.removeListener(onChange))
    }
  }

  return isDesktop
}
