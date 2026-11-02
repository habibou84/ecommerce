<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

/**
 * Habillage du live pour TikTok LIVE Studio (écran vertical d'un téléphone) : peu d'éléments, écrits très gros, pour
 * être lisibles par les clientes qui regardent le live sur leur téléphone.
 * - « haut » (1080 × 560) : bandeau en haut, la caméra en dessous ;
 * - « bas » (1080 × 320) : bandeau en bas, fond transparent sur la vidéo ;
 * - « carte » (480 × 480) : petite carte dans un coin, fond transparent.
 * Pas de QR code ni d'adresse de site (on ne scanne pas l'écran qu'on regarde) : « Écris A3 au 07… ».
 * Tout est en « em » calculé sur la taille de la source : nette si la source a la taille indiquée.
 */
const props = defineProps({
  data: { type: Object, default: null }, // { store, live }
  format: { type: String, default: 'haut' }, // haut | bas | carte
})

const root = ref(null)
const size = ref({ w: 1080, h: 560 })
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

// 1 em selon l'habillage (limité par la hauteur si la source est plus basse que prévu)
const fontSize = computed(() => {
  const { w, h } = size.value
  return { haut: Math.min(w / 30, h / 15.5), bas: Math.min(w / 30, h / 8.8), carte: Math.min(w / 12, h / 12) }[props.format] ?? Math.min(w / 30, h / 15.5)
})

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
const sizes = computed(() => shown.value?.options?.[0] ?? null)
const money = (v) => `${new Intl.NumberFormat('fr-FR').format(v).replace(/[  ]/g, ' ')} F`
// « Écris A3 38 » : le code et la première taille encore là
const example = computed(() => {
  const free = sizes.value?.values.find((v) => v.remaining > 0)
  return free ? `${shown.value.code} ${free.value}` : shown.value?.code
})
// Numéro sans l'indicatif, plus court à lire
const phone = computed(() => String(store.value?.whatsapp || '').replace(/^\+\d{3}\s*/, ''))
const left = computed(() => {
  const i = shown.value
  if (!i) return ''
  return i.remaining === 0 ? 'VENDU' : i.remaining === 1 ? 'Dernière pièce !' : i.remaining <= 3 ? `Plus que ${i.remaining} !` : ''
})
const lastSale = computed(() => live.value?.ticker?.[0]?.text ?? null)
</script>

<template>
  <div ref="root" class="lo" :class="`lo-${format}`" :style="{ fontSize: `${fontSize}px` }">
    <!-- Avant le live ou entre deux articles -->
    <div v-if="!shown" class="lo-wait">
      <b>{{ store?.name }}</b>
      <span>{{ live ? 'Écris le code de l\'article sur WhatsApp' : 'Le live commence bientôt' }}</span>
    </div>

    <!-- Bandeau en haut : photo, code, prix, tailles, « écris A3 » -->
    <div v-else-if="format === 'haut'" :key="shown.id" class="lo-top">
      <div class="lo-photo">
        <img v-if="shown.image" :src="shown.image" alt="" />
        <span v-if="flashLeft > 0 && shown.remaining > 0" class="lo-chrono">⏱ {{ chrono }}</span>
        <span v-if="left" class="lo-left" :class="{ 'is-out': shown.remaining === 0 }">{{ left }}</span>
      </div>
      <div class="lo-info">
        <div class="lo-head"><span class="lo-code">{{ shown.code }}</span><span class="lo-name">{{ shown.title }}</span></div>
        <p class="lo-price"><small v-if="shown.from">dès </small>{{ money(shown.price) }}<s v-if="shown.was">{{ money(shown.was) }}</s></p>
        <div v-if="sizes" class="lo-sizes">
          <span v-for="v in sizes.values" :key="v.value" :class="{ 'is-out': v.remaining === 0 }">{{ v.value }}</span>
        </div>
        <p v-if="store?.whatsapp" class="lo-wa">💬 Écris <b>{{ example }}</b> au {{ phone }}</p>
        <p v-else class="lo-wa">💬 Écris <b>{{ example }}</b> en message</p>
      </div>
    </div>

    <!-- Bandeau en bas, sur la vidéo -->
    <div v-else-if="format === 'bas'" :key="shown.id" class="lo-bottom">
      <span class="lo-code">{{ shown.code }}</span>
      <div class="lo-mid">
        <p class="lo-price">{{ money(shown.price) }}<s v-if="shown.was">{{ money(shown.was) }}</s></p>
        <p class="lo-line">Écris <b>{{ example }}</b><template v-if="store?.whatsapp"> au {{ phone }}</template></p>
      </div>
      <div class="lo-side">
        <span v-if="flashLeft > 0 && shown.remaining > 0" class="lo-chrono">⏱ {{ chrono }}</span>
        <span v-if="left" class="lo-left" :class="{ 'is-out': shown.remaining === 0 }">{{ left }}</span>
      </div>
    </div>

    <!-- Petite carte dans un coin -->
    <div v-else :key="shown.id" class="lo-card">
      <span class="lo-code">{{ shown.code }}</span>
      <p class="lo-price">{{ money(shown.price) }}</p>
      <p v-if="flashLeft > 0 && shown.remaining > 0" class="lo-chrono">⏱ {{ chrono }}</p>
      <p v-else-if="left" class="lo-left" :class="{ 'is-out': shown.remaining === 0 }">{{ left }}</p>
      <p class="lo-line">Écris <b>{{ shown.code }}</b> sur WhatsApp</p>
    </div>

    <!-- Dernière pièce prise (bandeau du haut seulement : la place y est) -->
    <p v-if="format === 'haut' && shown && lastSale" :key="lastSale" class="lo-sale">🎉 {{ lastSale }}</p>
  </div>
</template>

<style scoped>
.lo { position: absolute; inset: 0; overflow: hidden; color: #fff; font-family: Inter, system-ui, sans-serif; line-height: 1.1; }
.lo-haut { background: linear-gradient(135deg, #7c2d12 0%, #be123c 55%, #4c1d95 100%); }
.lo-code { display: inline-grid; place-items: center; background: #dc2626; color: #fff; border: .12em solid #fff; border-radius: .25em; font-weight: 900; padding: .05em .22em; line-height: 1; flex-shrink: 0; }
.lo-price { font-weight: 900; color: #fde047; white-space: nowrap; }
.lo-price s { color: #fecaca; font-size: .45em; margin-left: .3em; font-weight: 700; }
.lo-price small { font-size: .5em; }
.lo-chrono { background: #fff; color: #be123c; font-weight: 900; border-radius: .3em; padding: .1em .35em; white-space: nowrap; }
.lo-left { background: #f97316; color: #fff; font-weight: 900; border-radius: .3em; padding: .1em .35em; white-space: nowrap; }
.lo-left.is-out { background: #111827; }
.lo-wait { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .3em; text-align: center; padding: .5em; background: rgba(4, 18, 11, .85); }
.lo-wait b { font-size: 1.6em; font-weight: 900; }
.lo-wait span { font-size: 1em; color: #a7f3d0; font-weight: 700; }

/* Bandeau en haut (1080 × 560 : 30 em × 15,5 em) */
.lo-top { position: absolute; inset: 0; display: flex; gap: .6em; padding: .6em .6em 1.9em; }
.lo-photo { position: relative; width: 11em; flex-shrink: 0; border-radius: .5em; overflow: hidden; background: #fde7c7; }
.lo-photo img { width: 100%; height: 100%; object-fit: cover; }
.lo-photo .lo-chrono { position: absolute; left: .3em; top: .3em; font-size: 1.15em; }
.lo-photo .lo-left { position: absolute; left: .3em; bottom: .3em; font-size: .95em; }
.lo-info { min-width: 0; flex: 1; display: flex; flex-direction: column; justify-content: center; gap: .3em; }
.lo-head { display: flex; align-items: center; gap: .3em; min-width: 0; }
.lo-top .lo-code { font-size: 2.6em; }
.lo-name { font-size: 1.15em; font-weight: 800; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.lo-top .lo-price { font-size: 2.9em; }
.lo-sizes { display: flex; flex-wrap: wrap; gap: .25em; }
.lo-sizes span { background: #fff; color: #0f172a; font-weight: 900; font-size: 1.15em; border-radius: .25em; padding: .1em .35em; }
.lo-sizes span.is-out { opacity: .35; text-decoration: line-through; }
.lo-wa { background: #fff; color: #0f172a; font-weight: 800; font-size: .9em; border-radius: .35em; padding: .25em .45em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lo-wa b { color: #dc2626; font-weight: 900; }
.lo-sale { position: absolute; left: 0; right: 0; bottom: 0; height: 1.6em; display: flex; align-items: center; justify-content: center; background: rgba(0, 0, 0, .35); font-weight: 800; font-size: 1em; white-space: nowrap; overflow: hidden; animation: lo-in .5s ease-out; }

/* Bandeau en bas, transparent (1080 × 320 : 30 em × 8,8 em) */
.lo-bottom { position: absolute; inset: 0; display: flex; align-items: flex-end; gap: .5em; padding: 2.2em .6em .5em; background: linear-gradient(0deg, rgba(0, 0, 0, .88) 0%, rgba(0, 0, 0, .6) 60%, transparent 100%); animation: lo-in .5s ease-out; }
.lo-bottom .lo-code { font-size: 3.2em; }
.lo-mid { min-width: 0; flex: 1; }
.lo-bottom .lo-price { font-size: 2.6em; }
.lo-line { font-weight: 800; font-size: .95em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lo-line b { color: #fde047; font-weight: 900; }
.lo-side { display: flex; flex-direction: column; align-items: flex-end; gap: .25em; font-size: 1.15em; }

/* Petite carte (480 × 480 : 12 em × 12 em), transparente autour */
.lo-card { position: absolute; inset: .3em; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25em; background: #fff; color: #0f172a; border-radius: .9em; box-shadow: 0 .2em .6em rgba(0, 0, 0, .35); text-align: center; animation: lo-in .5s ease-out; }
.lo-card .lo-code { font-size: 3.6em; }
.lo-card .lo-price { color: #be123c; font-size: 2.1em; }
.lo-card .lo-chrono, .lo-card .lo-left { font-size: 1em; }
.lo-card .lo-chrono { background: #be123c; color: #fff; }
.lo-card .lo-line { font-size: .85em; color: #334155; }
.lo-card .lo-line b { color: #dc2626; }

@keyframes lo-in { from { opacity: 0; transform: translateY(.4em); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .lo-bottom, .lo-card, .lo-sale { animation: none; } }
</style>
