import { createApp } from 'vue'
import App from './App.vue'
import { initTelegram } from './telegram'
import './assets/modernist.css'
import './assets/app.css'

// Инициализация Telegram Mini App (ready/expand/тема). Вне Telegram — no-op.
initTelegram()

createApp(App).mount('#app')
