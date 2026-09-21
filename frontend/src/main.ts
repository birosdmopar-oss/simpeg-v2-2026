import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import './assets/main.css'
import { useAuthStore } from './features/auth/stores/auth.store'
import { setAuthFailureHandler } from './lib/axios'
import router from './router'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Refresh token gagal (sesi habis / dicabut): kosongkan store lalu ke halaman login (F0-09 + A-11).
setAuthFailureHandler(() => {
  useAuthStore(pinia).clearSession()
  const current = router.currentRoute.value
  if (current.name !== 'login') {
    void router.push({ name: 'login', query: current.fullPath !== '/' ? { redirect: current.fullPath } : {} })
  }
})

app.mount('#app')
