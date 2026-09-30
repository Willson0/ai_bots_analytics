import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Приложение живёт на поддомене под префиксом /statistics/
// (api.gptbackend.ru/statistics/), поэтому base = '/statistics/'.
export default defineConfig({
  base: '/statistics/',
  plugins: [vue()],
  server: {
    proxy: {
      // dev: /statistics/api/* -> бэкенд /api/*
      '/statistics/api': {
        target: process.env.VITE_API_PROXY || 'http://localhost:8000',
        changeOrigin: true,
        rewrite: (p) => p.replace(/^\/statistics/, ''),
      },
    },
  },
})
