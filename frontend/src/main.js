import { createApp } from 'vue'
import App from './App.vue'
import { initTelegram, lockZoom } from './telegram'
import './assets/modernist.css'
import './assets/app.css'

// Инициализация Telegram Mini App (ready/expand/тема). Вне Telegram — no-op.
initTelegram()
// Запрещаем любое масштабирование экрана (pinch / двойной тап / жесты iOS).
lockZoom()

createApp(App).mount('#app')
