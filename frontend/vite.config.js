import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Для локальной разработки: проксируем /api на локальный бэкенд (php artisan serve),
// чтобы работать без CORS. В проде фронт и /api отдаёт один и тот же nginx.
export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY || 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
