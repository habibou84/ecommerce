<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage } from '../../../lib/http'

// Textes des messages au client : chacun modifiable, avec des mots remplacés par la commande et un aperçu
const messages = ref([])
const placeholders = ref([])
const error = ref('')
const saved = ref(false)
const busy = ref(false)

function fill(data) {
  messages.value = data.messages.map((m) => ({ ...m, draft: m.text }))
  placeholders.value = data.placeholders
}

onMounted(async () => {
  try {
    fill((await http.get('/message-texts')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

let timers = {}
function changed(m) {
  saved.value = false
  clearTimeout(timers[m.key])
  timers[m.key] = setTimeout(async () => {
    try {
      m.preview = (await http.post('/message-texts/preview', { key: m.key, text: m.draft || m.default })).data.data.preview
    } catch {
      // aperçu indisponible : le texte reste modifiable
    }
  }, 400)
}

function insert(m, key) {
  m.draft = `${m.draft}{${key}}`
  changed(m)
}

function reset(m) {
  m.draft = m.default
  changed(m)
}

async function save() {
  busy.value = true
  error.value = ''
  saved.value = false
  try {
    fill((await http.put('/message-texts', { texts: Object.fromEntries(messages.value.map((m) => [m.key, m.draft === m.default ? null : m.draft])) })).data.data)
    saved.value = true
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-slate-600">
      Adaptez à votre façon de parler les messages envoyés à vos clients depuis votre WhatsApp (boutons de la commande) et la demande de confirmation automatique.
      Les mots entre accolades sont remplacés par les informations de la commande ; une ligne dont l'information est vide (ex. rien à payer) disparaît.
      Les modèles WhatsApp de Meta (messages automatiques hors conversation) ne se modifient pas ici : Meta doit les approuver.
    </p>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="saved" tone="success">Textes enregistrés.</Alert>

    <section v-for="m in messages" :key="m.key" class="card grid gap-4 p-4 text-sm lg:grid-cols-2">
      <div class="space-y-2">
        <div class="flex items-center gap-2">
          <h3 class="flex-1 font-semibold">{{ m.label }}</h3>
          <button v-if="m.draft !== m.default" type="button" class="text-xs text-slate-500 underline" @click="reset(m)">Texte d'origine</button>
        </div>
        <textarea v-model="m.draft" rows="7" maxlength="1500" class="input font-mono text-xs" @input="changed(m)"></textarea>
        <div class="flex flex-wrap gap-1">
          <button v-for="p in placeholders" :key="p.key" type="button" class="rounded-full border border-slate-200 px-2 py-0.5 text-xs" :title="p.label" @click="insert(m, p.key)">{{ '{' + p.key + '}' }}</button>
        </div>
      </div>
      <div>
        <p class="mb-1 text-xs text-slate-500">Aperçu</p>
        <p class="whitespace-pre-line rounded-lg bg-emerald-50 p-3">{{ m.preview }}</p>
      </div>
    </section>
    <button class="btn-brand" :disabled="busy" @click="save">Enregistrer</button>
  </div>
</template>
