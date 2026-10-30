<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { download, errorMessage } from '../../../lib/http'

/**
 * Cartes du live : une image par article (photo, code en très grand, prix, « Envoyez A1 sur WhatsApp »), sa
 * version « VENDU » et « Comment commander », à enregistrer dans la galerie du téléphone puis à poser dans un coin
 * du live TikTok (effet image), ou à partager sur WhatsApp avant le live.
 */
const props = defineProps({ id: { type: [String, Number], required: true } })
const cards = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const was = ref(true)
const stock = ref(true)
const sold = ref(false)

async function load() {
  error.value = ''
  try {
    cards.value = (await http.get(`/lives/${props.id}/cards`, { params: { was: was.value ? 1 : 0, stock: stock.value ? 1 : 0 } })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
watch([was, stock], load)

const list = computed(() => {
  if (!cards.value) return []
  const items = cards.value.items.flatMap((c) => [
    { key: c.code, label: c.code, url: c.url, path: c.path, name: `${c.code}.jpg` },
    ...(sold.value ? [{ key: `${c.code}-v`, label: `${c.code} vendu`, url: c.sold_url, path: c.sold_path, name: `${c.code}-vendu.jpg` }] : []),
  ])
  return [...items, { key: 'how', label: 'Commander', url: cards.value.how.url, path: cards.value.how.path, name: 'comment-commander.jpg' }]
})

// Images lues par l'application (même adresse que la page) pour les partager vers la galerie
async function file(card) {
  const { data } = await http.get('/lives/cards/file', { params: { path: card.path }, responseType: 'blob' })
  return new File([data], card.name, { type: 'image/jpeg' })
}
// Préparées dès l'affichage : le partage part tout de suite au toucher (l'iPhone refuse un partage trop tardif)
const prepared = ref(null)
watch(list, async (cards) => {
  prepared.value = null
  try {
    const ready = await Promise.all(cards.map(file))
    if (cards === list.value) prepared.value = ready
  } catch {
    prepared.value = null
  }
})
const files = async () => prepared.value ?? Promise.all(list.value.map(file))

// Téléphone : le partage du système propose « Enregistrer les images » ; sinon, un fichier ZIP
async function saveAll() {
  busy.value = true
  notice.value = ''
  try {
    const all = await files()
    if (navigator.canShare?.({ files: all })) {
      await navigator.share({ files: all, title: 'Cartes du live' })
    } else {
      await download(`/lives/${props.id}/cards.zip?was=${was.value ? 1 : 0}&stock=${stock.value ? 1 : 0}&sold=${sold.value ? 1 : 0}`, 'cartes-du-live.zip')
      notice.value = 'Fichier ZIP téléchargé : ouvrez-le pour retrouver les cartes.'
    }
  } catch (e) {
    if (e?.name !== 'AbortError') error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function shareOne(card) {
  try {
    const one = await file(card)
    if (navigator.canShare?.({ files: [one] })) await navigator.share({ files: [one] })
    else window.open(card.url, '_blank')
  } catch (e) {
    if (e?.name !== 'AbortError') window.open(card.url, '_blank')
  }
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4">
    <RouterLink :to="`/gestion/live/${id}`" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Retour au live</RouterLink>
    <div>
      <h1 class="text-xl font-extrabold">Cartes du live</h1>
      <p class="text-sm text-slate-600">Une image par article, dans l'ordre du live, plus « Comment commander ». Enregistrez-les dans la galerie, puis affichez-les dans un coin de votre live.</p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <section class="card space-y-3 p-4">
      <div class="flex flex-wrap gap-2 text-sm">
        <button type="button" class="rounded-full px-3 py-1.5 font-semibold" :class="was ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="was = !was">Avec prix barré</button>
        <button type="button" class="rounded-full px-3 py-1.5 font-semibold" :class="stock ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="stock = !stock">Pièces restantes</button>
        <button type="button" class="rounded-full px-3 py-1.5 font-semibold" :class="sold ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="sold = !sold">Versions VENDU</button>
      </div>
      <p v-if="!cards && !error" class="text-sm text-slate-500">Préparation des cartes…</p>
      <div v-else-if="cards" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
        <button v-for="c in list" :key="c.key" type="button" class="group text-left" @click="shareOne(c)">
          <img :src="c.url" :alt="`Carte ${c.label}`" class="aspect-[3/4] w-full rounded-xl border border-slate-200 object-cover" loading="lazy" />
          <span class="mt-1 block text-center text-xs font-semibold text-slate-600">{{ c.label }}</span>
        </button>
      </div>
      <p class="text-xs text-slate-500">Les pièces restantes sont celles du moment : pendant le live, montrez la version VENDU quand un article est épuisé.</p>
    </section>

    <button type="button" class="btn-brand w-full py-3 text-base" :disabled="busy || !cards" @click="saveAll">{{ busy ? 'Préparation…' : `⬇ Enregistrer les ${list.length} cartes dans la galerie` }}</button>
    <p class="text-center text-xs text-slate-500">Sur le téléphone, choisissez « Enregistrer les images » (iPhone) ou « Enregistrer » / « Galerie » (Android). Le même bouton permet de les envoyer sur WhatsApp (statut, groupes).</p>

    <section class="card p-4 text-sm">
      <h2 class="mb-2 font-semibold">Afficher une carte dans le live TikTok</h2>
      <ol class="space-y-1.5">
        <li class="flex gap-2"><span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-50 text-xs font-bold text-emerald-700">1</span>Pendant le live, touchez « Effets ».</li>
        <li class="flex gap-2"><span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-50 text-xs font-bold text-emerald-700">2</span>Cherchez « Green Screen » (ou l'effet image).</li>
        <li class="flex gap-2"><span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-50 text-xs font-bold text-emerald-700">3</span>Choisissez la carte de l'article dans la galerie.</li>
        <li class="flex gap-2"><span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-emerald-50 text-xs font-bold text-emerald-700">4</span>Réduisez-la et posez-la dans un coin de l'écran.</li>
      </ol>
      <p class="mt-2 text-xs text-slate-500">Les menus de TikTok changent selon la version de l'application.</p>
    </section>
  </div>
</template>
