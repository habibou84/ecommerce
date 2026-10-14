import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { listenToNotificationTaps } from './lib/push'

listenToNotificationTaps()

createApp(App).use(createPinia()).use(router).mount('#app')
