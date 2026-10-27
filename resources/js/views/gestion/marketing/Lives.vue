<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Icon from '../../../components/Icon.vue'
import LiveLink from '../../../components/LiveLink.vue'
import LiveTraining from '../../../components/LiveTraining.vue'
import LiveWhatsApp from '../../../components/LiveWhatsApp.vue'
import MaterielTip from '../../../components/MaterielTip.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

// Vendre en live (menu Ventes) : on prépare les articles (code, prix du live, pièces), puis l'écran du live suit les réservations
const router = useRouter()
const lives = ref(null)
const pageUrl = ref('')
const whatsapp = ref(null)
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
    whatsapp.value = data.meta.whatsapp
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const scrollTop = () => window.scrollTo({ top: 0, behavior: 'smooth' })

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
    router.push(`/gestion/live/${data.data.id}`)
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
    <Alert v-if="error">{{ error }}</Alert>
    <MaterielTip v-if="!editing" category="live" text="Pour des lives nets et bien éclairés :" />

    <template v-if="!editing">
      <section class="card p-5">
        <h2 class="text-xl font-extrabold leading-tight">Vendez en live,<br />sans rien noter</h2>
        <ol class="mt-4 space-y-3 text-[15px]">
          <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-sm font-extrabold text-emerald-800">A1</span>Chaque article reçoit un code</li>
          <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-800"><Icon name="share" :size="17" /></span>Le client réserve par le lien ou sur WhatsApp</li>
          <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-800"><Icon name="check" :size="17" /></span>La commande se crée toute seule</li>
        </ol>
        <details class="mt-3 text-sm text-slate-600">
          <summary class="cursor-pointer font-semibold text-emerald-700">En savoir plus</summary>
          <p class="mt-2">Sur WhatsApp, le client envoie le code (« A2 ») ou le code et le nombre de pièces (« A2 x3 »). Quand un article est épuisé, il passe sur la liste d'attente et récupère la pièce si une réservation est annulée.</p>
        </details>
        <button class="btn-brand mt-4 w-full sm:w-auto" @click="startNew"><Icon name="plus" :size="18" />Préparer un live</button>
      </section>

      <!-- Pas de WhatsApp relié : le proposer ici, avant de préparer le live -->
      <LiveWhatsApp v-if="lives" :status="whatsapp" @linked="load" />

      <p v-if="lives && !lives.length" class="rounded-2xl bg-ocre-50 p-4 text-sm text-ocre-800">
        Premier live ? Regardez d'abord le module « Préparer votre live » (1 minute), plus bas dans « Se former au live ».
      </p>

      <LiveLink v-if="pageUrl" :url="pageUrl" compact />

      <h3 v-if="lives?.length" class="px-0.5 pt-1 text-[17px] font-extrabold">Vos lives</h3>
      <p v-if="lives && !lives.length" class="card p-6 text-sm text-slate-500">Aucun live pour l'instant.</p>
      <RouterLink v-for="l in lives ?? []" :key="l.id" :to="`/gestion/live/${l.id}`" class="card flex items-center gap-3 p-4 text-sm hover:border-emerald-600">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl" :class="l.status === 'live' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-600'"><Icon name="live" :size="20" /></span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-[15px] font-bold">{{ l.title }}</span>
          <span class="block text-slate-500">{{ l.items_count }} article(s)<template v-if="l.started_at"> · {{ formatDateTime(l.started_at) }}</template><template v-if="l.summary"> · {{ l.summary.orders }} commande(s) · {{ money(l.summary.amount) }}</template></span>
        </span>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="STATUS[l.status][1]"><template v-if="l.status === 'live'">● </template>{{ STATUS[l.status][0] }}</span>
      </RouterLink>

      <LiveTraining :url="pageUrl" @prepare="startNew(); scrollTop()" />
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
          <span class="min-w-0 flex-1 basis-[calc(100%-6.5rem)] font-medium [overflow-wrap:anywhere] sm:basis-0">{{ i.title }}<span class="block text-xs font-normal text-slate-500">{{ i.available }} en stock</span></span>
          <button type="button" class="btn-ghost order-last px-2 text-red-600 max-sm:order-none" aria-label="Retirer" @click="form.items.splice(index, 1)">✕</button>
          <label class="flex items-center gap-1">Prix <input v-model="i.price" type="number" min="0" class="input w-28" /></label>
          <label class="flex items-center gap-1">Pièces <input v-model="i.quantity" type="number" min="1" class="input w-20" /></label>
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
