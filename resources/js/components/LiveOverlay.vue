<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

/**
 * Cartes du live pour TikTok LIVE Studio (écran vertical d'un téléphone) : deux cartes en hauteur, posées côte à côte
 * en haut de l'image, la vendeuse en dessous. Écrites très gros pour être lisibles sur le téléphone des clientes.
 * - « carte » (480 × 780) : l'article montré, la photo en haut (rien dessus), le code et le prix en bas ;
 * - « commander » (480 × 780) : le numéro WhatsApp, « envoie le code » et l'adresse de la page du live.
 * Pas de QR code (on ne scanne pas l'écran qu'on regarde). Tout est en « em » calculé sur la taille de la source :
 * nette si la source a la taille indiquée.
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
const money = (v) => `${new Intl.NumberFormat('fr-FR').format(v).replace(/[  ]/g, ' ')} F`
// Numéro sans l'indicatif, plus court à lire
const phone = computed(() => String(store.value?.whatsapp || '').replace(/^\+\d{3}\s*/, ''))
// Adresse du site coupée seulement après un point ou une barre oblique (jamais au milieu d'un mot)
const site = computed(() => String(store.value?.link_label || '').replace(/([./])/g, '$1\u200b'))
</script>

<template>
  <div ref="root" class="lo" :style="{ fontSize: `${fontSize}px` }">
    <!-- Lien 2 : pour commander (même sans article montré) -->
    <div v-if="format === 'commander'" class="lo-order">
      <p class="lo-order-title">Pour commander</p>
      <template v-if="phone">
        <p class="lo-order-wa">💬 WhatsApp</p>
        <p class="lo-order-num">{{ phone }}</p>
      </template>
      <p class="lo-order-hint">Envoie le code<template v-if="shown"><br>(ex. <b>{{ shown.code }}</b>)</template></p>
      <p v-if="site" class="lo-order-site"><small>ou sur</small>{{ site }}</p>
    </div>

    <!-- Avant le live ou entre deux articles -->
    <div v-else-if="!shown" class="lo-wait">
      <b>{{ store?.name }}</b>
      <span>{{ live ? 'Écris le code de l\'article' : 'Le live commence bientôt' }}</span>
    </div>

    <!-- Lien 1 : l'article, la photo en haut, le code et le prix en bas -->
    <div v-else :key="shown.id" class="lo-card">
      <div class="lo-photo"><img v-if="shown.image" :src="shown.image" alt="" /></div>
      <div class="lo-foot">
        <span class="lo-code">{{ shown.code }}</span>
        <p class="lo-price"><small v-if="shown.from">dès </small>{{ money(shown.price) }}</p>
        <p v-if="flashLeft > 0 && shown.remaining > 0" class="lo-chrono">⏱ {{ chrono }}</p>
        <p v-else-if="shown.remaining === 0" class="lo-sold">VENDU</p>
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

/* Lien 1 : la carte article (480 × 780 : 12 em × 19,5 em) */
.lo-card { display: flex; flex-direction: column; background: #fff; }
.lo-photo { flex: 1; min-height: 0; background: #fde7c7; }
.lo-photo img { width: 100%; height: 100%; object-fit: cover; }
.lo-foot { flex-shrink: 0; background: #b91c1c; text-align: center; padding: .35em .3em .45em; }
.lo-code { display: block; font-size: 4.2em; font-weight: 900; line-height: 1; letter-spacing: .02em; }
.lo-price { font-size: 2.2em; font-weight: 900; color: #fde047; white-space: nowrap; }
.lo-price small { font-size: .55em; }
.lo-chrono, .lo-sold { display: inline-block; margin-top: .25em; background: #fff; color: #b91c1c; font-weight: 900; font-size: 1.15em; border-radius: .3em; padding: .05em .4em; }
.lo-sold { background: #111827; color: #fff; }

/* Lien 2 : pour commander (480 × 780 : 12 em × 19,5 em) */
.lo-order { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .55em; padding: .7em .5em; background: #15803d; text-align: center; }
.lo-order-title { font-size: 1.15em; font-weight: 900; text-transform: uppercase; letter-spacing: .06em; color: #bbf7d0; }
.lo-order-wa { font-size: 1.2em; font-weight: 800; }
.lo-order-num { font-size: 1.55em; font-weight: 900; white-space: nowrap; letter-spacing: -.03em; }
.lo-order-hint { font-size: 1.2em; font-weight: 800; color: #dcfce7; }
.lo-order-hint b { color: #fde047; }
.lo-order-site { width: 100%; background: #fff; color: #15803d; border-radius: .4em; padding: .35em .3em; font-size: .9em; font-weight: 900; line-height: 1.2; overflow-wrap: anywhere; }
.lo-order-site small { display: block; font-size: .8em; color: #475569; font-weight: 700; }

@keyframes lo-in { from { opacity: 0; transform: translateY(.4em); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .lo-wait, .lo-card, .lo-order { animation: none; } }
</style>
