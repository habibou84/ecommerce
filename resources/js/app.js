import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { listenToNotificationTaps } from './lib/push'
import { noAutofill } from './lib/noAutofill'
import { tabStrip } from './lib/tabStrip'

listenToNotificationTaps()

createApp(App).use(createPinia()).use(router).directive('tab-strip', tabStrip).directive('no-autofill', noAutofill).mount('#app')
