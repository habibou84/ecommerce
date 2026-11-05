<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

/**
 * Cartes du live pour TikTok LIVE Studio (écran vertical d'un téléphone) : deux cartes en hauteur, posées côte à côte
 * en haut de l'image, la vendeuse en dessous. Écrites très gros pour être lisibles sur le téléphone des clientes.
 * Une seule carte (480 × 780), posée en haut à gauche, la vendeuse en dessous : la photo de l'article en grand avec
 * son code, puis « Envoie A1 » et le numéro dans le style choisi par la boutique (sinon « numéro dans la bio »).
 * Ni prix, ni QR code, ni adresse de site, ni le mot WhatsApp : TikTok coupe ou freine les lives qui en montrent.
 * Mode « Vitrine » : 10 articles et leur code ; mode « Commander » : « Envoie le code + ta taille ».
 * (L'ancien lien 2 « commander » montre la même carte.)
 * Tout est en « em » calculé sur la taille de la source : nette si la source a la taille indiquée.
 */
const props = defineProps({
  data: { type: Object, default: null }, // { store, live }
  format: { type: String, default: 'carte' }, // carte | commander
})

const root = ref(null)
const size = ref({ w: 480, h: 780 })
let observer
onMounted(() => {
  observer = new ResizeObserver(([entry]) => (size.value = { w: entry.contentRect.width, h: entry.contentRect.height }))
  observer.observe(root.value)
})
onUnmounted(() => {
  observer?.disconnect()
  clearInterval(second)
  clearInterval(rotate)
})

// 480 × 780 : 12 em × 19,5 em (limité par la hauteur si la source est plus basse que prévu)
const fontSize = computed(() => Math.min(size.value.w / 12, size.value.h / 19.5))

const live = computed(() => props.data?.live ?? null)
const store = computed(() => props.data?.store ?? null)
const items = computed(() => live.value?.items ?? [])
const available = computed(() => items.value.filter((i) => i.remaining > 0))
// Mêmes règles que l'écran télé : article choisi par la vendeuse, ou défilement toutes les 8 secondes
const step = ref(0)
const rotate = setInterval(() => (step.value += 1), 8000)
const shown = computed(() => {
  if (!live.value) return null
  if (live.value.mode === 'scroll') {
    const list = available.value.length ? available.value : items.value
    return list.length ? list[step.value % list.length] : null
  }
  return items.value.find((i) => i.id === live.value.featured_item_id) ?? available.value[0] ?? items.value[0] ?? null
})

const nowMs = ref(Date.now())
const second = setInterval(() => (nowMs.value = Date.now()), 1000)
const flashLeft = computed(() => (shown.value?.flash_ends_at ? Math.max(0, Math.round((new Date(shown.value.flash_ends_at).getTime() - nowMs.value) / 1000)) : 0))
const chrono = computed(() => `${String(Math.floor(flashLeft.value / 60)).padStart(2, '0')}:${String(flashLeft.value % 60).padStart(2, '0')}`)
// Exemple : le code de l'article montré, sinon A1
const example = computed(() => shown.value?.code ?? 'A1')
const mode = computed(() => live.value?.mode ?? 'product')
const phoneLine = computed(() => (store.value?.phone_shown ? store.value.phone_shown : '📌 Numéro dans la bio'))
// Vitrine : 10 articles, puis les 10 suivants toutes les 8 secondes
const gridItems = computed(() => {
  const pages = Math.max(1, Math.ceil(items.value.length / 10))
  const page = step.value % pages
  return items.value.slice(page * 10, page * 10 + 10)
})
</script>

<template>
  <div ref="root" class="lo" :style="{ fontSize: `${fontSize}px` }">
    <!-- Comment commander -->
    <div v-if="live && mode === 'how'" class="lo-order">
      <p class="lo-order-title">Pour commander</p>
      <p class="lo-order-send">Envoie<br>le code</p>
      <p class="lo-order-example">+ ta taille · ex. <b>{{ example }} M</b></p>
      <p class="lo-order-bio">{{ phoneLine }}</p>
    </div>

    <!-- Vitrine : 10 articles et leur code -->
    <div v-else-if="live && mode === 'grid' && items.length" class="lo-grid">
      <p class="lo-grid-head">Envoie le code 👇</p>
      <div class="lo-grid-items">
        <div v-for="i in gridItems" :key="i.id" class="lo-grid-item" :class="{ 'is-out': i.remaining === 0 }">
          <img v-if="i.image" :src="i.image" alt="" />
          <b>{{ i.code }}</b>
        </div>
      </div>
    </div>

    <!-- Avant le live ou entre deux articles -->
    <div v-else-if="!shown" class="lo-wait">
      <b>{{ store?.name }}</b>
      <span>{{ live ? 'Envoie le code de l\'article' : 'Le live commence bientôt' }}</span>
    </div>

    <!-- L'article : la photo en grand avec le code, puis « Envoie A1 » et le numéro -->
    <div v-else :key="shown.id" class="lo-card">
      <div class="lo-photo">
        <img v-if="shown.image" :src="shown.image" alt="" />
        <span class="lo-code">{{ shown.code }}</span>
        <span v-if="flashLeft > 0 && shown.remaining > 0" class="lo-chrono">⏱ {{ chrono }}</span>
        <span v-else-if="shown.remaining === 0" class="lo-sold">VENDU</span>
      </div>
      <div class="lo-foot">
        <p class="lo-send">Envoie {{ shown.code }}</p>
        <p class="lo-phone">{{ phoneLine }}</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.lo { position: absolute; inset: 0; overflow: hidden; color: #fff; font-family: Inter, system-ui, sans-serif; line-height: 1.1; }
.lo-wait, .lo-card, .lo-order { position: absolute; inset: .25em; border-radius: .7em; overflow: hidden; box-shadow: 0 .15em .5em rgba(0, 0, 0, .35); animation: lo-in .5s ease-out; }
.lo-wait { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .4em; text-align: center; padding: .6em; background: #0f172a; }
.lo-wait b { font-size: 1.4em; font-weight: 900; }
.lo-wait span { font-size: 1em; color: #a7f3d0; font-weight: 700; }

/* La carte : la photo en grand avec le code, puis « Envoie A1 » et le numéro (480 × 780 : 12 em × 19,5 em) */
.lo-card { display: flex; flex-direction: column; background: #fff; }
.lo-photo { position: relative; flex: 1; min-height: 0; background: #fde7c7; }
.lo-photo img { width: 100%; height: 100%; object-fit: cover; }
.lo-code { position: absolute; top: .3em; left: .3em; background: #fff; color: #0f172a; border: .12em solid #0f172a; border-radius: .25em; font-size: 4.4em; font-weight: 900; line-height: 1; letter-spacing: -.02em; padding: .04em .14em; }
.lo-chrono, .lo-sold { position: absolute; right: .3em; bottom: .3em; background: #fff; color: #b91c1c; font-weight: 900; font-size: 1.4em; border-radius: .3em; padding: .05em .4em; }
.lo-sold { background: #111827; color: #fff; }
.lo-foot { flex-shrink: 0; background: #0f172a; text-align: center; padding: .45em .3em .55em; }
.lo-send { font-size: 2.2em; font-weight: 900; line-height: 1.05; }
.lo-phone { margin-top: .2em; font-size: 1.05em; font-weight: 900; color: #fde047; letter-spacing: .02em; line-height: 1.25; overflow-wrap: anywhere; }

/* Vitrine : 10 articles et leur code, 2 colonnes */
.lo-grid { position: absolute; inset: .25em; border-radius: .7em; overflow: hidden; background: #fff; padding: .3em; display: flex; flex-direction: column; gap: .25em; box-shadow: 0 .15em .5em rgba(0, 0, 0, .35); }
.lo-grid-head { text-align: center; color: #0f172a; font-weight: 900; font-size: 1.15em; }
.lo-grid-items { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(2, 1fr); grid-template-rows: repeat(5, 1fr); gap: .25em; }
.lo-grid-item { position: relative; min-height: 0; border-radius: .35em; overflow: hidden; background: #fde7c7; }
.lo-grid-item img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.lo-grid-item b { position: absolute; left: 0; bottom: 0; background: #0f172a; color: #fff; font-weight: 900; font-size: 1.6em; line-height: 1.1; padding: 0 .25em; border-top-right-radius: .25em; }
.lo-grid-item.is-out img { filter: grayscale(1) opacity(.45); }

/* Comment commander (480 × 780 : 12 em × 19,5 em) */
.lo-order { display: flex; flex-direction: column; align-items: center; gap: .45em; padding: .7em .5em .6em; background: #15803d; text-align: center; }
.lo-order-title { font-size: 1.15em; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; color: #bbf7d0; }
.lo-order { justify-content: center; gap: .6em; }
.lo-order-send { font-size: 2.3em; font-weight: 900; line-height: 1; }
.lo-order-example { font-size: 1.4em; font-weight: 800; color: #dcfce7; }
.lo-order-example b { background: #fff; color: #15803d; border-radius: .3em; padding: 0 .3em; }
.lo-order-bio { width: 100%; margin-top: .4em; background: #fde047; color: #111827; border-radius: .4em; padding: .45em .3em; font-size: 1.35em; font-weight: 900; line-height: 1.1; }

@keyframes lo-in { from { opacity: 0; transform: translateY(.4em); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .lo-wait, .lo-card, .lo-order { animation: none; } }
</style>
