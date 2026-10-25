<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

// Ventes en live : on prépare les articles (code, prix du live, pièces), puis l'écran du live suit les réservations
const router = useRouter()
const lives = ref(null)
const pageUrl = ref('')
const error = ref('')
const errors = ref({})
const busy = ref(false)
const editing = ref(false)
const form = reactive({ title: '', hold_minutes: 120, items: [] })
const search = ref('')
const results = ref([])
let searchTimer

const STATUS = { draft: ['En préparation', 'bg-slate-100 text-slate-700'], live: ['En direct', 'bg-red-100 text-red-700'], ended: ['Terminé', 'bg-emerald-100 text-emerald-800'] }

async function load() {
  try {
    const { data } = await http.get('/lives')
    lives.value = data.data
    pageUrl.value = data.meta.page_url
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function startNew() {
  Object.assign(form, { title: `Live du ${new Date().toLocaleDateString('fr-FR')}`, hold_minutes: 120, items: [] })
  errors.value = {}
  editing.value = true
}

function find() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    results.value = (await http.get('/lives/variants', { params: { search: search.value || undefined } }).catch(() => ({ data: { data: [] } }))).data.data
  }, 250)
}

function add(v) {
  if (form.items.some((i) => i.variant_id === v.id)) return
  form.items.push({ variant_id: v.id, title: v.title, image: v.image, price: v.price, quantity: Math.max(1, Math.min(v.available || 1, 10)), available: v.available })
}

// A1…A9, B1… : même règle que le serveur
const code = (i) => String.fromCharCode(65 + (Math.floor(i / 9) % 26)) + ((i % 9) + 1)

async function save() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const { data } = await http.post('/lives', {
      title: form.title, hold_minutes: Number(form.hold_minutes),
      items: form.items.map((i) => ({ variant_id: i.variant_id, price: Number(i.price), quantity: Number(i.quantity) })),
    })
    router.push(`/gestion/marketing/live/${data.data.id}`)
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <MarketingTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="!editing">
      <section class="card space-y-2 p-4 text-sm">
        <p class="text-slate-700">
          Pendant un live Facebook, TikTok ou Instagram, chaque article a un code (A1, A2…). Vos clients réservent de deux façons :
          sur la <strong>page du live</strong> (lien à mettre dans votre bio, toujours le même) ou en envoyant le <strong>code sur WhatsApp</strong>.
          Quand c'est épuisé, ils passent sur la liste d'attente.
        </p>
        <p v-if="pageUrl" class="flex flex-wrap items-center gap-2"><span class="text-slate-500">Page du live :</span><a :href="pageUrl" target="_blank" rel="noopener" class="font-medium text-emerald-700 underline">{{ pageUrl }}</a></p>
        <button class="btn-brand" @click="startNew">+ Préparer un live</button>
      </section>

      <p v-if="lives && !lives.length" class="card p-6 text-sm text-slate-500">Aucun live pour l'instant.</p>
      <RouterLink v-for="l in lives ?? []" :key="l.id" :to="`/gestion/marketing/live/${l.id}`" class="card flex flex-wrap items-center justify-between gap-2 p-4 text-sm hover:bg-slate-50">
        <span>
          <span class="font-semibold">{{ l.title }}</span>
          <span class="ml-2 rounded-full px-2 py-0.5 text-xs" :class="STATUS[l.status][1]">{{ STATUS[l.status][0] }}</span>
          <span class="block text-slate-500">{{ l.items_count }} article(s)<template v-if="l.started_at"> · {{ formatDateTime(l.started_at) }}</template></span>
        </span>
        <span v-if="l.summary" class="text-slate-600">{{ l.summary.orders }} commande(s) · {{ money(l.summary.amount) }}</span>
      </RouterLink>
    </template>

    <form v-else class="space-y-4" @submit.prevent="save">
      <section class="card grid gap-3 p-4 sm:grid-cols-2">
        <Field id="live-title" label="Nom du live" :error="errors.title"><input id="live-title" v-model="form.title" class="input" required maxlength="120" /></Field>
        <Field id="live-hold" label="Pièce gardée sans quartier pendant (minutes)" :error="errors.hold_minutes" hint="Réservation WhatsApp : si le client ne donne pas son quartier à temps, la pièce va au suivant.">
          <input id="live-hold" v-model="form.hold_minutes" type="number" min="10" max="1440" class="input" />
        </Field>
      </section>

      <section class="card space-y-3 p-4 text-sm">
        <h3 class="font-semibold">Articles du live</h3>
        <p v-if="!form.items.length" class="text-slate-500">Cherchez vos articles ci-dessous et ajoutez-les dans l'ordre où vous les présenterez.</p>
        <div v-for="(i, index) in form.items" :key="i.variant_id" class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 p-2">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-red-600 font-bold text-white">{{ code(index) }}</span>
          <span class="min-w-0 flex-1 font-medium">{{ i.title }}<span class="block text-xs font-normal text-slate-500">{{ i.available }} en stock</span></span>
          <label class="flex items-center gap-1">Prix <input v-model="i.price" type="number" min="0" class="input w-28" /></label>
          <label class="flex items-center gap-1">Pièces <input v-model="i.quantity" type="number" min="1" class="input w-20" /></label>
          <button type="button" class="btn-ghost px-2 text-red-600" aria-label="Retirer" @click="form.items.splice(index, 1)">✕</button>
        </div>
        <p v-if="errors.items" class="text-red-600">{{ errors.items }}</p>
        <input v-model="search" class="input" placeholder="Chercher un article (nom ou référence)" @input="find" @focus="find" />
        <div v-if="results.length" class="max-h-72 divide-y divide-slate-100 overflow-auto rounded-lg border border-slate-200">
          <button v-for="v in results" :key="v.id" type="button" class="flex w-full items-center gap-3 p-2 text-left hover:bg-slate-50" @click="add(v)">
            <img v-if="v.image" :src="v.image" alt="" class="h-9 w-9 rounded object-cover" />
            <span class="flex-1">{{ v.title }}</span>
            <span class="text-slate-500">{{ money(v.price) }} · {{ v.available }} en stock</span>
          </button>
        </div>
      </section>

      <div class="flex gap-2">
        <button type="button" class="btn-ghost" @click="editing = false">Annuler</button>
        <button class="btn-brand" :disabled="busy || !form.items.length">Enregistrer et ouvrir l'écran du live</button>
      </div>
    </form>
  </div>
</template>
