<script setup>
import { computed, onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import Modal from '../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { formatDateTime } from '../../lib/format'

// Centre d'aide : les fiches lues par les marchands dans l'application (et bientôt envoyées par le support sur
// WhatsApp), leurs images et leur vidéo, les avis reçus, et les recherches restées sans réponse (fiches à écrire)
const data = ref(null)
const error = ref('')
const tab = ref('articles')
const filter = ref('')

async function load() {
  try {
    data.value = (await http.get('/console/help')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function call(method, url, payload) {
  error.value = ''
  try {
    data.value = (await http[method](url, payload)).data.data
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  }
}

const groups = computed(() => Object.entries(data.value?.categories ?? {})
  .filter(([key]) => !filter.value || filter.value === key)
  .map(([key, label]) => ({ key, label, articles: data.value.articles.filter((a) => a.category === key) }))
  .filter((g) => g.articles.length))
const totals = computed(() => {
  const list = data.value?.articles ?? []
  return {
    published: list.filter((a) => a.is_published).length,
    videos: list.filter((a) => a.has_video).length,
    views: list.reduce((n, a) => n + a.views, 0),
    unhelpful: list.filter((a) => a.unhelpful > a.helpful).length,
  }
})

// Fiche en cours de modification
const article = ref(null)
const errors = ref({})
const busy = ref(false)
const EMPTY = { title: '', category: 'demarrer', summary: '', steps: [], body: '', keywords: '', video_url: '', poster_url: '', video_seconds: '', screen: '', screen_label: '', sort: 0, is_published: true }
function edit(a = null, title = '') {
  errors.value = {}
  article.value = a
    ? { ...EMPTY, ...a, steps: [...(a.steps ?? [])], body: a.body ?? '', keywords: a.keywords ?? '', video_url: a.video_url ?? '', poster_url: a.poster_url ?? '', video_seconds: a.video_seconds ?? '', screen: a.screen ?? '' }
    : { ...EMPTY, title, steps: [''] }
}
const current = computed(() => (article.value?.id ? data.value.articles.find((a) => a.id === article.value.id) : null))
async function save() {
  const a = article.value
  errors.value = {}
  busy.value = true
  const payload = {
    title: a.title, category: a.category, summary: a.summary, steps: a.steps.map((s) => s.trim()).filter(Boolean), body: a.body || null,
    keywords: a.keywords || null, video_url: a.video_url || null, poster_url: a.poster_url || null, video_seconds: a.video_seconds === '' ? null : Number(a.video_seconds),
    screen: a.screen || null, screen_label: a.screen_label || null, sort: Number(a.sort) || 0, is_published: a.is_published,
  }
  try {
    const res = (await http[a.id ? 'put' : 'post'](a.id ? `/console/help/${a.id}` : '/console/help', payload)).data.data
    data.value = res
    if (a.id) {
      article.value = null
    } else {
      // Nouvelle fiche : elle reste ouverte pour y ajouter images et vidéo
      edit(res.articles.reduce((x, y) => (y.id > x.id ? y : x)))
    }
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
async function remove() {
  if (!window.confirm(`Supprimer la fiche « ${article.value.title} » ? Ses images et sa vidéo sont supprimées aussi.`)) return
  if (await call('delete', `/console/help/${article.value.id}`)) article.value = null
}
async function upload(kind, event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  const form = new FormData()
  form.append(kind, file)
  busy.value = true
  await call('post', `/console/help/${article.value.id}/${kind}`, form)
  busy.value = false
}
async function togglePublished(a) {
  await call('put', `/console/help/${a.id}`, { ...a, is_published: !a.is_published })
}
function writeFor(miss) {
  tab.value = 'articles'
  edit(null, miss.query.charAt(0).toUpperCase() + miss.query.slice(1))
}
</script>

<template>
  <div v-if="data" class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-semibold">Centre d'aide</h1>
      <button class="btn-brand ml-auto" @click="edit()">Écrire une fiche</button>
    </div>
    <p class="text-sm text-slate-600">Une fiche = une question de marchand, sa réponse courte, les étapes, une vidéo ou des images, et l'écran à ouvrir. Les marchands les lisent dans l'application (Aide).</p>
    <div class="grid gap-3 sm:grid-cols-4">
      <div class="card p-3"><p class="text-xs text-slate-500">Fiches publiées</p><p class="text-xl font-bold">{{ totals.published }} <span class="text-sm font-normal text-slate-500">sur {{ data.articles.length }}</span></p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Avec une vidéo</p><p class="text-xl font-bold">{{ totals.videos }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Lectures</p><p class="text-xl font-bold">{{ totals.views }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Recherches sans réponse</p><p class="text-xl font-bold" :class="data.misses.length ? 'text-amber-700' : ''">{{ data.misses.length }}</p></div>
    </div>
    <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:w-fit">
      <button v-for="[key, label] in [['articles', 'Fiches'], ['misses', `Recherches sans réponse (${data.misses.length})`]]" :key="key" class="rounded-md px-4 py-1.5" :class="tab === key ? 'bg-white font-semibold shadow-sm' : ''" @click="tab = key">{{ label }}</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Fiches -->
    <section v-if="tab === 'articles'" class="space-y-4">
      <select v-model="filter" class="input w-auto">
        <option value="">Toutes les rubriques</option>
        <option v-for="(label, key) in data.categories" :key="key" :value="key">{{ label }}</option>
      </select>
      <p v-if="!groups.length" class="card p-6 text-center text-sm text-slate-500">Aucune fiche. <button class="font-semibold text-emerald-700 underline" @click="call('post', '/console/help/defaults')">Remettre les fiches de départ</button></p>
      <div v-for="g in groups" :key="g.key" class="space-y-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ g.label }}</h2>
        <article v-for="a in g.articles" :key="a.id" class="card flex flex-wrap items-center gap-x-4 gap-y-2 p-3 text-sm" :class="a.is_published ? '' : 'opacity-60'">
          <div class="min-w-0 flex-1 basis-64">
            <p class="font-semibold">{{ a.title }}</p>
            <p class="line-clamp-2 text-slate-600">{{ a.summary }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
            <span v-if="a.has_video" class="rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-800">▶ Vidéo</span>
            <span v-if="a.images.length" class="rounded-full bg-slate-100 px-2 py-0.5">{{ a.images.length }} image(s)</span>
            <span>{{ a.views }} lecture(s)</span>
            <span :class="a.unhelpful > a.helpful ? 'font-semibold text-amber-700' : ''">👍 {{ a.helpful }} · 👎 {{ a.unhelpful }}</span>
            <span v-if="!a.is_published" class="rounded-full bg-slate-200 px-2 py-0.5 font-semibold">Masquée</span>
          </div>
          <div class="flex gap-2">
            <button class="btn-secondary" @click="edit(a)">Modifier</button>
            <button class="btn-ghost" @click="togglePublished(a)">{{ a.is_published ? 'Masquer' : 'Publier' }}</button>
          </div>
        </article>
      </div>
      <button v-if="groups.length" class="text-sm text-slate-500 underline" @click="call('post', '/console/help/defaults')">Remettre les fiches de départ manquantes</button>
    </section>

    <!-- Recherches sans réponse -->
    <section v-if="tab === 'misses'" class="space-y-2">
      <p class="text-sm text-slate-600">Ce que les marchands ont cherché sans rien trouver. Écrivez la fiche qui manque, ou ajoutez leurs mots dans les mots-clés d'une fiche existante.</p>
      <p v-if="!data.misses.length" class="card p-6 text-center text-sm text-slate-500">Aucune recherche sans réponse.</p>
      <article v-for="m in data.misses" :key="m.id" class="card flex flex-wrap items-center gap-3 p-3 text-sm">
        <p class="min-w-0 flex-1 basis-56 font-medium">« {{ m.query }} »</p>
        <span class="text-xs text-slate-500">{{ m.hits }} fois · {{ formatDateTime(m.last_at) }}</span>
        <button class="btn-secondary" @click="writeFor(m)">Écrire la fiche</button>
        <button class="btn-ghost" @click="call('delete', `/console/help/misses/${m.id}`)">Retirer</button>
      </article>
    </section>

    <!-- Fiche -->
    <Modal v-if="article" :title="article.id ? 'Modifier la fiche' : 'Nouvelle fiche'" wide @close="article = null">
      <form class="space-y-4" @submit.prevent="save">
        <Field id="help-title" label="Question du marchand" :error="errors.title?.[0]">
          <input id="help-title" v-model="article.title" class="input" maxlength="160" placeholder="Comment ajouter un produit ?" required>
        </Field>
        <div class="grid gap-4 sm:grid-cols-2">
          <Field id="help-category" label="Rubrique" :error="errors.category?.[0]">
            <select id="help-category" v-model="article.category" class="input"><option v-for="(label, key) in data.categories" :key="key" :value="key">{{ label }}</option></select>
          </Field>
          <Field id="help-sort" label="Ordre dans la rubrique" :error="errors.sort?.[0]" hint="Le plus petit en premier.">
            <input id="help-sort" v-model="article.sort" type="number" min="0" class="input">
          </Field>
        </div>
        <Field id="help-summary" label="Réponse courte" :error="errors.summary?.[0]" hint="Deux ou trois phrases simples, en tutoyant : c'est la réponse envoyée telle quelle sur WhatsApp.">
          <textarea id="help-summary" v-model="article.summary" class="input" rows="3" maxlength="600" required />
        </Field>
        <div>
          <p class="mb-1 text-sm font-medium">Étapes</p>
          <div v-for="(s, i) in article.steps" :key="i" class="mb-2 flex items-center gap-2">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-800">{{ i + 1 }}</span>
            <input v-model="article.steps[i]" class="input" maxlength="300" placeholder="Ouvre Mes produits et touche « + Produit »">
            <button type="button" class="btn-ghost px-2 text-red-600" aria-label="Retirer l'étape" @click="article.steps.splice(i, 1)">✕</button>
          </div>
          <button v-if="article.steps.length < 12" type="button" class="text-sm font-semibold text-emerald-700" @click="article.steps.push('')">+ Ajouter une étape</button>
          <p v-if="errors.steps" class="text-sm text-red-600">{{ errors.steps[0] }}</p>
        </div>
        <Field id="help-body" label="Détails (facultatif)" :error="errors.body?.[0]" hint="Cas particuliers, astuces. Une ligne vide sépare les paragraphes.">
          <textarea id="help-body" v-model="article.body" class="input" rows="3" maxlength="5000" />
        </Field>
        <Field id="help-keywords" label="Mots-clés" :error="errors.keywords?.[0]" hint="Les mots que les marchands emploient, séparés par des espaces : ils font trouver la fiche.">
          <input id="help-keywords" v-model="article.keywords" class="input" maxlength="500" placeholder="article stock prix taille couleur">
        </Field>
        <div class="grid gap-4 sm:grid-cols-2">
          <Field id="help-screen" label="Écran à ouvrir" :error="errors.screen?.[0]" hint="Adresse dans la gestion, par exemple /gestion/catalogue.">
            <input id="help-screen" v-model="article.screen" class="input" maxlength="160" placeholder="/gestion/catalogue">
          </Field>
          <Field id="help-screen-label" label="Texte du bouton" :error="errors.screen_label?.[0]">
            <input id="help-screen-label" v-model="article.screen_label" class="input" maxlength="60" placeholder="Ouvrir Mes produits">
          </Field>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
          <div class="sm:col-span-2">
            <Field id="help-video" label="Vidéo : adresse" :error="errors.video_url?.[0]" hint="Fichier du site (/videos/etapes/etape-06.mp4) ou lien https. Ou envoyez un fichier plus bas.">
              <input id="help-video" v-model="article.video_url" class="input" maxlength="255" placeholder="/videos/etapes/etape-06.mp4">
            </Field>
          </div>
          <Field id="help-seconds" label="Durée (secondes)" :error="errors.video_seconds?.[0]">
            <input id="help-seconds" v-model="article.video_seconds" type="number" min="1" class="input">
          </Field>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="article.is_published" type="checkbox"> Publiée (visible par les marchands)</label>

        <!-- Images et vidéo : sur une fiche déjà enregistrée -->
        <div v-if="current" class="space-y-3 rounded-xl bg-slate-50 p-3">
          <div>
            <p class="mb-1 text-sm font-medium">Images ({{ current.images.length }} sur {{ data.max_images }})</p>
            <div class="flex flex-wrap gap-2">
              <div v-for="(src, i) in current.images" :key="src" class="relative">
                <img :src="src" alt="" class="h-24 w-16 rounded-lg border border-slate-200 object-cover">
                <button type="button" class="absolute -right-1.5 -top-1.5 grid h-5 w-5 place-items-center rounded-full bg-red-600 text-xs text-white" aria-label="Retirer l'image" @click="call('delete', `/console/help/${current.id}/images/${i}`)">✕</button>
              </div>
              <label v-if="current.images.length < data.max_images" class="grid h-24 w-16 cursor-pointer place-items-center rounded-lg border border-dashed border-slate-300 text-2xl text-slate-400">+
                <input type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="upload('image', $event)">
              </label>
            </div>
            <p class="mt-1 text-xs text-slate-500">Captures d'écran du téléphone, 4 Mo au plus chacune.</p>
          </div>
          <div>
            <p class="mb-1 text-sm font-medium">Vidéo envoyée</p>
            <div v-if="current.video_uploaded" class="flex items-center gap-3">
              <video :src="current.video.url" class="h-24 rounded-lg bg-black" controls preload="metadata" />
              <button type="button" class="btn-ghost text-red-600" @click="call('delete', `/console/help/${current.id}/video`)">Retirer la vidéo</button>
            </div>
            <label v-else class="btn-secondary cursor-pointer">Envoyer une vidéo (MP4, 30 Mo au plus)
              <input type="file" accept="video/mp4" class="hidden" @change="upload('video', $event)">
            </label>
            <p class="mt-1 text-xs text-slate-500">Une vidéo envoyée remplace l'adresse indiquée plus haut.</p>
          </div>
        </div>
        <p v-else class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">Enregistrez la fiche : vous pourrez ensuite y ajouter des images et envoyer une vidéo.</p>

        <div class="flex flex-wrap items-center gap-2">
          <button class="btn-brand" :disabled="busy">{{ busy ? 'Un instant…' : 'Enregistrer' }}</button>
          <button type="button" class="btn-ghost" @click="article = null">Fermer</button>
          <button v-if="article.id" type="button" class="btn-ghost ml-auto text-red-600" @click="remove">Supprimer la fiche</button>
        </div>
      </form>
    </Modal>
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
