<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import http, { errorMessage } from '../lib/http'

/**
 * Photo à épingler (Support du live) : les photos des articles et leur code, rien d'autre. En hauteur (TikTok, statut
 * WhatsApp) ou carrée (Facebook), 12 articles par photo. À télécharger ou à partager, refaite quand les articles changent.
 */
const props = defineProps({ live: { type: Object, required: true } })

const pages = ref(null)
const format = ref('portrait')
const error = ref('')
const busy = ref(false)
const done = ref('')

// Les articles du live (codes) : la photo est redemandée quand ils changent
const signature = computed(() => (props.live.items ?? []).map((i) => i.code).join(','))
const shown = computed(() => pages.value?.[format.value] ?? [])

async function load() {
  error.value = ''
  try {
    pages.value = (await http.get(`/lives/${props.live.id}/pin`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
watch(signature, load)

const fileName = (i) => `live-${props.live.id}-${format.value === 'square' ? 'carre' : 'hauteur'}${shown.value.length > 1 ? `-${i + 1}` : ''}.jpg`

async function blobOf(page) {
  return (await http.get('/lives/pin/file', { params: { path: page.path }, responseType: 'blob' })).data
}

function flash(text) {
  done.value = text
  setTimeout(() => (done.value = ''), 2500)
}

async function download() {
  busy.value = true
  error.value = ''
  try {
    for (const [i, page] of shown.value.entries()) {
      const url = URL.createObjectURL(await blobOf(page))
      const a = Object.assign(document.createElement('a'), { href: url, download: fileName(i) })
      document.body.appendChild(a)
      a.click()
      a.remove()
      setTimeout(() => URL.revokeObjectURL(url), 5000)
    }
    flash(shown.value.length > 1 ? 'Photos téléchargées ✓' : 'Photo téléchargée ✓')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function' && typeof navigator.canShare === 'function'

async function share() {
  busy.value = true
  error.value = ''
  try {
    const files = await Promise.all(shown.value.map(async (page, i) => new File([await blobOf(page)], fileName(i), { type: 'image/jpeg' })))
    if (!navigator.canShare({ files })) {
      await download()
      return
    }
    await navigator.share({ files })
  } catch (e) {
    if (e?.name !== 'AbortError') error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="card space-y-3 p-4">
    <div>
      <h3 class="font-extrabold">📌 Photo à épingler</h3>
      <p class="mt-1 text-sm text-slate-600">Les photos de tes articles et leur code, sur une seule image. Épingle-la sur ton profil TikTok ou Facebook, ou en statut WhatsApp, avant le live.</p>
    </div>

    <div class="grid grid-cols-2 gap-1.5" role="tablist" aria-label="Format de la photo">
      <button v-for="[value, label] in [['portrait', 'En hauteur (TikTok, statut)'], ['square', 'Carrée (Facebook)']]" :key="value" type="button" role="tab" :aria-selected="format === value"
              class="rounded-xl border-[1.5px] px-2 py-2 text-xs font-bold" :class="format === value ? 'border-ocre-600 bg-orange-50 text-ocre-700' : 'border-slate-200 bg-white text-slate-600'" @click="format = value">{{ label }}</button>
    </div>

    <p v-if="error" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
    <p v-if="pages === null && !error" class="text-sm text-slate-500">Préparation de la photo…</p>
    <p v-else-if="pages && !shown.length" class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">Ajoutez des articles au live : la photo se prépare toute seule.</p>

    <div v-if="shown.length" class="grid gap-3" :class="shown.length > 1 ? 'grid-cols-2' : 'grid-cols-1'">
      <figure v-for="(page, i) in shown" :key="page.path" class="m-0">
        <img :src="page.url" :alt="`Photo à épingler ${i + 1} : ${page.codes.join(', ')}`" class="mx-auto w-full rounded-2xl bg-slate-900 object-contain" :class="format === 'square' ? 'max-w-sm' : 'max-w-[260px]'" loading="lazy" />
        <figcaption v-if="shown.length > 1" class="mt-1 text-center text-xs text-slate-500">{{ page.codes[0] }} à {{ page.codes[page.codes.length - 1] }}</figcaption>
      </figure>
    </div>

    <div v-if="shown.length" class="grid gap-2 sm:grid-cols-2">
      <button type="button" class="btn-brand justify-center" :disabled="busy" @click="download">⬇ {{ shown.length > 1 ? 'Télécharger les photos' : 'Télécharger la photo' }}</button>
      <button v-if="canShare" type="button" class="btn-secondary justify-center" :disabled="busy" @click="share">Partager (WhatsApp, TikTok…)</button>
    </div>
    <p v-if="done" class="text-sm font-semibold text-emerald-700">{{ done }}</p>
    <p class="text-xs text-slate-500">La photo est refaite quand tu ajoutes ou retires un article.</p>
  </section>
</template>
