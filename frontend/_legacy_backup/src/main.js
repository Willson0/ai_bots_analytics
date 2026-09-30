import { createApp } from 'vue'
import './assets/main.css'
import App from './App.vue'
import router from "./router.js";
import store from './storage.js';

const app = createApp(App)

app.use(store).use(router)
app.mount('#app')