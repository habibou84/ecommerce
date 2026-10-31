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

// Trois mises en page : paysage (télé, ordinateur), carré (TikTok LIVE Studio, OBS, PRISM en 720 × 640) et
// portrait (téléphone en partage d'écran, tablette debout)
const usable = computed(() => size.value.h * (1 - props.freeBottom))
const layout = computed(() => (size.value.w > usable.value * 1.15 ? 'land' : size.value.w >= usable.value * 0.9 ? 'square' : 'port'))
// 1 em = 1/80 de la largeur en paysage, 1/45 en carré, 1/25 en portrait (limité par la hauteur)
const fontSize = computed(() => ({
  land: Math.min(size.value.w / 80, usable.value / 45),
  square: Math.min(size.value.w / 45, usable.value / 40),
  port: Math.min(size.value.w / 25, usable.value / 42),
})[layout.value])

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
  return live.value ? 'Envoyez un ou plusieurs codes sur WhatsApp : A1 + A3' : 'Le live commence bientôt'
})
watch(() => live.value?.ticker?.[0]?.id, () => (tickIndex.value = 0))

const money = (v) => `${new Intl.NumberFormat('fr-FR').format(v).replace(/ /g, ' ')} F`
// Numéro complet : l'indicatif (+225) plus petit, pour que le numéro tienne en entier sur une ligne
const number = computed(() => {
  const [first, ...rest] = String(store.value?.whatsapp || '').split(' ')
  return first.startsWith('+') && rest.length ? { prefix: first, local: rest.join(' ') } : { prefix: '', local: store.value?.whatsapp || '' }
})
const left = (i) => (i.remaining === 0 ? (i.waiting ? `Liste d'attente : ${i.code}` : 'Épuisé') : i.remaining === 1 ? '🔥 Dernière pièce !' : i.remaining <= 3 ? `🔥 Plus que ${i.remaining} !` : `${i.remaining} pièces`)
</script>

<template>
  <div ref="root" class="live-display" :class="`is-${layout}`" :style="{ fontSize: `${fontSize}px` }">
    <div class="ld-stage" :style="{ bottom: `${freeBottom * 100}%` }">
      <!-- Comment commander (entre deux articles, ou avant le live) : le QR code en très grand -->
      <div v-if="showHow" class="ld-how">
        <div class="ld-how-text">
          <p class="ld-soon">{{ store?.name }} · {{ live ? 'en direct' : 'le live commence bientôt' }}</p>
          <h2>Comment commander ?</h2>
          <ol>
            <li><span>1</span>Notez vos codes (A1, A2…)</li>
            <li><span>2</span>{{ store?.qr ? 'Scannez, envoyez « A1 + A3 »' : 'Envoyez « A1 + A3 » sur WhatsApp' }}</li>
            <li><span>3</span>Touchez votre commune</li>
            <li><span>4</span>On vous livre ✅</li>
          </ol>
          <div v-if="store?.link" class="ld-site ld-how-site">
            <img :src="store.link_qr" alt="QR code du site" />
            <div class="ld-site-text">
              <small>Ou commandez sur le site</small>
              <b>{{ store.link_label }}</b>
            </div>
          </div>
        </div>
        <div v-if="store?.whatsapp" class="ld-qr">
          <img v-if="store.qr" :src="store.qr" alt="QR code WhatsApp" />
          <small class="ld-wa-label">WhatsApp</small>
          <b><small v-if="number.prefix" class="ld-cc">{{ number.prefix }}</small>{{ number.local }}</b>
          <small>Paiement à la livraison</small>
        </div>
      </div>

      <!-- Article montré : la photo en grand, puis le prix et le bloc WhatsApp (QR code et numéro) -->
      <div v-else class="ld-product">
        <div class="ld-photo">
          <template v-if="shown.image">
            <!-- Photo entière (jamais coupée), posée sur la même photo floutée qui remplit le cadre -->
            <img :src="shown.image" alt="" class="ld-photo-bg" aria-hidden="true" />
            <img :src="shown.image" alt="" class="ld-photo-img" />
          </template>
          <span class="ld-left" :class="{ 'is-out': shown.remaining === 0 }">{{ left(shown) }}</span>
          <span v-if="shown.remaining === 0" class="ld-stamp">VENDU</span>
        </div>
        <div class="ld-info">
          <span class="ld-live"><i />EN DIRECT · {{ store?.name }}</span>
          <!-- Le code à côté du nom, hors de la photo : rien ne cache l'article -->
          <div class="ld-head">
            <span class="ld-code">{{ shown.code }}</span>
            <div class="ld-titles">
              <p class="ld-name">{{ shown.title }}</p>
              <p v-if="shown.variant" class="ld-variant">{{ shown.variant }}</p>
            </div>
          </div>
          <p class="ld-price"><b>{{ money(shown.price) }}</b><s v-if="shown.was">{{ money(shown.was) }}</s></p>
          <div v-if="store?.whatsapp" class="ld-wa">
            <img v-if="store.qr" :src="store.qr" alt="QR code WhatsApp" />
            <div class="ld-wa-text">
              <small class="ld-wa-label">WhatsApp</small>
              <b><small v-if="number.prefix" class="ld-cc">{{ number.prefix }}</small>{{ number.local }}</b>
              <span>Envoyez <u>{{ shown.code }}</u><template v-if="layout !== 'square'"> (ou plusieurs : {{ shown.code }} + …)</template><template v-if="store.qr && layout === 'land'"><br>ou scannez le code</template></span>
            </div>
          </div>
          <div v-if="store?.link" class="ld-site">
            <img :src="store.link_qr" alt="QR code du site" />
            <div class="ld-site-text">
              <small>{{ store?.whatsapp ? 'Ou commandez sur le site' : 'Commandez sur le site' }}</small>
              <b>{{ store.link_label }}</b>
            </div>
          </div>
        </div>
      </div>

      <!-- Bandeau : dernières commandes -->
      <div v-if="layout !== 'port'" class="ld-ticker">
        <span class="ld-ticker-text">{{ ticker }}</span>
        <span v-if="!showHow" class="ld-ticker-pos">{{ position }} / {{ items.length }}</span>
        <span class="ld-brand">Propulsé par <b>Jibiat Marchand</b><template v-if="layout === 'land'"> · jibiat-marchand.com</template></span>
      </div>
      <template v-else>
        <p v-if="!showHow" class="ld-mini-ticker">{{ ticker }}</p>
        <p class="ld-brand-line">Propulsé par <b>Jibiat Marchand</b> · jibiat-marchand.com</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.live-display { position: relative; width: 100%; height: 100%; overflow: hidden; color: #fff; background: radial-gradient(circle at 20% 0%, #1e293b, #020617 70%); font-family: Inter, system-ui, sans-serif; line-height: 1.1; }
.ld-stage { position: absolute; inset: 0; display: flex; flex-direction: column; }
.ld-product { flex: 1; display: flex; min-height: 0; }
.ld-photo { position: relative; overflow: hidden; background: linear-gradient(160deg, #f97316, #be123c); flex-shrink: 0; box-shadow: 0 1em 3em rgba(0, 0, 0, .45); }
.ld-photo-bg { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; filter: blur(1.6em) brightness(.75); transform: scale(1.15); }
.ld-photo-img { position: relative; width: 100%; height: 100%; object-fit: contain; display: block; }
.ld-head { display: flex; align-items: center; gap: .6em; min-width: 0; }
.ld-titles { min-width: 0; }
.ld-code { flex-shrink: 0; background: #dc2626; color: #fff; font-weight: 900; line-height: 1; border: .1em solid #fff; border-radius: .22em; padding: .06em .2em .09em; letter-spacing: -.03em; }
/* Lien du site et son QR code, pour commander sans WhatsApp */
.ld-site { display: flex; align-items: center; background: #fff; color: #0f172a; flex-shrink: 0; }
.ld-site img { display: block; flex-shrink: 0; image-rendering: pixelated; }
.ld-site-text { min-width: 0; }
.ld-site small { display: block; font-weight: 800; color: #475569; }
.is-land .ld-price + .ld-site, .is-square .ld-price + .ld-site { margin-top: auto; }
.ld-site b { display: block; font-weight: 900; overflow-wrap: anywhere; line-height: 1.15; }
.ld-left { position: absolute; background: #f97316; color: #fff; font-weight: 900; border-radius: 999px; white-space: nowrap; box-shadow: 0 .2em .6em rgba(0, 0, 0, .3); }
.ld-left.is-out { background: #475569; }
.ld-stamp { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%) rotate(-14deg); color: #dc2626; font-weight: 900; border: .1em solid #dc2626; border-radius: .2em; background: rgba(255, 255, 255, .88); padding: 0 .3em; white-space: nowrap; }
.ld-info { display: flex; flex-direction: column; min-width: 0; }
.ld-live { display: inline-flex; align-items: center; gap: .5em; font-weight: 800; color: #fda4af; }
.ld-live i { width: .7em; height: .7em; border-radius: 50%; background: #ef4444; box-shadow: 0 0 0 .25em rgba(239, 68, 68, .25); }
.ld-name { font-weight: 900; line-height: 1.05; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.ld-variant { color: #cbd5e1; font-weight: 600; margin-top: .2em; }
.ld-price { display: flex; flex-wrap: wrap; align-items: baseline; column-gap: .4em; white-space: nowrap; }
.ld-price b { color: #facc15; font-weight: 900; letter-spacing: -.03em; line-height: 1; }
.ld-price s { color: #94a3b8; font-weight: 700; }

/* Bloc WhatsApp : QR code (sur fond blanc pour se scanner) et numéro en gros */
.ld-wa { display: flex; align-items: center; background: #fff; color: #064e3b; flex-shrink: 0; }
.ld-wa img { display: block; flex-shrink: 0; image-rendering: pixelated; }
.ld-wa-text { flex: 1; min-width: 0; container-type: inline-size; }
.ld-wa-label { display: flex; align-items: center; gap: .35em; font-weight: 800; color: #047857; }
.ld-wa-label::before { content: ''; width: 1.05em; height: 1.05em; border-radius: 50%; background: #25d366; box-shadow: inset 0 0 0 .22em #25d366, inset 0 0 0 .55em #fff; }
.ld-cc { font-size: .6em; margin-right: .3em; letter-spacing: 0; }
.ld-wa b { display: block; font-weight: 900; letter-spacing: -.02em; white-space: nowrap; color: #0f172a; }
.ld-wa span { display: block; font-weight: 700; color: #475569; }
.ld-wa u { text-decoration: none; background: #dc2626; color: #fff; border-radius: .2em; padding: 0 .25em; font-weight: 900; }

.ld-ticker { display: flex; align-items: center; gap: 1em; background: #047857; font-weight: 800; flex-shrink: 0; }
.ld-ticker-text { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ld-ticker-pos { color: #a7f3d0; white-space: nowrap; }
/* Mention Jibiat Marchand : discrète, jamais au-dessus du prix ni du code */
.ld-brand { padding-left: 1em; border-left: .08em solid rgba(167, 243, 208, .4); color: #a7f3d0; font-weight: 600; font-size: .62em; white-space: nowrap; }
.ld-brand b { color: #fff; font-weight: 800; }
.ld-brand-line { text-align: center; color: rgba(255, 255, 255, .6); font-size: .85em; font-weight: 600; padding: .5em 0 .7em; flex-shrink: 0; }
.ld-brand-line b { color: #fff; }
.ld-mini-ticker { text-align: center; color: #a7f3d0; font-weight: 700; padding: .4em 1em .5em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: .9em; }

/* Paysage : télé, ordinateur — la photo sur la moitié gauche, toute la hauteur */
.is-land .ld-product { gap: 2.3em; padding-right: 1.9em; }
.is-land .ld-photo { width: 50%; border-radius: 0 1.75em 1.75em 0; }
.is-land .ld-code { font-size: 4em; }
.is-land .ld-site { gap: 1em; padding: .6em 1em .6em .6em; border-radius: 1em; }
.is-land .ld-site img { width: 6em; }
.is-land .ld-site small { font-size: 1.15em; }
.is-land .ld-site b { font-size: 1.6em; }
.is-land .ld-left { left: 1.75em; bottom: 1.75em; font-size: 1.9em; padding: .25em .7em; }
.is-land .ld-stamp { font-size: 5em; }
.is-land .ld-info { flex: 1; gap: .9em; padding: 2.1em 0 1.75em; }
.is-land .ld-live { font-size: 1.3em; }
.is-land .ld-name { font-size: 2.9em; }
.is-land .ld-variant { font-size: 1.6em; }
.is-land .ld-price b { font-size: 6.5em; }
.is-land .ld-price s { font-size: 2em; }
.is-land .ld-wa { margin-top: auto; gap: 1.1em; padding: 1em; border-radius: 1.1em; }
.is-land .ld-wa img { width: 12.5em; }
.is-land .ld-wa-label { font-size: 1.4em; }
.is-land .ld-wa b { font-size: min(2.7em, 9.5cqi); margin: .15em 0 .25em; }
.is-land .ld-wa span { font-size: 1.4em; }
.is-land .ld-ticker { height: 2.5em; padding: 0 1.25em; font-size: 1.4em; }

/* Carré : TikTok LIVE Studio, OBS, PRISM — photo à gauche, prix et WhatsApp à droite */
.is-square .ld-product { gap: 1.25em; padding-right: 1.25em; }
.is-square .ld-photo { width: 52%; border-radius: 0 1.5em 1.5em 0; }
.is-square .ld-code { font-size: 2.8em; }
.is-square .ld-site { gap: .6em; padding: .45em; border-radius: .8em; }
.is-square .ld-site img { width: 4.6em; }
.is-square .ld-site small { font-size: .8em; }
.is-square .ld-site b { font-size: 1em; }
.is-square .ld-left { left: 1.1em; bottom: 1.1em; font-size: 1.15em; padding: .3em .8em; }
.is-square .ld-stamp { font-size: 3.4em; }
.is-square .ld-info { flex: 1; gap: .5em; padding: 1.4em 0 1.1em; }
.is-square .ld-live { font-size: .9em; }
.is-square .ld-name { font-size: 1.9em; }
.is-square .ld-variant { font-size: 1.05em; }
.is-square .ld-price b { font-size: 3.9em; }
.is-square .ld-price s { font-size: 1.15em; }
.is-square .ld-wa { margin-top: auto; flex-direction: column; gap: .4em; padding: .75em; border-radius: 1em; text-align: center; }
.is-square .ld-wa img { width: 10.6em; }
.is-square .ld-wa-label { justify-content: center; font-size: .95em; }
.is-square .ld-wa b { font-size: min(2em, 9.5cqi); margin: .1em 0; }
.is-square .ld-wa span { font-size: .95em; }
.is-square .ld-ticker { height: 2.75em; padding: 0 1.1em; font-size: 1em; }

/* Portrait : téléphone en partage d'écran, tablette debout — la photo bord à bord */
.is-port .ld-product { flex-direction: column; }
.is-port .ld-photo { flex: 1; min-height: 0; border-radius: 0 0 1.6em 1.6em; }
.is-port .ld-code { font-size: 2.6em; }
.is-port .ld-site { gap: .7em; padding: .45em; margin: .5em -.3em 0; border-radius: .9em; }
.is-port .ld-site img { width: 4.2em; }
.is-port .ld-site small { font-size: .85em; }
.is-port .ld-site b { font-size: 1.1em; }
.is-port .ld-left { right: 1em; bottom: 1em; font-size: 1.05em; padding: .35em .8em; }
.is-port .ld-stamp { font-size: 3em; }
.is-port .ld-info { gap: .25em; padding: .8em 1.1em 0; }
.is-port .ld-live { display: none; }
.is-port .ld-name { font-size: 1.85em; -webkit-line-clamp: 1; }
.is-port .ld-variant { font-size: .9em; }
.is-port .ld-price b { font-size: 3.6em; }
.is-port .ld-price s { font-size: 1.25em; }
.is-port .ld-wa { gap: .8em; padding: .7em; margin: .6em -.3em 0; border-radius: 1.1em; }
.is-port .ld-wa img { width: 7.4em; }
.is-port .ld-wa-label { font-size: 1em; }
.is-port .ld-wa b { font-size: min(1.95em, 9.5cqi); margin: .1em 0 .25em; }
.is-port .ld-wa span { font-size: 1em; }

/* Comment commander */
.ld-how { flex: 1; display: flex; align-items: center; background: linear-gradient(160deg, #047857, #022c22); min-height: 0; }
.ld-how h2 { font-weight: 900; line-height: 1; }
.ld-how ol { list-style: none; display: flex; flex-direction: column; }
.ld-how li { display: flex; align-items: center; font-weight: 800; }
.ld-how li span { border-radius: 50%; background: #facc15; color: #111; display: grid; place-items: center; font-weight: 900; flex-shrink: 0; }
.ld-soon { color: #a7f3d0; font-weight: 800; }
.ld-qr { display: flex; flex-direction: column; align-items: center; background: #fff; color: #064e3b; text-align: center; flex-shrink: 0; min-height: 0; }
.ld-qr img { display: block; width: 100%; image-rendering: pixelated; }
.ld-qr b { display: block; font-weight: 900; white-space: nowrap; color: #0f172a; letter-spacing: -.02em; }
.ld-qr small { font-weight: 700; color: #475569; }
.ld-qr .ld-wa-label { color: #047857; font-weight: 800; }
.is-land .ld-how { gap: 2.5em; padding: 2em 3.5em; }
.is-land .ld-how-text { flex: 1; min-width: 0; }
.is-land .ld-how h2 { font-size: 3.7em; margin-bottom: .5em; white-space: nowrap; }
.is-land .ld-how ol { gap: 1.25em; }
.is-land .ld-how li { gap: .8em; font-size: 2.1em; }
.is-land .ld-how li span { width: 1.6em; height: 1.6em; }
.is-land .ld-how-site { margin-top: 1.5em; gap: 1em; padding: .6em 1.2em .6em .6em; border-radius: 1em; display: inline-flex; }
.is-land .ld-how-site img { width: 6em; }
.is-land .ld-how-site small { font-size: 1.1em; }
.is-land .ld-how-site b { font-size: 1.6em; }
.is-land .ld-soon { font-size: 1.4em; margin-bottom: .6em; }
.is-land .ld-qr { width: 27em; padding: 1.4em; border-radius: 1.6em; }
.is-land .ld-qr .ld-wa-label { font-size: 1.4em; margin-top: .5em; }
.is-land .ld-qr b { font-size: 2.75em; }
.is-land .ld-qr small:last-child { font-size: 1.15em; margin-top: .2em; }
.is-square .ld-how { gap: 1.5em; padding: 1.5em; }
.is-square .ld-how-text { flex: 1; min-width: 0; }
.is-square .ld-how h2 { font-size: 2.4em; margin-bottom: .5em; }
.is-square .ld-how ol { gap: .7em; }
.is-square .ld-how li { gap: .5em; font-size: 1.2em; }
.is-square .ld-how li span { width: 1.6em; height: 1.6em; }
.is-square .ld-how-site { margin-top: 1em; gap: .6em; padding: .45em; border-radius: .8em; }
.is-square .ld-how-site img { width: 4.6em; }
.is-square .ld-how-site small { font-size: .8em; }
.is-square .ld-how-site b { font-size: 1em; }
.is-square .ld-soon { font-size: .95em; margin-bottom: .5em; }
.is-square .ld-qr { width: 18em; padding: 1em; border-radius: 1.2em; }
.is-square .ld-qr .ld-wa-label { font-size: 1em; margin-top: .3em; }
.is-square .ld-qr b { font-size: 2.1em; }
.is-square .ld-qr small:last-child { font-size: .85em; }
.is-port .ld-how { flex-direction: column; justify-content: center; gap: 1.2em; padding: 1.4em; text-align: left; }
.is-port .ld-how-text { align-self: stretch; }
.is-port .ld-how h2 { font-size: 2.6em; margin-bottom: .5em; }
.is-port .ld-how ol { gap: .55em; }
.is-port .ld-how li { gap: .5em; font-size: 1.15em; }
.is-port .ld-how li span { width: 1.7em; height: 1.7em; }
.is-port .ld-how-site { margin-top: 1em; gap: .6em; padding: .45em; border-radius: .8em; }
.is-port .ld-how-site img { width: 4.2em; }
.is-port .ld-how-site small { font-size: .8em; }
.is-port .ld-how-site b { font-size: 1em; }
.is-port .ld-soon { font-size: .9em; margin-bottom: .6em; }
.is-port .ld-qr { width: 15em; padding: 1em; border-radius: 1.2em; }
.is-port .ld-qr .ld-wa-label { font-size: .95em; margin-top: .3em; }
.is-port .ld-qr b { font-size: 1.6em; }
.is-port .ld-qr small:last-child { font-size: .8em; }
</style>
