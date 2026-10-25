import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { listenToInstallPrompt } from './lib/install'
import { listenToNotificationTaps } from './lib/push'
import { watchKeyboard } from './lib/keyboard'
import { noAutofill } from './lib/noAutofill'
import { tabStrip } from './lib/tabStrip'

listenToNotificationTaps()
listenToInstallPrompt()
watchKeyboard()

createApp(App).use(createPinia()).use(router).directive('tab-strip', tabStrip).directive('no-autofill', noAutofill).mount('#app')
