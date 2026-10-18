// Application livreur sans réseau : les dernières données reçues restent sur le téléphone, et les étapes faites
// hors connexion (colis récupéré, en route, livré, pas livré) attendent dans une file envoyée dès que le réseau revient,
// dans l'ordre, avec leur heure réelle.
import { reactive } from 'vue'
import http, { errorMessage } from './http'

const CACHE_PREFIX = 'jm-livreur:'
const DB_NAME = 'jm-livreur'
const OUTBOX = 'outbox'

export const offline = reactive({
  online: navigator.onLine,
  pending: 0, // étapes en attente d'envoi
  failed: [], // étapes refusées par le serveur : { id, reference, label, error }
  syncing: false,
  savedAt: null, // heure des données affichées quand elles viennent du téléphone
  synced: 0, // augmente à chaque étape envoyée : les pages se rechargent
})

// Étapes possibles depuis chaque statut (comme CourierDesk::NEXT côté serveur)
const NEXT = {
  awaiting_pickup: ['in_transit'],
  in_transit: ['out_for_delivery', 'delivered', 'failed'],
  out_for_delivery: ['delivered', 'failed'],
  failed: ['out_for_delivery', 'returning'],
  rescheduled: ['out_for_delivery', 'returning'],
}
const LABELS = {
  in_transit: 'Colis récupéré',
  out_for_delivery: 'En cours de livraison',
  delivered: 'Livrée',
  failed: 'Échec',
  rescheduled: 'Reportée',
  returning: 'Retour en cours',
}

// ── Données gardées sur le téléphone ──

function save(key, value) {
  try {
    localStorage.setItem(CACHE_PREFIX + key, JSON.stringify({ at: Date.now(), value }))
  } catch {
    // stockage plein ou refusé : l'application marche sans
  }
}

function saved(key) {
  try {
    return JSON.parse(localStorage.getItem(CACHE_PREFIX + key))
  } catch {
    return null
  }
}

const isNetworkError = (e) => e?.isAxiosError && !e.response

// Profil du livreur connecté : l'application s'ouvre sans réseau
export const saveProfile = (profile) => save('profile', profile)
export const savedProfile = () => saved('profile')?.value ?? null

/**
 * Lecture depuis le serveur, gardée sur le téléphone ; sans réseau, la dernière version gardée.
 */
export async function cachedGet(url, key = url) {
  try {
    const data = (await http.get(url)).data.data
    save(key, data)
    offline.online = true
    offline.savedAt = null
    return data
  } catch (e) {
    const copy = isNetworkError(e) ? saved(key) : null
    if (!copy) throw e
    offline.online = false
    offline.savedAt = copy.at
    return copy.value
  }
}

/**
 * Missions : la liste et le détail de chacune (pour les ouvrir sans réseau), avec les étapes pas encore envoyées.
 */
export async function loadMissions() {
  const list = await cachedGet('/courier/missions?full=1', 'missions')
  if (offline.savedAt === null) list.filter((m) => m.next_steps).forEach((m) => save(`mission:${m.id}`, m))
  return Promise.all(list.map((m) => withPendingSteps(m)))
}

export async function loadMission(id) {
  return withPendingSteps(await cachedGet(`/courier/missions/${id}`, `mission:${id}`))
}

// Les étapes en attente s'appliquent à la mission affichée : le livreur voit où il en est
async function withPendingSteps(mission) {
  const steps = (await all()).filter((item) => item.missionId === mission.id && !item.error)
  return steps.reduce((m, item) => {
    const status = item.status === 'failed' && item.fields.rescheduled_to ? 'rescheduled' : item.status
    return { ...m, status, status_label: LABELS[status], next_steps: NEXT[status] ?? [], pending_sync: true,
      ...(status === 'delivered' ? { collected_amount: Number(item.fields.collected_amount ?? 0) } : {}) }
  }, mission)
}

// ── File d'attente des étapes (IndexedDB : les photos y tiennent) ──

let dbPromise = null
function db() {
  dbPromise ??= new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1)
    request.onupgradeneeded = () => request.result.createObjectStore(OUTBOX, { keyPath: 'id', autoIncrement: true })
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
  return dbPromise
}

async function tx(mode, run) {
  const database = await db()
  return new Promise((resolve, reject) => {
    const t = database.transaction(OUTBOX, mode)
    const result = run(t.objectStore(OUTBOX))
    t.oncomplete = () => resolve(result?.result)
    t.onerror = () => reject(t.error)
  })
}

const all = () => tx('readonly', (store) => store.getAll()).then((items) => items ?? []).catch(() => [])
const put = (item) => tx('readwrite', (store) => store.put(item))
const remove = (id) => tx('readwrite', (store) => store.delete(id))

async function refreshCounts() {
  const items = await all()
  offline.pending = items.filter((i) => !i.error).length
  offline.failed = items.filter((i) => i.error).map(({ id, reference, status, error }) => ({ id, reference, label: LABELS[status] ?? status, error }))
}

/**
 * Une mission a-t-elle des étapes pas encore envoyées ? (les suivantes doivent attendre derrière elles)
 */
export async function hasPendingSteps(missionId) {
  return (await all()).some((i) => i.missionId === missionId)
}

/**
 * Met une étape dans la file : elle partira dès que possible, avec l'heure où elle a été faite.
 */
export async function queueStep(mission, status, fields, photo) {
  await put({ missionId: mission.id, reference: mission.reference, status, fields, photo: photo ?? null,
    offlineId: crypto.randomUUID(), occurredAt: new Date().toISOString(), error: null })
  await refreshCounts()
  flush()
}

/**
 * Étape d'une mission : envoyée tout de suite, ou gardée dans la file sans réseau (ou derrière des étapes de la même
 * mission déjà en attente : l'ordre compte). Renvoie true si elle attend sur le téléphone.
 */
export async function sendStep(mission, status, fields = {}, photo = null) {
  if (!offline.online || offline.savedAt !== null || await hasPendingSteps(mission.id)) {
    await queueStep(mission, status, fields, photo)
    return true
  }
  const body = new FormData()
  body.append('status', status)
  Object.entries(fields).forEach(([k, v]) => body.append(k, v))
  if (photo) body.append('photo', photo, 'photo.jpg')
  try {
    await http.post(`/courier/missions/${mission.id}/steps`, body)
    return false
  } catch (e) {
    if (!isNetworkError(e)) throw e
    // Le réseau a lâché pendant l'envoi
    await queueStep(mission, status, fields, photo)
    return true
  }
}

/**
 * Ordre de la tournée choisi par le livreur : gardé sur le téléphone pour la journée, envoyé à la boutique si possible.
 */
export function saveRoute(ids) {
  save('route', { day: new Date().toLocaleDateString('sv-SE'), ids })
  http.put('/courier/route', { ids }).catch(() => {})
}

export function savedRoute() {
  const route = saved('route')?.value
  return route?.day === new Date().toLocaleDateString('sv-SE') ? route.ids : null
}

/**
 * Envoie la file dans l'ordre. Plus de réseau : on s'arrête et on réessaiera. Étape refusée (mission annulée,
 * code faux…) : elle reste affichée avec le motif, et les étapes suivantes de la même mission attendent.
 */
export async function flush() {
  if (offline.syncing) return
  offline.syncing = true
  try {
    const blocked = new Set()
    for (const item of await all()) {
      if (item.error || blocked.has(item.missionId)) {
        blocked.add(item.missionId)
        continue
      }
      const body = new FormData()
      body.append('status', item.status)
      Object.entries(item.fields).forEach(([k, v]) => v !== null && v !== undefined && v !== '' && body.append(k, v))
      if (item.photo) body.append('photo', item.photo, 'photo.jpg')
      body.append('offline_id', item.offlineId)
      body.append('occurred_at', item.occurredAt)
      try {
        await http.post(`/courier/missions/${item.missionId}/steps`, body)
        await remove(item.id)
        offline.online = true
        offline.synced++
      } catch (e) {
        if (isNetworkError(e) || e.response?.status >= 500 || [419, 429].includes(e.response?.status)) {
          offline.online = !isNetworkError(e)
          break
        }
        await put({ ...item, error: errorMessage(e) })
        blocked.add(item.missionId)
      }
    }
  } finally {
    offline.syncing = false
    await refreshCounts()
  }
}

// Étape refusée : le livreur l'enlève après avoir vu le motif (la mission se recharge depuis le serveur)
export async function dismissFailed(id) {
  await remove(id)
  await refreshCounts()
}

/**
 * Photo réduite avant envoi (réseau lent, file d'attente plus légère) : 1600 px au plus, JPEG.
 */
export async function shrinkPhoto(file, max = 1600) {
  if (!file || !file.type?.startsWith('image/')) return file
  try {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height))
    const canvas = Object.assign(document.createElement('canvas'), { width: Math.round(bitmap.width * scale), height: Math.round(bitmap.height * scale) })
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8))
    return blob && blob.size < file.size ? new File([blob], 'photo.jpg', { type: 'image/jpeg' }) : file
  } catch {
    return file
  }
}

/**
 * À la sortie : on efface ce que le téléphone gardait (un autre livreur peut s'y connecter).
 */
export async function forgetAll() {
  Object.keys(localStorage).filter((k) => k.startsWith(CACHE_PREFIX)).forEach((k) => localStorage.removeItem(k))
  await tx('readwrite', (store) => store.clear()).catch(() => {})
  await refreshCounts()
}

let started = false
export function startOfflineSync() {
  if (started) return
  started = true
  window.addEventListener('online', () => {
    offline.online = true
    flush()
  })
  window.addEventListener('offline', () => (offline.online = false))
  setInterval(() => offline.pending && flush(), 30000)
  refreshCounts().then(() => offline.pending && flush())
}

export const isOffline = isNetworkError
