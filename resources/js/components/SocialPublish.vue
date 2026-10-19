<script setup>
import NetworkMark from './NetworkMark.vue'
import Icon from './Icon.vue'
import { computed, onMounted, ref } from 'vue'
import Alert from './Alert.vue'
import Modal from './Modal.vue'
import http, { errorMessage } from '../lib/http'

// Publier un produit sur Facebook, Instagram et TikTok : textes proposés par l'IA, visuels faits par l'application,
// tout de suite ou à l'heure choisie
const props = defineProps({ product: { type: Object, required: true } })
const emit = defineEmits(['close'])

const accounts = ref(null)
const networks = ref([])
const caption = ref(`${props.product.title} 😍\nCommandez ici : {lien}`)
const tone = ref('simple')
const proposals = ref([])
const when = ref('now')
const scheduledAt = ref('')
const busy = ref(false)
const error = ref('')
const result = ref(null)

const LABELS = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok' }

onMounted(async () => {
  try {
    accounts.value = (await http.get('/social')).data.accounts.filter((a) => a.connected)
    networks.value = accounts.value.map((a) => a.network)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function propose() {
  busy.value = true
  error.value = ''
  try {
    proposals.value = (await http.post(`/products/${props.product.id}/social/captions`, { tone: tone.value })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
const use = (p) => (caption.value = [p.text, p.hashtags.join(' ')].filter(Boolean).join('\n\n'))

const minDate = computed(() => new Date(Date.now() + 5 * 60000 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16))

async function publish() {
  busy.value = true
  error.value = ''
  try {
    result.value = (await http.post(`/products/${props.product.id}/social/posts`, {
      caption: caption.value, networks: networks.value, scheduled_at: when.value === 'later' ? scheduledAt.value : null,
    })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal title="Publier sur les réseaux" @close="emit('close')">
    <div v-if="result" class="space-y-3 text-sm">
      <Alert v-if="result.status === 'scheduled'" tone="success">Publication programmée ✓ Elle partira toute seule à l'heure choisie.</Alert>
      <template v-else>
        <p v-for="(r, n) in result.results" :key="n" class="flex items-center gap-2">
          <span class="inline-flex items-center gap-1.5"><NetworkMark :network="n" />{{ LABELS[n] }}</span>
          <span v-if="r.status === 'published'" class="text-emerald-700">publié ✓ <a v-if="r.url" :href="r.url" target="_blank" rel="noopener" class="underline">voir</a></span>
          <span v-else class="text-red-700">échec : {{ r.error }}</span>
        </p>
        <p v-if="!Object.keys(result.results).length" class="text-slate-600">Publication en cours… le résultat apparaît dans Marketing › Réseaux sociaux.</p>
      </template>
      <div class="grid grid-cols-2 gap-2">
        <RouterLink to="/gestion/marketing/reseaux" class="btn-secondary">Voir les publications</RouterLink>
        <button class="btn-brand" @click="emit('close')">Fermer</button>
      </div>
    </div>

    <form v-else class="space-y-4 text-sm" @submit.prevent="publish">
      <Alert v-if="error">{{ error }}</Alert>
      <div v-if="accounts && !accounts.length" class="rounded-lg bg-amber-50 p-3 text-amber-900">
        Aucun réseau connecté. <RouterLink to="/gestion/marketing/reseaux" class="font-medium underline">Connecter Facebook, Instagram ou TikTok</RouterLink>
      </div>
      <div v-else-if="accounts">
        <p class="label">Publier sur</p>
        <div class="flex flex-wrap gap-2">
          <label v-for="a in accounts" :key="a.network" class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2"
                 :class="networks.includes(a.network) ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
            <input v-model="networks" type="checkbox" :value="a.network" class="accent-emerald-700" />
            <NetworkMark :network="a.network" class="mr-1" />{{ a.label }}
          </label>
        </div>
      </div>

      <div class="space-y-2 rounded-lg border border-slate-200 p-3">
        <div class="flex flex-wrap items-center gap-2">
          <p class="font-medium"><Icon name="sparkles" :size="16" class="mr-1 inline-block align-[-3px]" />Textes proposés</p>
          <select v-model="tone" class="input ml-auto w-auto py-1">
            <option value="simple">Français simple</option>
            <option value="nouchi">Nouchi léger</option>
          </select>
          <button type="button" class="btn-secondary px-3 py-1" :disabled="busy" @click="propose">{{ busy && !proposals.length ? 'Rédaction…' : 'Proposer' }}</button>
        </div>
        <button v-for="(p, i) in proposals" :key="i" type="button" class="block w-full whitespace-pre-line rounded-lg border border-slate-200 p-2 text-left hover:border-emerald-600" @click="use(p)">
          {{ p.text }}<span class="block text-sky-700">{{ p.hashtags.join(' ') }}</span>
        </button>
      </div>

      <div>
        <label for="sp-caption" class="label">Texte de la publication</label>
        <textarea id="sp-caption" v-model="caption" rows="6" class="input" maxlength="2000" required />
        <p class="mt-1 text-xs text-slate-500">« {lien} » est remplacé par le lien de commande, différent pour chaque réseau : vous saurez d'où viennent les commandes.</p>
      </div>

      <p class="rounded-lg bg-slate-50 p-2 text-xs text-slate-600"><Icon name="image" :size="16" class="mr-1 inline-block align-[-3px]" />L'application crée les visuels avec la photo, le prix du moment et le nom de la boutique : carré pour Facebook et Instagram, vertical pour TikTok.</p>

      <div class="space-y-2">
        <label class="flex items-center gap-2"><input v-model="when" type="radio" value="now" class="accent-emerald-700" /> Publier maintenant</label>
        <label class="flex items-center gap-2"><input v-model="when" type="radio" value="later" class="accent-emerald-700" /> Programmer</label>
        <input v-if="when === 'later'" v-model="scheduledAt" type="datetime-local" :min="minDate" class="input" required />
      </div>

      <button class="btn-brand w-full" :disabled="busy || !networks.length">{{ busy ? 'Envoi…' : when === 'later' ? 'Programmer la publication' : 'Publier' }}</button>
    </form>
  </Modal>
</template>
