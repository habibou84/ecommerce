<script setup>
// Carte OpenStreetMap (MapLibre), chargée seulement quand elle s'affiche : points numérotés, départ, arrivée,
// points approximatifs (centre du quartier), trait de l'ordre de passage ; ou épingle à placer (mode « pick »).
import 'maplibre-gl/dist/maplibre-gl.css'
import * as maplibregl from 'maplibre-gl'
// MapLibre 6 : le worker (fichier à part, autonome) est copié par Vite ; sans cette adresse, la bibliothèque le
// chercherait à côté du fichier compilé, où il n'est pas
import workerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url'

maplibregl.setWorkerUrl(workerUrl)
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { boot } from '../lib/boot'

const props = defineProps({
  // [{ id, lat, lng, label, kind: 'start' | 'stop' | 'approx' | 'pin', last: bool }]
  points: { type: Array, default: () => [] },
  // Relie les arrêts dans l'ordre (à vol d'oiseau)
  line: { type: Boolean, default: false },
  // Mode épingle : toucher la carte place l'épingle (émet « pick »)
  pick: { type: Boolean, default: false },
  selected: { default: null },
})
const emit = defineEmits(['select', 'pick'])

const el = ref(null)
const failed = ref(false)
const noTiles = ref(false) // fond de carte injoignable (pas de réseau) : les points restent visibles
let map = null
let markers = []
const ABIDJAN = [-4.0083, 5.36]

function markerEl(p) {
  const node = document.createElement('button')
  node.type = 'button'
  node.setAttribute('aria-label', p.title || p.label || 'Point')
  const base = 'flex items-center justify-center rounded-full font-bold shadow-md text-xs '
  if (p.kind === 'start') node.className = base + 'h-5 w-5 border-[3px] border-white bg-green-600'
  else if (p.kind === 'pin') node.className = base + 'h-6 w-6 border-[3px] border-white bg-red-600'
  else if (p.kind === 'approx') node.className = base + 'h-7 w-7 border-2 border-dashed border-emerald-700 bg-white text-emerald-800'
  else node.className = base + 'h-7 w-7 border-2 border-white bg-emerald-700 text-white'
  if (p.last) node.className += ' ring-4 ring-amber-400'
  if (props.selected !== null && p.id === props.selected) node.className += ' scale-125'
  node.textContent = p.label ?? ''
  if (p.id !== undefined) node.addEventListener('click', (e) => { e.stopPropagation(); emit('select', p.id) })
  return node
}

function draw() {
  if (!map) return
  markers.forEach((m) => m.remove())
  markers = props.points.filter((p) => p.lat != null && p.lng != null).map((p) => new maplibregl.Marker({ element: markerEl(p) }).setLngLat([p.lng, p.lat]).addTo(map))
  const coords = props.points.filter((p) => p.lat != null && p.kind !== 'pin').map((p) => [p.lng, p.lat])
  const data = { type: 'Feature', geometry: { type: 'LineString', coordinates: props.line && coords.length > 1 ? coords : [] } }
  if (map.getSource('route')) map.getSource('route').setData(data)
}

function fit() {
  const pts = props.points.filter((p) => p.lat != null)
  if (!map || !pts.length) return
  if (pts.length === 1) return map.jumpTo({ center: [pts[0].lng, pts[0].lat], zoom: 15 })
  const b = new maplibregl.LngLatBounds()
  pts.forEach((p) => b.extend([p.lng, p.lat]))
  map.fitBounds(b, { padding: 48, maxZoom: 15, duration: 0 })
}

onMounted(() => {
  try {
    map = new maplibregl.Map({
      container: el.value,
      style: boot.mapStyleUrl || 'https://tiles.openfreemap.org/styles/liberty',
      center: ABIDJAN,
      zoom: 11,
      attributionControl: { compact: true },
    })
  } catch {
    failed.value = true
    return
  }
  map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right')
  map.on('load', () => {
    map.addSource('route', { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: [] } } })
    map.addLayer({ id: 'route', type: 'line', source: 'route', paint: { 'line-color': '#047857', 'line-width': 2.5, 'line-dasharray': [2, 2], 'line-opacity': 0.8 } })
    draw()
  })
  map.on('error', () => { if (!map.isStyleLoaded()) noTiles.value = true })
  map.on('click', (e) => props.pick && emit('pick', { lat: +e.lngLat.lat.toFixed(6), lng: +e.lngLat.lng.toFixed(6) }))
  draw()
  fit()
})
onUnmounted(() => map?.remove())
watch(() => [props.points, props.selected], draw, { deep: true })
watch(() => props.points.map((p) => `${p.lat},${p.lng}`).join('|'), fit)

defineExpose({ fit, flyTo: (lat, lng) => map?.flyTo({ center: [lng, lat], zoom: 16 }) })
</script>

<template>
  <div class="relative h-full w-full overflow-hidden rounded-xl bg-slate-100">
    <div ref="el" class="h-full w-full" />
    <p v-if="noTiles && !failed" class="absolute inset-x-2 bottom-2 rounded-lg bg-white/90 px-3 py-1.5 text-center text-xs text-slate-600 shadow">Fond de carte indisponible (réseau) : les points restent placés.</p>
    <p v-if="failed" class="absolute inset-0 flex items-center justify-center p-4 text-center text-sm text-slate-500">La carte ne peut pas s'afficher sur ce téléphone.</p>
  </div>
</template>
