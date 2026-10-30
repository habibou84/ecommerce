<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

/**
 * Affichage du live pour un écran filmé (télé, tablette), TikTok LIVE Studio ou le partage d'écran : l'article
 * montré en très grand (photo, code, prix, pièces restantes, « VENDU »), le défilement des articles, ou « Comment
 * commander » avec le QR code WhatsApp ; bandeau des dernières commandes (commune seulement).
 * Tout est dessiné en « em » : la taille suit celle de l'écran, du téléviseur au téléphone.
 */
const props = defineProps({
  data: { type: Object, default: null }, // { store, live }
  // Place laissée libre en bas (bulle du visage en partage d'écran), en part de la hauteur
  freeBottom: { type: Number, default: 0 },
  // Article à montrer tout de suite (mode présentation : sans attendre le serveur)
  itemId: { type: Number, default: null },
})

const root = ref(null)
const size = ref({ w: 1280, h: 720 })
let observer
onMounted(() => {
  observer = new ResizeObserver(([entry]) => (size.value = { w: entry.contentRect.width, h: entry.contentRect.height }))
  observer.observe(root.value)
})
onUnmounted(() => {
  observer?.disconnect()
  clearInterval(rotate)
  clearInterval(tick)
})

const landscape = computed(() => size.value.w > size.value.h * 1.15)
// 1 em = 1/80 de la largeur en paysage (télé), 1/25 en portrait (téléphone, tablette debout)
const fontSize = computed(() => {
  const usable = size.value.h * (1 - props.freeBottom)
  return landscape.value ? Math.min(size.value.w / 80, usable / 45) : Math.min(size.value.w / 25, usable / 42)
})

const live = computed(() => props.data?.live ?? null)
const store = computed(() => props.data?.store ?? null)
const items = computed(() => live.value?.items ?? [])
const available = computed(() => items.value.filter((i) => i.remaining > 0))

// Défilement : un article disponible toutes les 8 secondes
const step = ref(0)
let rotate = setInterval(() => (step.value += 1), 8000)
const mode = computed(() => live.value?.mode ?? 'product')
const shown = computed(() => {
  if (!live.value) return null
  if (mode.value === 'scroll') {
    const list = available.value.length ? available.value : items.value
    return list.length ? list[step.value % list.length] : null
  }
  const id = props.itemId ?? live.value.featured_item_id
  return items.value.find((i) => i.id === id) ?? available.value[0] ?? items.value[0] ?? null
})
const showHow = computed(() => !live.value || mode.value === 'how' || !shown.value)
const position = computed(() => (shown.value ? items.value.findIndex((i) => i.id === shown.value.id) + 1 : 0))

// Bandeau : les dernières commandes à tour de rôle, sinon le nombre de commandes
const tickIndex = ref(0)
let tick = setInterval(() => (tickIndex.value += 1), 4000)
const ticker = computed(() => {
  const list = live.value?.ticker ?? []
  if (list.length) return `✓ ${list[tickIndex.value % list.length].text} 🎉`
  if (live.value?.orders) return `✓ ${live.value.orders} commande${live.value.orders > 1 ? 's' : ''} pendant ce live 🎉`
  return live.value ? 'Envoyez le code de l\'article sur WhatsApp' : 'Le live commence bientôt'
})
watch(() => live.value?.ticker?.[0]?.id, () => (tickIndex.value = 0))

const money = (v) => `${new Intl.NumberFormat('fr-FR').format(v).replace(/ /g, ' ')} F`
const left = (i) => (i.remaining === 0 ? (i.waiting ? `Liste d'attente : ${i.code}` : 'Épuisé') : i.remaining === 1 ? '🔥 Dernière pièce !' : i.remaining <= 3 ? `🔥 Plus que ${i.remaining} !` : `${i.remaining} pièces`)
</script>

<template>
  <div ref="root" class="live-display" :class="landscape ? 'is-land' : 'is-port'" :style="{ fontSize: `${fontSize}px` }">
    <div class="ld-stage" :style="{ bottom: `${freeBottom * 100}%` }">
      <!-- Comment commander (entre deux articles, ou avant le live) -->
      <div v-if="showHow" class="ld-how">
        <div class="ld-how-text">
          <p v-if="!live" class="ld-soon">{{ store?.name }} · le live commence bientôt</p>
          <h2>Comment commander ?</h2>
          <ol>
            <li><span>1</span>Notez le code (A1, A2…)</li>
            <li><span>2</span>Envoyez-le sur WhatsApp</li>
            <li><span>3</span>Touchez votre commune</li>
            <li><span>4</span>On vous livre ✅</li>
          </ol>
        </div>
        <div v-if="store?.whatsapp" class="ld-qr">
          <img v-if="store.qr" :src="store.qr" alt="QR code WhatsApp" />
          <b>{{ store.whatsapp }}</b>
          <small>Paiement à la livraison</small>
        </div>
      </div>

      <!-- Article montré -->
      <div v-else class="ld-product">
        <div class="ld-photo">
          <img v-if="shown.image" :src="shown.image" alt="" />
          <span class="ld-code">{{ shown.code }}</span>
          <span v-if="shown.remaining === 0" class="ld-stamp">VENDU</span>
        </div>
        <div class="ld-info">
          <span class="ld-live"><i />EN DIRECT · {{ store?.name }}<em v-if="!landscape">{{ position }} / {{ items.length }}</em></span>
          <div>
            <p class="ld-name">{{ shown.title }}</p>
            <p v-if="shown.variant" class="ld-variant">{{ shown.variant }}</p>
          </div>
          <p class="ld-price"><b>{{ money(shown.price) }}</b><s v-if="shown.was">{{ money(shown.was) }}</s></p>
          <span class="ld-left" :class="{ 'is-out': shown.remaining === 0 }">{{ left(shown) }}</span>
          <p v-if="!landscape && store?.whatsapp" class="ld-wa">Envoyez {{ shown.code }} au {{ store.whatsapp }}</p>
        </div>
      </div>

      <!-- Bandeau : dernières commandes et numéro WhatsApp -->
      <div v-if="landscape" class="ld-ticker">
        <span class="ld-ticker-dot" />
        <span class="ld-ticker-text">{{ ticker }}</span>
        <span v-if="store?.whatsapp" class="ld-ticker-num">WhatsApp {{ store.whatsapp }}</span>
      </div>
      <p v-else-if="!showHow" class="ld-mini-ticker">{{ ticker }}</p>
    </div>
  </div>
</template>

<style scoped>
.live-display { position: relative; width: 100%; height: 100%; overflow: hidden; color: #fff; background: radial-gradient(circle at 20% 0%, #1e293b, #020617 70%); font-family: Inter, system-ui, sans-serif; line-height: 1.1; }
.ld-stage { position: absolute; inset: 0; display: flex; flex-direction: column; }
.ld-product { flex: 1; display: flex; min-height: 0; }
.ld-photo { position: relative; overflow: hidden; border-radius: 1.6em; background: linear-gradient(160deg, #f97316, #be123c); flex-shrink: 0; box-shadow: 0 1em 3em rgba(0, 0, 0, .45); }
.ld-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ld-code { position: absolute; left: .6em; top: .6em; background: #dc2626; color: #fff; font-weight: 900; line-height: 1; border: .12em solid #fff; border-radius: .25em; letter-spacing: -.03em; }
.ld-stamp { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%) rotate(-14deg); color: #dc2626; font-weight: 900; border: .1em solid #dc2626; border-radius: .2em; background: rgba(255, 255, 255, .88); padding: 0 .3em; white-space: nowrap; }
.ld-info { display: flex; flex-direction: column; justify-content: center; gap: .9em; min-width: 0; }
.ld-live { display: inline-flex; align-items: center; gap: .5em; font-weight: 800; color: #fda4af; }
.ld-live i { width: .7em; height: .7em; border-radius: 50%; background: #ef4444; box-shadow: 0 0 0 .25em rgba(239, 68, 68, .25); }
.ld-live em { margin-left: auto; font-style: normal; color: #94a3b8; }
.ld-name { font-weight: 900; line-height: 1.05; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.ld-variant { color: #cbd5e1; font-weight: 600; margin-top: .2em; }
.ld-price { display: flex; align-items: baseline; gap: .4em; white-space: nowrap; }
.ld-price b { color: #facc15; font-weight: 900; letter-spacing: -.03em; line-height: 1; }
.ld-price s { color: #94a3b8; font-weight: 700; }
.ld-left { align-self: flex-start; background: #f97316; color: #fff; font-weight: 900; border-radius: 999px; white-space: nowrap; }
.ld-left.is-out { background: #475569; }
.ld-wa { background: #047857; border-radius: .6em; font-weight: 800; padding: .5em .7em; }
.ld-ticker { display: flex; align-items: center; gap: 1em; background: #047857; font-weight: 800; padding: 0 1.4em; height: 3.4em; flex-shrink: 0; font-size: 1.15em; }
.ld-ticker-dot { width: 1.4em; height: 1.4em; border-radius: 50%; background: #25d366; flex-shrink: 0; box-shadow: inset 0 0 0 .4em #047857, inset 0 0 0 1em #fff; }
.ld-ticker-text { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ld-ticker-num { background: #fff; color: #064e3b; border-radius: .5em; padding: .25em .7em; white-space: nowrap; }
.ld-mini-ticker { text-align: center; color: #a7f3d0; font-weight: 700; padding: .5em 1em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Paysage : télé, ordinateur */
.is-land .ld-product { gap: 2.4em; padding: 2.4em 2.8em 2em; align-items: stretch; }
.is-land .ld-photo { width: 36%; }
.is-land .ld-code { font-size: 5.6em; padding: .06em .22em .1em; }
.is-land .ld-stamp { font-size: 5em; }
.is-land .ld-live { font-size: 1.5em; }
.is-land .ld-name { font-size: 3.4em; }
.is-land .ld-variant { font-size: 1.7em; }
.is-land .ld-price b { font-size: 6em; }
.is-land .ld-price s { font-size: 2.2em; }
.is-land .ld-left { font-size: 2.2em; padding: .25em .7em; }

/* Portrait : téléphone en partage d'écran, tablette debout */
.is-port .ld-product { flex-direction: column; gap: .7em; padding: 1.4em 1em .4em; }
.is-port .ld-photo { flex: 1; min-height: 0; border-radius: 1.1em; }
.is-port .ld-code { font-size: 3.2em; padding: .04em .2em .08em; }
.is-port .ld-stamp { font-size: 3em; }
.is-port .ld-info { gap: .45em; }
.is-port .ld-live { font-size: .8em; }
.is-port .ld-name { font-size: 1.6em; }
.is-port .ld-variant { font-size: .85em; }
.is-port .ld-price b { font-size: 2.9em; }
.is-port .ld-price s { font-size: 1.1em; }
.is-port .ld-left { position: absolute; right: 1.6em; top: 2.1em; font-size: .9em; padding: .35em .7em; }
.is-port .ld-wa { font-size: .9em; }

/* Comment commander */
.ld-how { flex: 1; display: flex; align-items: center; gap: 2.4em; background: linear-gradient(160deg, #047857, #022c22); min-height: 0; }
.ld-how h2 { font-weight: 900; line-height: 1; }
.ld-how ol { list-style: none; display: flex; flex-direction: column; }
.ld-how li { display: flex; align-items: center; font-weight: 800; }
.ld-how li span { border-radius: 50%; background: #facc15; color: #111; display: grid; place-items: center; font-weight: 900; flex-shrink: 0; }
.ld-soon { color: #a7f3d0; font-weight: 800; }
.ld-qr { background: #fff; color: #064e3b; text-align: center; border-radius: 1em; flex-shrink: 0; }
.ld-qr img { display: block; width: 100%; image-rendering: pixelated; }
.ld-qr b { display: block; font-weight: 900; white-space: nowrap; }
.ld-qr small { font-weight: 700; }
.is-land .ld-how { padding: 2em 3.2em; }
.is-land .ld-how-text { flex: 1; }
.is-land .ld-how h2 { font-size: 3.8em; margin-bottom: .5em; white-space: nowrap; }
.is-land .ld-how ol { gap: .9em; }
.is-land .ld-how li { gap: .8em; font-size: 2.2em; }
.is-land .ld-how li span { width: 1.6em; height: 1.6em; }
.is-land .ld-soon { font-size: 1.6em; margin-bottom: .8em; }
.is-land .ld-qr { width: 19em; padding: 1.2em; }
.is-land .ld-qr b { font-size: 2em; margin-top: .4em; }
.is-land .ld-qr small { font-size: 1.2em; }
.is-port .ld-how { flex-direction: column; justify-content: center; gap: 1.2em; padding: 1.4em; text-align: left; }
.is-port .ld-how h2 { font-size: 2.6em; margin-bottom: .5em; }
.is-port .ld-how ol { gap: .55em; }
.is-port .ld-how li { gap: .5em; font-size: 1.15em; }
.is-port .ld-how li span { width: 1.7em; height: 1.7em; }
.is-port .ld-soon { font-size: .9em; margin-bottom: .6em; }
.is-port .ld-qr { width: 12em; padding: .8em; }
.is-port .ld-qr b { font-size: 1.15em; margin-top: .3em; }
.is-port .ld-qr small { font-size: .75em; }
</style>
