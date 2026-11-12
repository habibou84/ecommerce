// Sons et musiques du show du live. Cinq morceaux de Jibiat Marchand (fichiers de public/audio/live, qui
// s'enchaînent) et cinq musiques fabriquées par le navigateur (Web Audio). Chaque ambiance a ses sons des moments.
// Le navigateur ne joue un son qu'après un geste (« Activer le son ») ; TikTok LIVE Studio et OBS l'autorisent d'office.
let ac = null
let master = null
let musicBus = null
let sfxBus = null
let noiseBuffer = null
let shaperCurve = null
let muted = false
let ambiance = 'aqui'
const VOLUME = 0.9

// Chansons : fichier, et jeu de sons des moments qui va avec
const TRACKS = {
  aqui: '/audio/live/c-est-a-qui-est-a.mp3?v=1',
  style: '/audio/live/le-style-au-rendez-vous.mp3?v=1',
  lagune: '/audio/live/soleil-sur-la-lagune.mp3?v=1',
  gravity: '/audio/live/concrete-gravity.mp3?v=1',
  noon: '/audio/live/high-noon-in-plateau.mp3?v=1',
}
const TRACK_ORDER = Object.keys(TRACKS)
export const AMBIANCES = [...TRACK_ORDER, 'techno', 'coupe', 'zouglou', 'afro', 'douce']

export function audioReady() {
  return !!ac && ac.state === 'running'
}

/** À appeler au toucher (« Activer le son ») ; sans geste, essaie quand même (sources navigateur d'OBS, LIVE Studio) */
export function unlockAudio() {
  try {
    if (!ac) {
      const Ctx = window.AudioContext || window.webkitAudioContext
      if (!Ctx) return false
      ac = new Ctx()
      const comp = ac.createDynamicsCompressor()
      comp.threshold.value = -14
      comp.ratio.value = 4
      master = ac.createGain()
      master.gain.value = muted ? 0 : VOLUME
      master.connect(comp)
      comp.connect(ac.destination)
      musicBus = ac.createGain()
      musicBus.gain.value = 0.55
      musicBus.connect(master)
      sfxBus = ac.createGain()
      sfxBus.gain.value = 0.9
      sfxBus.connect(master)
    }
    ac.resume?.()
    restartMusic()
    return true
  } catch {
    return false
  }
}

export function setMuted(value) {
  muted = !!value
  if (master) master.gain.value = muted ? 0 : VOLUME
}

export function setAmbiance(name) {
  const next = AMBIANCES.includes(name) ? name : 'aqui'
  if (next === ambiance) return
  ambiance = next
  restartMusic()
}

const ok = () => ac && !muted && ac.state === 'running'
const now = () => ac.currentTime
const mf = (m) => 440 * Math.pow(2, (m - 69) / 12)

// ── Briques de son ──
function noiseBuf() {
  if (!noiseBuffer) {
    const len = ac.sampleRate * 2
    noiseBuffer = ac.createBuffer(1, len, ac.sampleRate)
    const d = noiseBuffer.getChannelData(0)
    for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1
  }
  return noiseBuffer
}
function env(g, t, a, peak, dec) {
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(peak, t + a)
  g.gain.exponentialRampToValueAtTime(0.0001, t + a + dec)
}
function osc(type, f, t, dur, peak, o = {}) {
  const x = ac.createOscillator()
  const g = ac.createGain()
  const a = o.a ?? 0.005
  x.type = type
  x.frequency.setValueAtTime(f, t)
  if (o.f2) x.frequency.exponentialRampToValueAtTime(o.f2, t + (o.glide ?? dur))
  if (o.detune) x.detune.value = o.detune
  env(g, t, a, peak, dur)
  let node = x
  if (o.lp) {
    const fl = ac.createBiquadFilter()
    fl.type = 'lowpass'
    fl.frequency.setValueAtTime(o.lp, t)
    if (o.lp2) fl.frequency.exponentialRampToValueAtTime(o.lp2, t + dur)
    fl.Q.value = o.q ?? 1
    node.connect(fl)
    node = fl
  }
  node.connect(g).connect(o.dest || sfxBus)
  x.start(t)
  x.stop(t + a + dur + 0.05)
}
function nz(t, dur, peak, o = {}) {
  const s = ac.createBufferSource()
  const fl = ac.createBiquadFilter()
  const g = ac.createGain()
  const a = o.a ?? 0.002
  s.buffer = noiseBuf()
  fl.type = o.type || 'highpass'
  fl.frequency.setValueAtTime(o.f || 7000, t)
  if (o.f2) fl.frequency.exponentialRampToValueAtTime(o.f2, t + dur)
  fl.Q.value = o.q ?? 1
  env(g, t, a, peak, dur)
  s.connect(fl).connect(g).connect(o.dest || sfxBus)
  s.start(t, Math.random() * 1.5, a + dur + 0.05)
}

// ── Instruments ──
const kick = (t, v = 1, d) => { osc('sine', 150, t, 0.34, v * 0.9, { f2: 42, glide: 0.12, dest: d }); osc('square', 900, t, 0.008, v * 0.08, { dest: d }) }
const snare = (t, v = 1, d) => { nz(t, 0.16, v * 0.5, { type: 'bandpass', f: 1900, q: 0.8, dest: d }); osc('triangle', 190, t, 0.09, v * 0.3, { f2: 130, dest: d }) }
const clap = (t, v = 1, d) => {
  for (let k = 0; k < 3; k++) nz(t + k * 0.011, 0.02, v * 0.5, { type: 'bandpass', f: 1300, q: 1.2, dest: d })
  nz(t + 0.033, 0.17, v * 0.45, { type: 'bandpass', f: 1200, dest: d })
}
const hat = (t, v = 1, open = false, d) => nz(t, open ? 0.2 : 0.03, v * 0.22, { type: 'highpass', f: 8000, dest: d })
const shaker = (t, v = 1, d) => nz(t, 0.055, v * 0.16, { type: 'bandpass', f: 6500, q: 1.5, a: 0.015, dest: d })
const conga = (t, f, v = 1, d) => { osc('sine', f * 1.5, t, 0.26, v * 0.6, { f2: f, glide: 0.03, dest: d }); nz(t, 0.015, v * 0.14, { type: 'bandpass', f: 2500, dest: d }) }
const rim = (t, v = 1, d) => { osc('triangle', 1700, t, 0.03, v * 0.22, { dest: d }); nz(t, 0.012, v * 0.2, { type: 'bandpass', f: 3000, q: 3, dest: d }) }
const cowbell = (t, v = 1, d) => { osc('square', 545, t, 0.16, v * 0.06, { lp: 2500, dest: d }); osc('square', 815, t, 0.16, v * 0.05, { lp: 2500, dest: d }) }
const bass = (t, f, dur, v = 1, cut = 600, d) => osc('sawtooth', f, t, dur, v * 0.3, { lp: cut * 2.2, lp2: cut, q: 4, dest: d })
const lead = (t, f, dur, v = 1, cut = 1500, d) => osc('square', f, t, dur, v * 0.5, { lp: cut, q: 6, dest: d })
const keys = (t, fs, dur, v = 1, d) => fs.forEach((f) => { osc('triangle', f, t, dur, v, { dest: d }); osc('sine', f * 2, t, dur * 0.6, v * 0.3, { dest: d }) })
const pad = (t, fs, dur, v = 1, d) => fs.forEach((f) => [-9, 9].forEach((dt) => osc('sawtooth', f, t, dur, v * 0.5, { a: 0.35, detune: dt, lp: 1100, dest: d })))
const bell = (t, f, v = 1, d) => { osc('sine', f, t, 0.9, v, { dest: d }); osc('sine', f * 2.76, t, 0.35, v * 0.35, { dest: d }) }
const crash = (t, v = 1, d) => nz(t, 1.3, v * 0.22, { type: 'highpass', f: 5000, dest: d })
function logDrum(t, f, v = 1, d, dur = 0.45) {
  if (!shaperCurve) {
    shaperCurve = new Float32Array(256)
    for (let i = 0; i < 256; i++) shaperCurve[i] = Math.tanh((i / 128 - 1) * 3)
  }
  const x = ac.createOscillator()
  const ws = ac.createWaveShaper()
  const fl = ac.createBiquadFilter()
  const g = ac.createGain()
  x.frequency.setValueAtTime(f * 2, t)
  x.frequency.exponentialRampToValueAtTime(f, t + 0.05)
  ws.curve = shaperCurve
  fl.type = 'lowpass'
  fl.frequency.value = 1100
  env(g, t, 0.004, v * 0.45, dur)
  x.connect(ws).connect(fl).connect(g).connect(d || sfxBus)
  x.start(t)
  x.stop(t + dur + 0.05)
}
// Corde pincée (guitare) : algorithme de Karplus-Strong, calculé une fois par note
const plucks = new Map()
function pluck(t, f, v = 1, d) {
  const key = Math.round(f)
  if (!plucks.has(key)) {
    const sr = ac.sampleRate
    const len = Math.floor(sr * 0.8)
    const n = Math.max(2, Math.round(sr / f))
    const b = ac.createBuffer(1, len, sr)
    const x = b.getChannelData(0)
    let p = 0
    for (let i = 0; i < n; i++) { p = 0.6 * (Math.random() * 2 - 1) + 0.4 * p; x[i] = p }
    for (let i = n; i < len; i++) x[i] = 0.996 * 0.5 * (x[i - n] + x[i - n + 1 < i ? i - n + 1 : i - n])
    plucks.set(key, b)
  }
  const s = ac.createBufferSource()
  const g = ac.createGain()
  const hp = ac.createBiquadFilter()
  s.buffer = plucks.get(key)
  hp.type = 'highpass'
  hp.frequency.value = 180
  g.gain.value = v * 0.5
  s.connect(hp).connect(g).connect(d || sfxBus)
  s.start(t)
}
function whistle(t, dur, v = 1) {
  const x = ac.createOscillator()
  const lfo = ac.createOscillator()
  const lg = ac.createGain()
  const g = ac.createGain()
  x.frequency.value = 2650
  lfo.frequency.value = 26
  lg.gain.value = 170
  lfo.connect(lg).connect(x.frequency)
  g.gain.setValueAtTime(0.0001, t)
  g.gain.linearRampToValueAtTime(v * 0.16, t + 0.02)
  g.gain.setValueAtTime(v * 0.16, t + dur - 0.03)
  g.gain.linearRampToValueAtTime(0.0001, t + dur)
  x.connect(g).connect(sfxBus)
  x.start(t); lfo.start(t); x.stop(t + dur + 0.02); lfo.stop(t + dur + 0.02)
}
function riser(t, dur, v = 1) {
  const s = ac.createBufferSource()
  const fl = ac.createBiquadFilter()
  const g = ac.createGain()
  s.buffer = noiseBuf()
  fl.type = 'bandpass'
  fl.Q.value = 2
  fl.frequency.setValueAtTime(400, t)
  fl.frequency.exponentialRampToValueAtTime(7000, t + dur)
  g.gain.setValueAtTime(0.0001, t)
  g.gain.exponentialRampToValueAtTime(v * 0.35, t + dur)
  g.gain.linearRampToValueAtTime(0.0001, t + dur + 0.03)
  s.connect(fl).connect(g).connect(sfxBus)
  s.start(t, 0, dur + 0.05)
}
function siren(t, dur, v = 1) {
  const x = ac.createOscillator()
  const lfo = ac.createOscillator()
  const lg = ac.createGain()
  const g = ac.createGain()
  const fl = ac.createBiquadFilter()
  x.type = 'sawtooth'
  x.frequency.value = 820
  lfo.frequency.value = 3
  lg.gain.value = 260
  lfo.connect(lg).connect(x.frequency)
  fl.type = 'lowpass'
  fl.frequency.value = 2400
  env(g, t, 0.05, v * 0.12, dur)
  x.connect(fl).connect(g).connect(sfxBus)
  x.start(t); lfo.start(t); x.stop(t + dur + 0.1); lfo.stop(t + dur + 0.1)
}
function applause(t, dur, v = 1) {
  for (let k = 0; k < dur * 110; k++) {
    const tt = t + Math.random() * dur
    nz(tt, 0.01 + Math.random() * 0.02, v * (1 - ((tt - t) / dur) * 0.6) * (0.3 + Math.random() * 0.7), { type: 'bandpass', f: 1100 + Math.random() * 2600, q: 1.4, a: 0.001 })
  }
}
const roll = (t, n, span, fn) => { for (let k = 0; k < n; k++) fn(t + (k * span) / n, 0.25 + (0.75 * k) / n) }

// ── Sons des moments : communs, puis propres à chaque ambiance ──
const BASE = {
  ding: () => { const t = now(); osc('sine', 1318, t, 0.5, 0.25); osc('sine', 1976, t + 0.07, 0.6, 0.18); osc('triangle', 2637, t + 0.14, 0.5, 0.08) },
  whoosh: () => nz(now(), 0.6, 0.25, { type: 'bandpass', f: 300, f2: 5000 }),
  impact: () => { const t = now(); osc('sine', 140, t, 0.45, 0.55, { f2: 40 }); nz(t, 0.25, 0.35, { type: 'lowpass', f: 2000, f2: 100 }) },
  stamp: () => { const t = now(); osc('square', 90, t, 0.35, 0.25, { f2: 50 }); nz(t, 0.12, 0.5, { type: 'lowpass', f: 1200, f2: 200 }) },
  tick: () => osc('square', 2200, now(), 0.05, 0.12),
  buzzer: () => { const t = now(); osc('sawtooth', 220, t, 0.6, 0.18, { f2: 180 }); osc('sawtooth', 233, t, 0.6, 0.12, { f2: 190 }) },
  beat: () => { const t = now(); osc('sine', 70, t, 0.18, 0.5, { f2: 45 }); osc('sine', 70, t + 0.22, 0.16, 0.4, { f2: 45 }) },
  fanfare: () => {
    const t = now()
    ;[523, 659, 784, 1046].forEach((f, i) => { osc('triangle', f, t + i * 0.12, 0.35, 0.22); osc('sine', f * 2, t + i * 0.12, 0.25, 0.06) })
    osc('triangle', 1046, t + 0.5, 0.9, 0.25); osc('triangle', 784, t + 0.5, 0.9, 0.15)
  },
  count: () => osc('square', 880, now(), 0.18, 0.14),
  go: () => osc('square', 1320, now(), 0.5, 0.14),
  wheel: () => osc('square', 1700, now(), 0.03, 0.08),
  pop: () => osc('sine', 600, now(), 0.12, 0.25, { f2: 1200 }),
}
BASE.order = BASE.ding
BASE.show = () => { BASE.whoosh(); setTimeout(() => ok() && BASE.impact(), 380) }
BASE.flash = BASE.fanfare
BASE.goal = BASE.fanfare

const PACKS = {
  techno: {
    order: () => { const t = now(); osc('sawtooth', 300, t, 0.16, 0.1, { f2: 2400, glide: 0.14, lp: 5000 }); clap(t + 0.02, 0.7); [69, 72, 76].forEach((n) => osc('sawtooth', mf(n + 12), t + 0.02, 0.22, 0.05, { lp: 4000, lp2: 900 })) },
    show: () => { const t = now(); riser(t, 0.42, 1); kick(t + 0.42, 1.3); crash(t + 0.42, 0.8); osc('sine', 55, t + 0.42, 0.9, 0.45) },
    flash: () => { const t = now(); siren(t, 1.6, 1); for (let k = 0; k < 12; k++) osc('square', mf([69, 72, 76, 81][k % 4] + 12), t + k * 0.08, 0.07, 0.07, { lp: 3000 }); kick(t + 1, 1.2); crash(t + 1, 0.7) },
    goal: () => { const t = now(); [57, 64, 69, 72, 76].forEach((n) => osc('sawtooth', mf(n + 12), t, 1.1, 0.05, { lp: 5000, lp2: 1200 })); kick(t, 1.3); crash(t, 0.9); applause(t + 0.1, 2.6, 1) },
    count: () => { const t = now(); osc('sawtooth', 440, t, 0.14, 0.12, { lp: 4000 }); kick(t, 0.8) },
    go: () => { const t = now(); osc('sawtooth', 880, t, 0.4, 0.12, { lp: 4000 }); kick(t, 1.2) },
  },
  coupe: {
    order: () => { const t = now(); whistle(t, 0.11, 1); whistle(t + 0.17, 0.11, 1); cowbell(t, 1.2); [76, 80, 83, 88].forEach((n, k) => pluck(t + 0.05 + k * 0.07, mf(n), 0.7)) },
    show: () => { const t = now(); roll(t, 10, 0.42, (tt, v) => snare(tt, v * 0.7)); kick(t + 0.44, 1.2); crash(t + 0.44, 0.8) },
    flash: () => { const t = now(); whistle(t, 0.7, 1); roll(t + 0.1, 14, 0.8, (tt, v) => snare(tt, v * 0.7)); setTimeout(() => ok() && BASE.fanfare(), 900) },
    goal: () => {
      const t = now()
      ;[64, 68, 71, 76, 80, 83, 88].forEach((n, k) => pluck(t + k * 0.06, mf(n), 0.7))
      whistle(t + 0.5, 0.12, 1); whistle(t + 0.7, 0.12, 1); whistle(t + 0.9, 0.3, 1); applause(t + 0.2, 2.6, 1)
    },
    count: () => cowbell(now(), 1),
    go: () => { const t = now(); cowbell(t, 1.6); whistle(t, 0.3, 1) },
  },
  zouglou: {
    order: () => { const t = now(); [220, 196, 165, 147, 110].forEach((f, k) => conga(t + k * 0.06, f, 0.9)); [0.36, 0.5, 0.64].forEach((dt) => clap(t + dt, 0.8)) },
    show: () => { const t = now(); roll(t, 9, 0.45, (tt, v) => conga(tt, 196 - v * 60, v)); conga(t + 0.47, 90, 1.2); kick(t + 0.47, 0.9) },
    flash: () => { const t = now(); roll(t, 16, 1, (tt, v) => conga(tt, [220, 165][Math.round(v * 9) % 2], v)); [1.05, 1.2, 1.35, 1.5].forEach((dt) => clap(t + dt, 0.9)) },
    goal: () => { const t = now(); [0, 0.15, 0.3, 0.6, 0.75, 0.9].forEach((dt) => clap(t + dt, 0.9)); applause(t + 0.2, 3, 1.1); conga(t, 98, 1) },
    count: () => conga(now(), 196, 1),
    go: () => { const t = now(); conga(t, 98, 1); clap(t, 0.9) },
  },
  afro: {
    order: () => { const t = now(); logDrum(t, mf(38), 1.1); logDrum(t + 0.17, mf(45), 0.9); bell(t + 0.05, mf(88), 0.15); shaker(t, 1) },
    show: () => { const t = now(); roll(t, 8, 0.4, (tt, v) => shaker(tt, v * 1.4)); logDrum(t + 0.42, mf(50), 1.2, null, 0.7) },
    flash: () => { const t = now(); [38, 38, 45, 50, 45, 38].forEach((n, k) => logDrum(t + k * 0.13, mf(n), 1)); keys(t + 0.8, [62, 65, 69, 72].map(mf), 0.9, 0.07) },
    goal: () => { const t = now(); keys(t, [62, 65, 69, 72, 76].map(mf), 1.4, 0.07); logDrum(t, mf(38), 1.2, null, 0.8); applause(t + 0.1, 2.6, 1) },
    count: () => logDrum(now(), mf(45), 1),
    go: () => logDrum(now(), mf(38), 1.2),
  },
  douce: {
    order: () => { const t = now(); bell(t, mf(84), 0.14); bell(t + 0.1, mf(88), 0.1) },
    show: () => { const t = now(); nz(t, 0.7, 0.12, { type: 'bandpass', f: 300, f2: 3000 }); bell(t + 0.4, mf(76), 0.14) },
    goal: () => { const t = now(); keys(t, [69, 72, 76, 81].map(mf), 1.5, 0.07); applause(t + 0.1, 2.2, 0.7) },
  },
}

export function play(name) {
  if (!ok()) return
  try {
    // Avec une chanson : les sons « fête » (sifflet, cloche, roulements)
    const fn = PACKS[TRACKS[ambiance] ? 'coupe' : ambiance]?.[name] ?? BASE[name]
    fn?.()
  } catch {
    // un son raté ne gêne pas le live
  }
}

// ── Musique : un séquenceur de 16 pas par mesure, programmé un peu en avance ──
let musicOn = false
let musicTimer = null
let step = 0
let nextAt = 0
let boostUntil = 0
let beatListener = null

/** La musique monte d'un cran (plus d'instruments) pendant quelques secondes : vente flash, commandes en rafale */
export function boost(seconds) {
  if (ac) boostUntil = Math.max(boostUntil, ac.currentTime + seconds)
}
/** Appelé à chaque temps fort de la musique (les lumières de l'écran battent avec elle) */
export function onBeat(fn) {
  beatListener = fn
}
function pulse(t) {
  if (!beatListener) return
  setTimeout(() => beatListener?.(), Math.max(0, (t - ac.currentTime) * 1000))
}

const STYLES = {
  techno: { bpm: 126, play(i, bar, t, up, d) {
    const ch = [[57, 60, 64], [53, 57, 60], [60, 64, 67], [55, 59, 62]][bar % 4]
    if (i % 4 === 0) { kick(t, 1, d); pulse(t) }
    if (i % 4 === 2) hat(t, 0.9, true, d); else hat(t, 0.35, false, d)
    if (i === 4 || i === 12) clap(t, 0.7, d)
    if (i % 4 !== 0) bass(t, mf(ch[0] - 24), 0.11, 0.9, up ? 1300 : 520, d)
    const arp = [0, 1, 2, 1, 2, 0, 2, 1]
    if (up || bar % 2 === 1) lead(t, mf(ch[arp[i % 8]] + 12), 0.09, 0.16, 1100 + 2200 * (0.5 + 0.5 * Math.sin(step / 22)), d)
    if (bar % 4 === 3 && i >= 12) snare(t, 0.2 + 0.12 * (i - 12), d)
    if (up && i === 0 && bar % 2 === 0) crash(t, 0.6, d)
  } },
  coupe: { bpm: 128, play(i, bar, t, up, d) {
    const ch = [[64, 68, 71], [59, 63, 66], [61, 64, 68], [57, 61, 64]][bar % 4]
    if (i % 4 === 0) { kick(t, 0.9, d); pulse(t) }
    if (i === 4 || i === 12) snare(t, 0.7, d)
    if ([7, 10, 15].includes(i)) snare(t, 0.18, d)
    if ([0, 3, 6, 8, 11, 14].includes(i)) cowbell(t, 0.7, d)
    hat(t, i % 2 ? 0.22 : 0.38, false, d)
    if (i % 2) shaker(t, 0.5, d)
    if ([0, 3, 6, 8, 10, 13].includes(i)) bass(t, mf(ch[0] - 24), 0.14, 1, 520, d)
    const notes = [ch[0], ch[1], ch[2], ch[0] + 12]
    pluck(t, mf(notes[[0, 1, 2, 3, 2, 1][i % 6]] + 12), up ? 0.55 : 0.35, d)
    if (bar % 4 === 3 && i >= 12) snare(t, 0.35, d)
    if (up && i === 0 && bar % 2 === 0) crash(t, 0.5, d)
  } },
  zouglou: { bpm: 112, swing: 0.14, play(i, bar, t, up, d) {
    const ch = [[60, 64, 67], [65, 69, 72], [67, 71, 74], [60, 64, 67]][bar % 4]
    if (i === 0 || i === 8) { kick(t, 0.7, d); pulse(t) }
    if ([0, 6, 10].includes(i)) conga(t, 98, 0.85, d)
    if ([3, 4, 12, 14].includes(i)) conga(t, 196, 0.6, d)
    if (i === 11) conga(t, 165, 0.5, d)
    if (i === 4 || i === 12) { clap(t, 0.65, d); pulse(t) }
    if (up && (i === 7 || i === 15)) clap(t, 0.4, d)
    if (i % 2 === 0) shaker(t, 0.6, d)
    if (i === 0) bass(t, mf(ch[0] - 24), 0.35, 1, 480, d)
    if (i === 6) bass(t, mf(ch[0] - 24), 0.2, 0.8, 480, d)
    if (i === 8) bass(t, mf(ch[2] - 24), 0.3, 0.9, 480, d)
    const at = { 2: 0, 5: 1, 8: 2, 10: 1, 13: 2 }[i]
    if (at !== undefined) pluck(t, mf(ch[at] + 12), up ? 0.6 : 0.45, d)
    if (i === 10) rim(t, 0.6, d)
  } },
  afro: { bpm: 113, play(i, bar, t, up, d) {
    const ch = [[62, 65, 69, 72], [57, 60, 64, 67], [58, 62, 65, 69], [60, 64, 67, 70]][bar % 4]
    if (i === 0 || i === 8) { kick(t, 0.6, d); pulse(t) }
    const ld = { 0: 0, 3: 0, 6: 7, 10: 12, 11: 7, 14: 0 }[i]
    if (ld !== undefined) { logDrum(t, mf(ch[0] - 24 + ld), 0.9, d); if (i === 6 || i === 10) pulse(t) }
    shaker(t, i % 2 ? 0.6 : 0.3, d)
    if (i === 4 || i === 12) rim(t, 0.6, d)
    if (i === 12 && up) clap(t, 0.4, d)
    if ([2, 7, 10].includes(i) && (up || bar % 2 === 0)) keys(t, ch.map(mf), 0.25, 0.05, d)
    if (i === 0) pad(t, ch.map(mf), 2.1, 0.025, d)
  } },
  douce: { bpm: 92, play(i, bar, t, up, d) {
    const ch = [[57, 60, 64], [53, 57, 60], [48, 52, 55], [55, 59, 62]][bar % 4]
    if (i === 0 || i === 10) { kick(t, 0.45, d); pulse(t) }
    if (i === 4 || i === 12) rim(t, 0.4, d)
    if (i % 2 === 0) hat(t, 0.12, false, d)
    if (i === 0 || i === 8) keys(t, ch.map((n) => mf(n + 12)), 0.9, 0.045, d)
    if (i === 0) bass(t, mf(ch[0] - 12), 0.6, 0.9, 300, d)
    if (i === 7) bass(t, mf(ch[2] - 12), 0.3, 0.8, 300, d)
    const at = [3, 6, 11, 14].indexOf(i)
    if (up && at >= 0) bell(t, mf(ch[[0, 1, 2, 1][at]] + 24), 0.07, d)
  } },
}

function schedule() {
  if (!ac || ac.state !== 'running') return
  const style = STYLES[ambiance]
  if (!style) return
  const sixteenth = 60 / style.bpm / 4
  // Onglet resté en pause : on repart du présent au lieu de rattraper les notes perdues
  if (nextAt < ac.currentTime - 0.2) nextAt = ac.currentTime + 0.05
  while (nextAt < ac.currentTime + 0.15) {
    const i = step % 16
    const t = nextAt + (style.swing && i % 2 ? sixteenth * style.swing : 0)
    if (!muted) {
      try {
        style.play(i, Math.floor(step / 16), t, ac.currentTime < boostUntil, musicBus)
      } catch {
        // une note ratée ne gêne pas le live
      }
    }
    nextAt += sixteenth
    step += 1
  }
}
// ── Chansons : lues par un lecteur audio branché sur la même sortie ; les temps forts sont repérés dans les basses ──
let player = null
let analyser = null
let trackAt = 0
let trackTimer = null
function stopTrack() {
  clearInterval(trackTimer)
  trackTimer = null
  player?.pause()
}
function playTrack(key) {
  if (!player) {
    player = new Audio()
    player.preload = 'auto'
    const source = ac.createMediaElementSource(player)
    const gain = ac.createGain()
    gain.gain.value = 0.75
    analyser = ac.createAnalyser()
    analyser.fftSize = 512
    source.connect(analyser)
    analyser.connect(gain)
    gain.connect(musicBus)
    // Fin d'une chanson : la suivante
    player.addEventListener('ended', () => {
      if (!musicOn || !TRACKS[ambiance]) return
      trackAt = (trackAt + 1) % TRACK_ORDER.length
      player.src = TRACKS[TRACK_ORDER[trackAt]]
      player.play().catch(() => {})
    })
  }
  trackAt = TRACK_ORDER.indexOf(key)
  player.src = TRACKS[key]
  player.play().catch(() => {})
  const bins = new Uint8Array(analyser.frequencyBinCount)
  // Temps fort = montée brusque de l'énergie des basses (par rapport à ses montées habituelles)
  let previous = 0
  let rise = 4
  let last = 0
  trackTimer = setInterval(() => {
    if (player.paused) return
    analyser.getByteFrequencyData(bins)
    const energy = (bins[1] + bins[2] + bins[3] + bins[4] + bins[5]) / 5
    const jump = Math.max(0, energy - previous)
    const t = performance.now()
    if (jump > rise * 1.8 && jump > 6 && t - last > 280) {
      last = t
      beatListener?.()
    }
    rise = rise * 0.9 + jump * 0.1
    previous = energy
  }, 30)
}

function restartMusic() {
  clearInterval(musicTimer)
  musicTimer = null
  stopTrack()
  if (!musicOn || !ac) return
  if (TRACKS[ambiance]) {
    playTrack(ambiance)
    return
  }
  step = 0
  nextAt = ac.currentTime + 0.08
  musicTimer = setInterval(schedule, 25)
}

export function setMusic(on) {
  const playing = !!musicTimer || (!!player && !player.paused)
  if (musicOn === !!on && (playing || !on)) return // déjà dans cet état : on ne recommence pas la chanson
  musicOn = !!on
  restartMusic()
}
