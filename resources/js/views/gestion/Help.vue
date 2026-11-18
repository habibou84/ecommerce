<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import Icon from '../../components/Icon.vue'
import { useAuthStore } from '../../stores/auth'
import { boot } from '../../lib/boot'
import http, { errorMessage } from '../../lib/http'

// Aide : on écrit sa question, on lit la fiche (réponse courte, vidéo, étapes, images, bouton vers le bon écran).
// Le support sur WhatsApp vient après, quand la fiche n'a pas suffi.
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const list = ref(null)
const results = ref(null)
const article = ref(null)
const error = ref('')
const q = ref('')
const searching = ref(false)
const playing = ref(false)
const zoom = ref(null)

const slug = computed(() => route.params.slug || null)
const groups = computed(() => Object.entries(list.value?.categories ?? {})
  .map(([key, label]) => ({ key, label, articles: list.value.articles.filter((a) => a.category === key) }))
  .filter((g) => g.articles.length))

async function loadList() {
  try {
    list.value = (await http.get('/help')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function loadArticle() {
  article.value = null
  playing.value = false
  if (!slug.value) return
  error.value = ''
  try {
    article.value = (await http.get(`/help/${slug.value}`)).data.data
  } catch (e) {
    error.value = e.response?.status === 404 ? 'Cette fiche n\'existe plus.' : errorMessage(e)
  }
}
onMounted(() => {
  loadList()
  loadArticle()
})
watch(slug, loadArticle)

// Recherche : lancée quand la personne a fini d'écrire
let timer
watch(q, (value) => {
  clearTimeout(timer)
  if (value.trim().length < 2) {
    results.value = null
    return
  }
  timer = setTimeout(search, 450)
})
async function search() {
  const asked = q.value.trim()
  searching.value = true
  try {
    const found = (await http.get('/help', { params: { q: asked } })).data.data.results
    if (asked === q.value.trim()) results.value = found
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    searching.value = false
  }
}

async function vote(helpful) {
  try {
    article.value.my_vote = (await http.post(`/help/${slug.value}/vote`, { helpful })).data.data.my_vote
  } catch (e) {
    error.value = errorMessage(e)
  }
}

const paragraphs = computed(() => (article.value?.body ?? '').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean))
function supportUrl(about) {
  const text = `Bonjour, j'ai besoin d'aide pour mon espace marchand ${auth.store?.name ?? ''} (${boot.store?.slug ?? ''})${about ? ` : ${about}` : '.'}`
  return `https://wa.me/${String(boot.supportWhatsapp ?? '').replace(/\D/g, '')}?text=${encodeURIComponent(text)}`
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Une fiche -->
    <template v-if="slug">
      <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-slate-600" @click="router.push('/gestion/aide')">← Toute l'aide</button>
      <article v-if="article" class="card space-y-5 p-5 sm:p-6">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ list?.categories[article.category] }}</p>
          <h2 class="mt-1 text-xl font-semibold">{{ article.title }}</h2>
        </div>
        <p class="rounded-xl bg-emerald-50 p-4 text-base text-emerald-900">{{ article.summary }}</p>

        <div v-if="article.video">
          <template v-if="article.video.file">
            <video v-if="playing" class="mx-auto aspect-[9/16] w-full max-w-[16rem] rounded-2xl bg-slate-900" :poster="article.video.poster" controls autoplay playsinline>
              <source :src="article.video.url" type="video/mp4">
            </video>
            <button v-else type="button" class="btn-secondary w-full sm:w-auto" @click="playing = true">▶ Voir la vidéo<template v-if="article.video.seconds"> ({{ article.video.seconds }} s)</template></button>
          </template>
          <a v-else :href="article.video.url" target="_blank" rel="noopener" class="btn-secondary w-full sm:w-auto">▶ Voir la vidéo</a>
        </div>

        <div v-if="article.steps.length">
          <p class="text-sm font-semibold text-slate-800">Ce qu'il faut faire</p>
          <ol class="mt-2 space-y-2">
            <li v-for="(item, i) in article.steps" :key="i" class="flex items-start gap-2.5 text-base text-slate-700">
              <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ i + 1 }}</span>{{ item }}
            </li>
          </ol>
        </div>

        <div v-if="article.images.length" class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
          <button v-for="src in article.images" :key="src" type="button" class="shrink-0" aria-label="Agrandir l'image" @click="zoom = src">
            <img :src="src" alt="" class="h-56 rounded-xl border border-slate-200 object-contain" loading="lazy">
          </button>
        </div>

        <p v-for="(p, i) in paragraphs" :key="i" class="whitespace-pre-line text-base text-slate-700">{{ p }}</p>

        <RouterLink v-if="article.screen" :to="article.screen" class="btn-brand w-full justify-center sm:w-auto">{{ article.screen_label }}<Icon name="chevron" :size="16" /></RouterLink>

        <div class="border-t border-slate-100 pt-4">
          <p class="text-sm font-medium text-slate-700">Cette réponse vous a aidé ?</p>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <button type="button" class="btn-secondary" :class="article.my_vote === true ? '!border-emerald-600 !bg-emerald-50 !text-emerald-800' : ''" @click="vote(true)">👍 Oui</button>
            <button type="button" class="btn-secondary" :class="article.my_vote === false ? '!border-amber-600 !bg-amber-50 !text-amber-900' : ''" @click="vote(false)">👎 Non</button>
          </div>
          <p v-if="article.my_vote === true" class="mt-2 text-sm text-emerald-800">Merci 🙂</p>
          <div v-else-if="article.my_vote === false" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
            <p>Désolé. Écrivez-nous, on vous aide tout de suite.</p>
            <a v-if="boot.supportWhatsapp" :href="supportUrl(article.title)" target="_blank" rel="noopener" class="btn-brand mt-2"><Icon name="whatsapp" :size="16" />Écrire au support</a>
          </div>
        </div>
      </article>
    </template>

    <!-- Toutes les fiches -->
    <template v-else>
      <section class="card p-4 sm:p-5">
        <h2 class="text-lg font-semibold sm:text-xl">Comment peut-on vous aider ?</h2>
        <label for="help-search" class="sr-only">Votre question</label>
        <div class="relative mt-3">
          <Icon name="search" :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
          <input id="help-search" v-model="q" type="search" class="input !pl-10" placeholder="Écrivez votre question : ajouter un produit, livreur…" autocomplete="off" enterkeyhint="search" @keydown.enter.prevent="search">
        </div>
      </section>

      <section v-if="results !== null" class="space-y-2">
        <RouterLink v-for="a in results" :key="a.slug" :to="`/gestion/aide/${a.slug}`" class="card block p-4">
          <p class="font-semibold">{{ a.title }}<span v-if="a.has_video" class="ml-2 rounded-full bg-sky-100 px-2 py-0.5 align-middle text-xs font-semibold text-sky-800">▶ Vidéo</span></p>
          <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ a.summary }}</p>
        </RouterLink>
        <div v-if="!results.length && !searching" class="card p-5 text-center text-sm text-slate-600">
          <p>Pas encore de fiche sur cette question.</p>
          <a v-if="boot.supportWhatsapp" :href="supportUrl(q.trim())" target="_blank" rel="noopener" class="btn-brand mt-3"><Icon name="whatsapp" :size="16" />Poser la question au support</a>
        </div>
      </section>

      <template v-else-if="list">
        <section v-for="g in groups" :key="g.key" class="card p-2">
          <p class="px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ g.label }}</p>
          <RouterLink v-for="a in g.articles" :key="a.slug" :to="`/gestion/aide/${a.slug}`" class="flex items-center gap-2 rounded-lg px-3 py-2.5 text-base hover:bg-slate-50">
            <span class="min-w-0 flex-1">{{ a.title }}</span>
            <span v-if="a.has_video" class="shrink-0 text-xs font-semibold text-sky-700" aria-label="Avec une vidéo">▶</span>
            <Icon name="chevron" :size="16" class="shrink-0 text-slate-400" />
          </RouterLink>
        </section>
      </template>

      <section class="card flex flex-wrap items-center gap-3 p-4 text-sm">
        <p class="min-w-0 flex-1 basis-48 text-slate-600">Vous ne trouvez pas votre réponse ? <RouterLink to="/gestion/demarrage" class="font-semibold text-emerald-700 underline">Reprenez le guide de démarrage</RouterLink> ou écrivez-nous.</p>
        <a v-if="boot.supportWhatsapp" :href="supportUrl('')" target="_blank" rel="noopener" class="btn-secondary"><Icon name="whatsapp" :size="16" />Écrire au support</a>
      </section>
    </template>

    <!-- Image agrandie -->
    <Teleport to="body">
      <div v-if="zoom" class="fixed inset-0 z-[60] grid place-items-center bg-slate-900/80 p-4" @click="zoom = null">
        <img :src="zoom" alt="" class="max-h-full max-w-full rounded-xl">
      </div>
    </Teleport>
  </div>
</template>
