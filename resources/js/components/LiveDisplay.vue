<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { play, setMusic, setMuted } from '../lib/liveSounds'

/**
 * Affichage du live pour un écran filmé (télé, tablette), TikTok LIVE Studio ou le partage d'écran : l'article
 * montré en très grand (photo, code, tailles, pièces restantes, « VENDU »), le défilement des articles, la vitrine
 * (10 articles et leur code) ou « Comment commander » ; bandeau des dernières commandes (commune seulement).
 * Conforme aux règles de TikTok : ni prix, ni QR code, ni adresse de site, ni le mot WhatsApp ; le numéro seulement
 * si la boutique l'a choisi (écrit espacé, en émojis ou coupé en deux), sinon « numéro dans la bio ».
 * Tout est dessiné en « em » : la taille suit celle de l'écran, du téléviseur au téléphone.
 * Effets (calculés par l'écran, rien de plus demandé au serveur) : fond qui bouge, entrée de chaque article, zoom
 * lent de la photo, barre de stock, bandeau qui défile ; à chaque pièce prise, confettis et « Cocody vient de
 * prendre A3 ». Seulement transform et opacity : fluide même sur une vieille télé.
 */
const props = defineProps({
  data: { type: Object, default: null }, // { store, live }
  // Place laissée libre en bas (bulle du visage en partage d'écran), en part de la hauteur
  freeBottom: { type: Number, default: 0 },
  // Article à montrer tout de suite (mode présentation : sans attendre le serveur)
  itemId: { type: Number, default: null },
  // Effets animés (coupés pour les petites vignettes de l'application)
  effects: { type: Boolean, default: true },
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
  clearTimeout(partyTimer)
  clearInterval(second)
  clearTimers()
  setMusic(false)
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
// Vitrine : 10 articles et leur code, puis les 10 suivants toutes les 10 secondes
const showGrid = computed(() => !!live.value && mode.value === 'grid' && items.value.length > 0)
const pages = computed(() => Math.max(1, Math.ceil(items.value.length / 10)))
const gridItems = computed(() => {
  const page = Math.floor((step.value * 8) / 10) % pages.value
  return items.value.slice(page * 10, page * 10 + 10)
})
// Le numéro à l'écran, dans le style choisi par la boutique, sinon « dans la bio »
const phoneLine = computed(() => (store.value?.phone_shown ? `📞 ${store.value.phone_shown}` : '📌 Le numéro est dans la bio'))
const position = computed(() => (shown.value ? items.value.findIndex((i) => i.id === shown.value.id) + 1 : 0))

// Bandeau : les dernières commandes à tour de rôle, sinon le nombre de commandes
const tickIndex = ref(0)
let tick = setInterval(() => (tickIndex.value += 1), 4000)
const ticker = computed(() => {
  const list = live.value?.ticker ?? []
  if (list.length) return `✓ ${list[tickIndex.value % list.length].text} 🎉`
  if (live.value?.orders) return `✓ ${live.value.orders} commande${live.value.orders > 1 ? 's' : ''} pendant ce live 🎉`
  return live.value ? 'Envoie un ou plusieurs codes : A1 + A3' : 'Le live commence bientôt'
})
watch(() => live.value?.ticker?.[0]?.id, () => (tickIndex.value = 0))

// Bandeau qui défile en continu : toutes les dernières pièces prises à la suite
const tickerLine = computed(() => {
  const list = live.value?.ticker ?? []
  if (!props.effects || !list.length) return ticker.value
  return `${list.map((t) => `✓ ${t.text}`).join('   ·   ')}   🎉   Envoie ton code`
})
const tickerSeconds = computed(() => Math.max(12, Math.round(tickerLine.value.length * 0.22)))

// Pièce prise pendant le live : confettis, carte « Cocody vient de prendre A3 », compteur qui grossit
const party = ref(null)
let partyTimer
// (rien au chargement de l'écran : seulement une pièce prise pendant qu'il est allumé)
watch(() => (live.value ? live.value.ticker?.[0]?.id ?? 0 : undefined), (now, before) => {
  if (!props.effects || now === undefined || before === undefined || now <= before) return
  party.value = { key: now, text: live.value.ticker[0].text }
  clearTimeout(partyTimer)
  partyTimer = setTimeout(() => (party.value = null), 4500)
})
const COLORS = ['#facc15', '#22c55e', '#f43f5e', '#38bdf8', '#ffffff', '#fb923c']
const confetti = Array.from({ length: 36 }, (_, i) => ({
  i,
  style: { left: `${(i * 37) % 100}%`, background: COLORS[i % COLORS.length], animationDelay: `${(i % 9) * 0.09}s`, animationDuration: `${2.6 + (i % 5) * 0.25}s` },
}))
const sparks = Array.from({ length: 10 }, (_, i) => ({ i, style: { left: `${(i * 23 + 7) % 100}%`, animationDelay: `${i * 0.7}s` } }))
// Vente flash : chrono sur l'article montré (l'heure de fin vient du serveur, le décompte est fait ici)
const nowMs = ref(Date.now())
const second = setInterval(() => shown.value?.flash_ends_at && (nowMs.value = Date.now()), 1000)
const flashLeft = computed(() => (shown.value?.flash_ends_at ? Math.max(0, Math.round((new Date(shown.value.flash_ends_at).getTime() - nowMs.value) / 1000)) : 0))
const chrono = computed(() => `${String(Math.floor(flashLeft.value / 60)).padStart(2, '0')}:${String(flashLeft.value % 60).padStart(2, '0')}`)
// Produit avec tailles ou couleurs : la première option en pastilles (pièces restantes), les autres en texte
const sizes = computed(() => shown.value?.options?.[0] ?? null)
const others = computed(() => (shown.value?.options ?? []).slice(1))
const sizeNote = (v) => (v.remaining === 0 ? 'épuisé' : v.remaining === 1 ? 'dernière' : `${v.remaining} pièces`)
const stockWidth = (i) => `${Math.max(6, Math.min(100, Math.round((100 * i.remaining) / Math.max(1, i.quantity))))}%`


// ═══ Show du live : sons, entrée des articles, enchaînements, urgence, moments lancés depuis le téléphone ═══
// Tout est déduit de ce que l'écran reçoit déjà toutes les 2 secondes : rien de plus n'est demandé au serveur.
const show = computed(() => live.value?.show ?? null)
const settings = computed(() => show.value?.settings ?? { theme: 'fete', sound: true, music: false, light: false, goal: null })
const lightMode = computed(() => !!settings.value.light)
watch(() => [settings.value.sound, settings.value.music, props.effects], () => {
  setMuted(!settings.value.sound || !props.effects)
  setMusic(!!settings.value.music && !!settings.value.sound && props.effects)
}, { immediate: true })

// Article qui arrive : projecteur, souffle puis impact
const spotKey = ref(0)
watch(() => shown.value?.id, (id, before) => {
  if (!props.effects || id === undefined || before === undefined || id === before) return
  spotKey.value += 1
  play('whoosh')
  setTimeout(() => play('impact'), 380)
})

// Commandes à la suite : « 🔥 3 commandes d'affilée ! » (dans la minute)
const combo = ref(0)
let comboTimes = []
let comboTimer
watch(() => (live.value ? live.value.ticker?.[0]?.id ?? 0 : undefined), (id, before) => {
  if (!props.effects || id === undefined || before === undefined || id <= before) return
  play('ding')
  const t = Date.now()
  // Plusieurs pièces prises entre deux questions de l'écran : chacune compte
  const fresh = Math.max(1, (live.value.ticker ?? []).filter((x) => x.id > before).length)
  comboTimes = [...comboTimes.filter((x) => t - x < 60000), ...Array(fresh).fill(t)]
  combo.value = comboTimes.length
  if (combo.value >= 3) setTimeout(() => play('pop'), 300)
  clearTimeout(comboTimer)
  comboTimer = setTimeout(() => (combo.value = 0), 6000)
})

// Urgence : « Plus que 2 » (battement de cœur), puis épuisé (tampon)
watch(() => [shown.value?.id, shown.value?.remaining], ([id, n], [beforeId, was]) => {
  if (!props.effects || id !== beforeId || n === undefined || was === undefined || n >= was) return
  if (n === 0) play('stamp')
  else if (n <= 2) { play('beat'); setTimeout(() => play('beat'), 700) }
})

// Vente flash : fanfare au départ, tic-tac les 10 dernières secondes, sirène à la fin
watch(flashLeft, (n, was) => {
  if (!props.effects) return
  if (was === 0 && n > 0) play('fanfare')
  else if (n > 0 && n <= 10 && n < was) play('tick')
  else if (n === 0 && was > 0) play('buzzer')
})

// Moments lancés depuis le téléphone : montrés une seule fois, dans les 20 secondes
const moment = ref(null)
const countN = ref(0)
const wheelCanvas = ref(null)
const wheelTurn = ref(0)
const wheelDone = ref(false)
const goalShown = ref(0)
let seen = null
let timers = []
const later = (fn, ms) => timers.push(setTimeout(fn, ms))
function clearTimers() { timers.forEach(clearTimeout); timers = [] }
watch(() => show.value?.event?.id, (id) => {
  if (!props.effects || !id) return
  if (seen === null) { seen = id; if (Date.now() - id > 20000) return }
  if (id < seen || (id === seen && moment.value)) return
  seen = id
  if (Date.now() - id > 20000) return
  start(show.value.event)
}, { immediate: true })
function finish(ms) { later(() => (moment.value = null), ms) }
function start(event) {
  clearTimers()
  moment.value = { ...event }
  if (event.type === 'countdown') {
    countN.value = 3
    play('count')
    later(() => { countN.value = 2; play('count') }, 900)
    later(() => { countN.value = 1; play('count') }, 1800)
    later(() => { play('go'); moment.value = null }, 2700)
  } else if (event.type === 'party') {
    play('fanfare')
    party.value = { key: event.id, text: 'Merci pour vos commandes ! 🙏' }
    clearTimeout(partyTimer)
    partyTimer = setTimeout(() => (party.value = null), 4500)
    finish(4500)
  } else if (event.type === 'goal') {
    goalShown.value = 0
    play('whoosh')
    later(() => (goalShown.value = Math.min(1, (live.value?.orders ?? 0) / Math.max(1, event.data.goal))), 300)
    if ((live.value?.orders ?? 0) >= event.data.goal) later(() => play('fanfare'), 2200)
    finish(8000)
  } else if (event.type === 'match') {
    play('whoosh')
    later(() => play('pop'), 900)
    finish(8000)
  } else if (event.type === 'wheel') {
    wheelTurn.value = 0
    wheelDone.value = false
    nextTick(drawWheel)
    const slots = event.data.slots.length
    later(() => (wheelTurn.value = 6 * 360 + (360 - (event.data.winner + 0.5) * (360 / slots))), 100)
    let delay = 40
    let total = 0
    const clack = () => { play('wheel'); total += delay; delay *= 1.09; if (total < 5000) later(clack, delay) }
    clack()
    later(() => { wheelDone.value = true; play('fanfare') }, 5400)
    finish(12000)
  }
}
const WHEEL_COLORS = ['#facc15', '#f43f5e', '#22c55e', '#38bdf8', '#fb923c', '#a78bfa']
function drawWheel() {
  const c = wheelCanvas.value
  if (!c || !moment.value?.data?.slots) return
  const g = c.getContext('2d')
  const slots = moment.value.data.slots
  const seg = (Math.PI * 2) / slots.length
  g.clearRect(0, 0, 600, 600)
  slots.forEach((label, i) => {
    g.beginPath(); g.moveTo(300, 300); g.arc(300, 300, 290, i * seg - Math.PI / 2, (i + 1) * seg - Math.PI / 2); g.closePath()
    g.fillStyle = WHEEL_COLORS[i % WHEEL_COLORS.length]; g.fill(); g.strokeStyle = 'rgba(0,0,0,.25)'; g.lineWidth = 4; g.stroke()
    g.save(); g.translate(300, 300); g.rotate((i + 0.5) * seg - Math.PI / 2); g.textAlign = 'right'; g.fillStyle = '#0f172a'
    g.font = `800 ${slots.length > 8 ? 22 : 28}px system-ui, sans-serif`; g.fillText(label, 270, 9); g.restore()
  })
}
const wheelWinner = computed(() => (moment.value?.type === 'wheel' ? moment.value.data.slots[moment.value.data.winner] : ''))
const quarterRows = computed(() => {
  const rows = show.value?.quarters ?? []
  const max = Math.max(1, ...rows.map((r) => r.orders))
  return rows.map((r) => ({ ...r, width: `${Math.round((r.orders / max) * 100)}%` }))
})
const goal = computed(() => moment.value?.type === 'goal' ? moment.value.data.goal : settings.value.goal)

const left = (i) => (i.remaining === 0 ? (i.waiting ? `Liste d'attente : ${i.code}` : 'Épuisé') : i.remaining === 1 ? '🔥 Dernière pièce !' : i.remaining <= 3 ? `🔥 Plus que ${i.remaining} !` : `${i.remaining} pièces`)
</script>

<template>
  <div ref="root" class="live-display" :class="[`is-${layout}`, `th-${settings.theme}`, { fx: effects, 'is-light': lightMode }]" :style="{ fontSize: `${fontSize}px` }">
    <!-- Fond qui bouge doucement, avec quelques étincelles -->
    <template v-if="effects">
      <div class="ld-bg" aria-hidden="true" />
      <template v-if="!lightMode"><i v-for="sp in sparks" :key="sp.i" class="ld-spark" :style="sp.style" aria-hidden="true" /></template>
    </template>
    <div class="ld-stage" :style="{ bottom: `${freeBottom * 100}%` }">
      <!-- Comment commander (entre deux articles, ou avant le live) : le QR code en très grand -->
      <div v-if="showHow" class="ld-how">
        <div class="ld-how-text">
          <p class="ld-soon">{{ store?.name }} · {{ live ? 'en direct' : 'le live commence bientôt' }}</p>
          <h2>Comment commander ?</h2>
          <ol>
            <li><span>1</span>Note le code de l'article (A1, A2…)</li>
            <li><span>2</span>Envoie le code de l'article</li>
            <li><span>3</span>Ta taille t'est demandée s'il y en a</li>
            <li><span>4</span>Choisis ton quartier · on te livre ✅</li>
          </ol>
        </div>
        <div class="ld-qr ld-bio">
          <b>{{ phoneLine }}</b>
          <small>Paiement à la livraison</small>
        </div>
      </div>

      <!-- Vitrine : 10 articles en miniature, le code écrit en grand -->
      <div v-else-if="showGrid" class="ld-grid">
        <p class="ld-grid-head"><span>En direct maintenant</span><span class="ld-live"><i />{{ store?.name }}</span></p>
        <div class="ld-grid-items">
          <div v-for="i in gridItems" :key="i.id" class="ld-grid-item" :class="{ 'is-out': i.remaining === 0 }">
            <img v-if="i.image" :src="i.image" alt="" />
            <span v-if="i.remaining === 0" class="ld-grid-sold">VENDU</span>
            <b>{{ i.code }}</b>
          </div>
        </div>
        <p class="ld-grid-foot">Envoie le code de l'article · {{ phoneLine }}</p>
      </div>

      <!-- Article montré : la photo en grand, puis le prix et le bloc WhatsApp (QR code et numéro) -->
      <div v-else :key="shown.id" class="ld-product" :class="{ 'has-sizes': sizes }">
        <div class="ld-photo">
          <template v-if="shown.image">
            <!-- Photo entière (jamais coupée), posée sur la même photo floutée qui remplit le cadre -->
            <img :src="shown.image" alt="" class="ld-photo-bg" aria-hidden="true" />
            <img :src="shown.image" alt="" class="ld-photo-img" />
          </template>
          <span class="ld-left" :class="{ 'is-out': shown.remaining === 0, 'is-hot': shown.remaining > 0 && shown.remaining <= 3 }">{{ left(shown) }}</span>
          <span v-if="shown.remaining === 0" class="ld-stamp">VENDU</span>
          <!-- Stock qui baisse, le long du bas de la photo (ne prend pas de place au texte) -->
          <div v-if="effects && shown.remaining > 0 && shown.quantity > 0" class="ld-stock"><b :style="{ width: stockWidth(shown) }" /></div>
          <!-- Vente flash : ruban et chrono -->
          <template v-if="flashLeft > 0 && shown.remaining > 0">
            <span class="ld-ribbon">OFFRE LIVE</span>
            <span class="ld-chrono">⏱ {{ chrono }}</span>
          </template>
        </div>
        <div class="ld-info">
          <span class="ld-live"><i />EN DIRECT · {{ store?.name }}<b v-if="effects && live?.orders" :key="live.orders" class="ld-count">🛍️ {{ live.orders }}</b></span>
          <!-- Le code à côté du nom, hors de la photo : rien ne cache l'article -->
          <div class="ld-head">
            <div class="ld-titles">
              <p class="ld-name">{{ shown.title }}</p>
              <p v-if="shown.variant" class="ld-variant">{{ shown.variant }}</p>
            </div>
          </div>
          <!-- Tailles (ou couleurs) qui restent : une taille épuisée est barrée, VENDU seulement quand tout est parti -->
          <div v-if="sizes" class="ld-sizes">
            <span v-for="v in sizes.values" :key="v.value" class="ld-size" :class="{ 'is-out': v.remaining === 0, 'is-low': v.remaining === 1 }">{{ v.value }}<small>{{ sizeNote(v) }}</small></span>
          </div>
          <p v-for="o in others" :key="o.name" class="ld-others">{{ o.name }} : {{ o.values.filter((v) => v.remaining > 0).map((v) => v.value).join(', ') || 'épuisé' }}</p>
          <!-- Comment commander cet article : le code (et la taille), puis le numéro dans le style choisi -->
          <!-- Là où était le QR code : le code de l'article en très grand, puis comment commander -->
          <div class="ld-wa ld-send">
            <span class="ld-code ld-bigcode">{{ shown.code }}</span>
            <div class="ld-wa-text">
              <b>Envoie {{ shown.code }}<template v-if="sizes && sizes.values.length > 1"> + ta taille</template></b>
              <span>{{ phoneLine }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Bandeau : dernières commandes -->
      <div v-if="layout !== 'port'" class="ld-ticker">
        <span class="ld-ticker-text"><span :key="tickerLine" :class="{ 'ld-marquee': tickerLine !== ticker }" :style="{ animationDuration: `${tickerSeconds}s` }">{{ tickerLine }}</span></span>
        <span v-if="!showHow && !showGrid" class="ld-ticker-pos">{{ position }} / {{ items.length }}</span>
      </div>
      <template v-else>
        <p v-if="!showHow && !showGrid" class="ld-mini-ticker">{{ ticker }}</p>
      </template>

      <!-- Show : projecteur quand un article arrive, enchaînement de commandes, objectif en cours -->
      <div v-if="effects && spotKey" :key="`s${spotKey}`" class="ld-spot" aria-hidden="true" />
      <p v-if="effects && combo >= 2" :key="`k${combo}`" class="ld-combo">🔥 {{ combo }} commandes d'affilée !</p>
      <p v-if="effects && settings.goal && !moment" class="ld-goal-mini">🎯 {{ live?.orders ?? 0 }} / {{ settings.goal }}</p>

      <!-- Moments lancés depuis le téléphone -->
      <div v-if="moment && moment.type !== 'party'" class="ld-moment">
        <template v-if="moment.type === 'countdown'">
          <p class="ld-kicker">Article vedette dans…</p>
          <p :key="countN" class="ld-count-big">{{ countN }}</p>
        </template>
        <div v-else-if="moment.type === 'goal'" class="ld-goal">
          <p class="ld-kicker">Objectif du live</p>
          <h2>🎯 {{ goal }} commandes</h2>
          <div class="ld-goal-bar"><b :style="{ transform: `scaleX(${goalShown})` }" /></div>
          <p class="ld-goal-num">{{ live?.orders ?? 0 }} <small>/ {{ goal }}{{ (live?.orders ?? 0) >= goal ? ' · objectif atteint ! 🎉' : '' }}</small></p>
        </div>
        <div v-else-if="moment.type === 'match'" class="ld-match">
          <p class="ld-kicker">Quel quartier va gagner ?</p>
          <h2>🏆 Le match des quartiers</h2>
          <p v-if="!quarterRows.length" class="ld-kicker">Envoie ton code pour faire gagner ton quartier !</p>
          <div v-for="(r, i) in quarterRows" :key="r.name" class="ld-row" :style="{ animationDelay: `${i * 0.15}s` }">
            <span>{{ r.name }}</span><div class="ld-track"><b :style="{ width: r.width, animationDelay: `${0.3 + i * 0.15}s` }" /></div><strong>{{ r.orders }}</strong>
          </div>
        </div>
        <div v-else-if="moment.type === 'wheel'" class="ld-wheelbox">
          <p class="ld-kicker">Parmi les acheteurs du live</p>
          <h2>🎡 La roue de la chance</h2>
          <div class="ld-wheel"><canvas ref="wheelCanvas" width="600" height="600" :style="{ transform: `rotate(${wheelTurn}deg)` }" /></div>
          <p v-if="wheelDone" class="ld-winner">🎁 Gagné : l'acheteuse de {{ wheelWinner }} ! La boutique te contacte.</p>
        </div>
      </div>

      <!-- Pièce prise : confettis et carte qui glisse -->
      <template v-if="party">
        <div :key="`c${party.key}`" class="ld-party" aria-hidden="true"><i v-for="c in (lightMode ? confetti.slice(0, 12) : confetti)" :key="c.i" :style="c.style" /></div>
        <p :key="`t${party.key}`" class="ld-toast">🎉 {{ party.text }}</p>
      </template>
    </div>
  </div>
</template>

<style scoped>

/* ── Show : thèmes, projecteur, enchaînements, moments ── */
.th-wax .ld-bg { background: linear-gradient(120deg, #1c0a03 20%, #7c2d12 42%, #b45309 58%, #1c0a03 80%); }
.th-neon .ld-bg { background: linear-gradient(120deg, #020617 20%, #0e7490 42%, #a21caf 58%, #020617 80%); }
.th-luxe .ld-bg { background: linear-gradient(120deg, #0a0a0a 20%, #3f3420 45%, #6b5a2e 58%, #0a0a0a 80%); }
.is-light .ld-bg { animation: none; }
.ld-spot { position: absolute; inset: 0; z-index: 7; pointer-events: none; background: radial-gradient(circle 18em at 50% 45%, rgba(255, 255, 255, .35), transparent 65%); mix-blend-mode: screen; animation: ld-sweep 1.1s ease-in-out forwards; }
@keyframes ld-sweep { 0% { transform: translateX(-70%); opacity: 0 } 20% { opacity: 1 } 100% { transform: translateX(70%); opacity: 0 } }
.ld-combo { position: absolute; left: 50%; top: .6em; z-index: 8; transform: translateX(-50%); white-space: nowrap; font-size: 2.6em; font-weight: 900; color: #facc15; text-shadow: 0 .06em 0 rgba(0, 0, 0, .5); animation: ld-pop .5s cubic-bezier(.2, 1.6, .4, 1) both; }
.is-square .ld-combo, .is-port .ld-combo { font-size: 1.8em; }
.ld-goal-mini { position: absolute; left: 1em; bottom: 4.2em; z-index: 6; background: rgba(0, 0, 0, .55); color: #fff; font-weight: 900; font-size: 1.4em; padding: .25em .7em; border-radius: 1em; }
.ld-moment { position: absolute; inset: 0; z-index: 9; display: grid; place-items: center; align-content: center; gap: .6em; text-align: center; color: #fff; background: radial-gradient(ellipse at center, rgba(2, 6, 23, .7), rgba(2, 6, 23, .94)); padding: 1em; }
.ld-moment h2 { margin: 0; font-size: 3.4em; font-weight: 900; letter-spacing: -.02em; }
.ld-kicker { margin: 0; font-size: 1.5em; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; color: #fde68a; }
.ld-count-big { margin: 0; font-size: 18em; line-height: 1; font-weight: 900; color: #facc15; animation: ld-countpop .9s ease-out both; }
@keyframes ld-countpop { 0% { transform: scale(2.4); opacity: 0 } 25% { transform: scale(1); opacity: 1 } 100% { transform: scale(.75); opacity: 0 } }
.ld-goal { width: min(90%, 50em); display: grid; gap: .8em; }
.ld-goal-bar { height: 2.6em; border-radius: 2em; background: rgba(255, 255, 255, .15); overflow: hidden; border: .15em solid rgba(255, 255, 255, .25); }
.ld-goal-bar b { display: block; height: 100%; background: linear-gradient(90deg, #f43f5e, #facc15); transform-origin: left; transition: transform 2.2s cubic-bezier(.3, .9, .3, 1); }
.ld-goal-num { margin: 0; font-size: 4em; font-weight: 900; font-variant-numeric: tabular-nums; }
.ld-goal-num small { font-size: .45em; color: #fde68a; }
.ld-match { width: min(92%, 56em); display: grid; gap: .7em; }
.ld-row { display: grid; grid-template-columns: 9em 1fr 2.5em; align-items: center; gap: .8em; font-size: 1.7em; font-weight: 900; text-align: left; animation: ld-up .5s both; }
.ld-track { height: 1em; border-radius: 1em; background: rgba(255, 255, 255, .12); overflow: hidden; }
.ld-track b { display: block; height: 100%; border-radius: 1em; background: linear-gradient(90deg, #22c55e, #facc15); transform-origin: left; animation: ld-grow 1.4s cubic-bezier(.3, 1, .3, 1) both; }
.ld-row:nth-child(4) .ld-track b { background: linear-gradient(90deg, #f43f5e, #fb923c); }
.ld-row:nth-child(5) .ld-track b { background: linear-gradient(90deg, #38bdf8, #a78bfa); }
@keyframes ld-grow { from { transform: scaleX(0) } }
.ld-row strong { text-align: right; font-variant-numeric: tabular-nums; }
.ld-wheelbox { display: grid; gap: .6em; justify-items: center; }
.ld-wheel { position: relative; width: 24em; height: 24em; }
.is-land .ld-wheel { width: 20em; height: 20em; }
.ld-wheel canvas { width: 100%; height: 100%; transition: transform 5.2s cubic-bezier(.12, .8, .12, 1); }
.ld-wheel::after { content: ''; position: absolute; left: 50%; top: -1em; transform: translateX(-50%); border: 1em solid transparent; border-top: 2em solid #fff; }
.ld-winner { margin: 0; font-size: 2em; font-weight: 900; color: #facc15; animation: ld-pop .6s cubic-bezier(.2, 1.6, .4, 1) both; }
@media (prefers-reduced-motion: reduce) { .ld-spot, .ld-count-big, .ld-combo { animation: none; } }
/* ── Effets (classe fx) : transform et opacity seulement ── */
.ld-bg { position: absolute; inset: -40%; background: linear-gradient(120deg, #020617 20%, #3b0764 42%, #881337 58%, #020617 80%); opacity: .75; animation: ld-drift 22s ease-in-out infinite alternate; }
@keyframes ld-drift { to { transform: translate(12%, 8%) rotate(10deg); } }
.ld-spark { position: absolute; bottom: -1em; width: .35em; height: .35em; border-radius: 50%; background: #fde68a; opacity: 0; animation: ld-rise 7s linear infinite; }
@keyframes ld-rise { 0% { transform: translateY(0) scale(.6); opacity: 0 } 15% { opacity: .45 } 100% { transform: translateY(-60em) scale(1.2); opacity: 0 } }
.fx .ld-product .ld-photo { animation: ld-slide .8s cubic-bezier(.2, 1.3, .4, 1) both; }
.fx .ld-product .ld-code { animation: ld-pop .6s .3s cubic-bezier(.2, 1.6, .4, 1) both; }
.fx .ld-product .ld-titles { animation: ld-up .6s .4s both; }
.fx .ld-product .ld-price { animation: ld-pop .7s .55s cubic-bezier(.2, 1.6, .4, 1) both; transform-origin: left center; }
.fx .ld-photo-img { animation: ld-zoom 9s ease-in-out infinite alternate; }
.fx .ld-live i { animation: ld-beat 1.2s infinite; }
.fx .ld-left.is-hot { animation: ld-glow 1.2s infinite; }
.fx .ld-stamp { animation: ld-slam .5s cubic-bezier(.2, 1.4, .4, 1) both; }
@keyframes ld-slide { from { transform: translateX(-40%) rotate(-4deg); opacity: 0 } }
@keyframes ld-pop { 0% { transform: scale(.2); opacity: 0 } 70% { transform: scale(1.12) } 100% { transform: scale(1) } }
@keyframes ld-up { from { transform: translateY(1em); opacity: 0 } }
@keyframes ld-zoom { to { transform: scale(1.06) } }
@keyframes ld-beat { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, .7) } 70%, 100% { box-shadow: 0 0 0 .6em rgba(239, 68, 68, 0) } }
@keyframes ld-glow { 50% { transform: scale(1.08) } }
@keyframes ld-slam { from { transform: translate(-50%, -50%) rotate(-14deg) scale(3); opacity: 0 } }
.ld-count { display: inline-block; margin-left: .6em; padding: .1em .5em; border-radius: 1em; background: rgba(255, 255, 255, .15); color: #4ade80; font-weight: 900; animation: ld-bump .6s cubic-bezier(.2, 1.6, .4, 1); }
@keyframes ld-bump { 0% { transform: scale(1.8) } 100% { transform: scale(1) } }
.ld-stock { position: absolute; left: 0; right: 0; bottom: 0; height: .55em; background: rgba(0, 0, 0, .35); overflow: hidden; }
.ld-stock b { position: relative; display: block; height: 100%; background: linear-gradient(90deg, #f97316, #facc15); overflow: hidden; }
.ld-stock b::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .6), transparent); animation: ld-shine 2s linear infinite; }
@keyframes ld-shine { from { transform: translateX(-100%) } to { transform: translateX(100%) } }
.is-land .ld-stock { height: .8em; }
/* Vente flash : ruban brillant, chrono, ancien prix barré en direct */
.ld-ribbon { position: absolute; top: 1.6em; left: -3.2em; transform: rotate(-35deg); background: linear-gradient(90deg, #facc15, #fff7ae, #facc15); color: #7c2d12; font-weight: 900; padding: .3em 3.6em; box-shadow: 0 .3em .8em rgba(0, 0, 0, .3); z-index: 2; }
.ld-chrono { position: absolute; top: .8em; right: .8em; background: #fff; color: #be123c; font-weight: 900; border-radius: .4em; padding: .15em .5em; font-variant-numeric: tabular-nums; box-shadow: 0 .3em .8em rgba(0, 0, 0, .3); z-index: 2; }
.fx .ld-chrono { animation: ld-glow 1s infinite; }
.is-land .ld-ribbon { font-size: 1.6em; }
.is-land .ld-chrono { font-size: 2.6em; }
.is-square .ld-ribbon { font-size: 1.1em; }
.is-square .ld-chrono { font-size: 1.7em; }
.is-port .ld-ribbon { font-size: 1em; }
.is-port .ld-chrono { font-size: 1.6em; top: auto; right: auto; bottom: 1em; left: 1em; }
.ld-price.is-flash s { position: relative; text-decoration: none; }
.ld-price.is-flash s::after { content: ''; position: absolute; left: 0; top: 50%; height: .12em; width: 100%; background: #ef4444; }
.fx .ld-price.is-flash s::after { animation: ld-cross 1.2s .6s both; }
@keyframes ld-cross { from { width: 0 } }
.ld-marquee { display: inline-block; padding-left: 100%; animation: ld-marquee linear infinite; }
@keyframes ld-marquee { to { transform: translateX(-100%) } }
.ld-party { position: absolute; inset: 0; overflow: hidden; pointer-events: none; z-index: 5; }
.ld-party i { position: absolute; top: -2em; width: .9em; height: 1.4em; border-radius: .15em; animation: ld-fall 3s linear both; }
@keyframes ld-fall { 0% { transform: translateY(0) rotate(0); opacity: 1 } 80% { opacity: 1 } 100% { transform: translateY(70em) rotate(720deg); opacity: 0 } }
.ld-toast { position: absolute; top: 1em; right: 1em; max-width: 70%; z-index: 6; background: #fff; color: #0f172a; font-weight: 800; border-radius: 1em; padding: .6em 1em; box-shadow: 0 .8em 2em rgba(0, 0, 0, .35); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; animation: ld-toast 4.5s ease-in-out both; }
.is-land .ld-toast { font-size: 1.9em; }
.is-square .ld-toast { font-size: 1.2em; }
.is-port .ld-toast { font-size: 1.1em; left: 1em; right: 1em; max-width: none; }
@keyframes ld-toast { 0% { transform: translateY(-150%); opacity: 0 } 10%, 85% { transform: translateY(0); opacity: 1 } 100% { transform: translateY(-150%); opacity: 0 } }
@media (prefers-reduced-motion: reduce) {
  .live-display *, .live-display *::after { animation: none !important; }
  .ld-party, .ld-spark { display: none; }
}

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
.ld-from { align-self: center; color: #fde68a; font-weight: 800; font-size: 1.6em; }
.ld-sizes { display: flex; flex-wrap: wrap; gap: .4em; flex-shrink: 0; }
.ld-size { min-width: 2.6em; text-align: center; border-radius: .5em; padding: .25em .5em; background: #fff; color: #0f172a; font-weight: 900; line-height: 1.05; }
.ld-size small { display: block; font-size: .42em; font-weight: 800; color: #16a34a; }
.ld-size.is-low small { color: #ea580c; }
.ld-size.is-out { background: rgba(255, 255, 255, .18); color: rgba(255, 255, 255, .55); text-decoration: line-through; }
.ld-size.is-out small { color: rgba(255, 255, 255, .55); }
.ld-others { color: #e2e8f0; font-weight: 700; }
.is-land .ld-size { font-size: 1.9em; }
.is-land .ld-others { font-size: 1.35em; }
.is-square .ld-size { font-size: 1.2em; }
.is-square .ld-others { font-size: .9em; }
.is-port .ld-size { font-size: 1.1em; }
.is-port .ld-others { font-size: .85em; }
/* Produit avec tailles : prix et QR code un peu plus petits pour que tout tienne au-dessus du bandeau */
.is-land .has-sizes .ld-info { gap: .7em; padding-top: 1.6em; }
.is-land .has-sizes .ld-price b { font-size: 5.2em; }
.is-land .has-sizes .ld-wa { padding: .7em; }
.is-land .has-sizes .ld-wa img { width: 8.5em; }
.is-land .has-sizes .ld-site img { width: 4.5em; }
.is-land .has-sizes .ld-site { padding-top: .4em; padding-bottom: .4em; }
.is-square .has-sizes .ld-price b { font-size: 3.2em; }
.is-square .has-sizes .ld-wa img { width: 7.5em; }
.ld-price b { color: #facc15; font-weight: 900; letter-spacing: -.03em; line-height: 1; }
.ld-price s { color: #94a3b8; font-weight: 700; }

/* Envoie le code (sans QR code ni le mot WhatsApp) */
.ld-send { margin-top: auto; }
/* Le code en très grand dans le bloc du bas (à la place de l'ancien QR code) */
.live-display .ld-send { flex-direction: column; justify-content: center; text-align: center; }
.live-display .ld-send .ld-bigcode { line-height: .9; border-width: .05em; color: #fff; display: inline-block; align-self: center; }
.is-land .ld-send { flex: 1 1 auto; min-height: 0; gap: .6em !important; }
.live-display .ld-send .ld-wa-text { flex: none; width: 100%; }
.live-display.is-land .ld-send { container-type: size; }
.live-display.is-land .ld-send .ld-bigcode { font-size: min(48cqh, 34cqi); }
.live-display.is-square .ld-send .ld-bigcode { font-size: 6em; }
.live-display.is-port .ld-send .ld-bigcode { font-size: 4.2em; }
.ld-bio { width: auto !important; max-width: 46%; padding: 1.2em !important; text-align: center; }
.ld-bio b { white-space: normal !important; line-height: 1.2; font-size: 2.4em !important; overflow-wrap: anywhere; }
.is-port .ld-bio, .is-square .ld-bio { max-width: none; }
.is-port .ld-bio b, .is-square .ld-bio b { font-size: 1.6em !important; }
.ld-send span { white-space: normal; overflow-wrap: anywhere; }
/* Vitrine : 10 articles et leur code */
.ld-grid { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: .8em; padding: 1.2em 1.6em; }
.ld-grid-head { display: flex; justify-content: space-between; align-items: center; font-weight: 900; font-size: 2em; }
.ld-grid-head .ld-live { font-size: .5em; }
.ld-grid-items { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(5, 1fr); grid-template-rows: repeat(2, 1fr); gap: .8em; }
.ld-grid-item { position: relative; min-height: 0; border-radius: .6em; overflow: hidden; background: linear-gradient(160deg, #f97316, #be123c); }
.ld-grid-item img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: #fde7c7; }
.ld-grid-item b { position: absolute; left: .3em; bottom: .3em; background: #fff; color: #0f172a; font-weight: 900; font-size: 1.5em; line-height: 1.15; padding: 0 .3em; border-radius: .25em; box-shadow: 0 .1em .3em rgba(0, 0, 0, .3); }
.ld-grid-item.is-out img { filter: grayscale(1) opacity(.5); }
.ld-grid-sold { position: absolute; left: 50%; top: 40%; transform: translate(-50%, -50%) rotate(-12deg); color: #dc2626; background: #fff; border: .12em solid #dc2626; border-radius: .2em; font-weight: 900; font-size: 1.4em; padding: 0 .3em; }
.ld-grid-foot { background: #facc15; color: #0f172a; border-radius: .4em; text-align: center; font-weight: 900; font-size: 1.5em; padding: .3em; }
.is-port .ld-grid-items { grid-template-columns: repeat(2, 1fr); grid-template-rows: repeat(5, 1fr); }
.is-square .ld-grid-items { grid-template-columns: repeat(4, 1fr); grid-template-rows: repeat(3, 1fr); }
.is-port .ld-grid-item b, .is-square .ld-grid-item b { font-size: 1.1em; }

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
.fx .ld-ticker { position: relative; }
.fx .ld-ticker-text { text-overflow: clip; }
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
