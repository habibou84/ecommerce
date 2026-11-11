// Sons du show du live, fabriqués par le navigateur (Web Audio) : aucun fichier à télécharger. Le navigateur ne
// joue un son qu'après un geste (« Activer le son ») ; TikTok LIVE Studio et OBS l'autorisent d'office.
let ac = null
let master = null
let muted = false
let musicTimer = null

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
      master = ac.createGain()
      master.gain.value = 0.7
      master.connect(ac.destination)
    }
    ac.resume?.()
    return true
  } catch {
    return false
  }
}

export function setMuted(value) {
  muted = !!value
  if (master) master.gain.value = muted ? 0 : 0.7
}

const ok = () => ac && !muted && ac.state === 'running'
const now = () => ac.currentTime

function tone(f, t0, dur, type = 'sine', vol = 0.3, f2 = null) {
  const o = ac.createOscillator()
  const g = ac.createGain()
  o.type = type
  o.frequency.setValueAtTime(f, t0)
  if (f2) o.frequency.exponentialRampToValueAtTime(f2, t0 + dur)
  g.gain.setValueAtTime(0, t0)
  g.gain.linearRampToValueAtTime(vol, t0 + 0.01)
  g.gain.exponentialRampToValueAtTime(0.0001, t0 + dur)
  o.connect(g)
  g.connect(master)
  o.start(t0)
  o.stop(t0 + dur + 0.05)
}

function noise(t0, dur, vol = 0.3, from = 4000, to = 200, type = 'lowpass') {
  const len = Math.floor(ac.sampleRate * dur)
  const buf = ac.createBuffer(1, len, ac.sampleRate)
  const d = buf.getChannelData(0)
  for (let i = 0; i < len; i++) d[i] = (Math.random() * 2 - 1) * (1 - i / len)
  const s = ac.createBufferSource()
  const f = ac.createBiquadFilter()
  const g = ac.createGain()
  s.buffer = buf
  f.type = type
  f.frequency.setValueAtTime(from, t0)
  f.frequency.exponentialRampToValueAtTime(to, t0 + dur)
  g.gain.value = vol
  s.connect(f)
  f.connect(g)
  g.connect(master)
  s.start(t0)
}

const SOUNDS = {
  // Pièce prise : caisse qui sonne
  ding: () => { const t = now(); tone(1318, t, 0.5, 'sine', 0.25); tone(1976, t + 0.07, 0.6, 'sine', 0.18); tone(2637, t + 0.14, 0.5, 'triangle', 0.08) },
  // Article qui arrive : souffle puis impact
  whoosh: () => noise(now(), 0.6, 0.25, 300, 5000, 'bandpass'),
  impact: () => { const t = now(); tone(140, t, 0.45, 'sine', 0.55, 40); noise(t, 0.25, 0.35, 2000, 100) },
  stamp: () => { const t = now(); tone(90, t, 0.35, 'square', 0.25, 50); noise(t, 0.12, 0.5, 1200, 200) },
  tick: () => tone(2200, now(), 0.05, 'square', 0.12),
  buzzer: () => { const t = now(); tone(220, t, 0.6, 'sawtooth', 0.18, 180); tone(233, t, 0.6, 'sawtooth', 0.12, 190) },
  beat: () => { const t = now(); tone(70, t, 0.18, 'sine', 0.5, 45); tone(70, t + 0.22, 0.16, 'sine', 0.4, 45) },
  fanfare: () => { const t = now(); [523, 659, 784, 1046].forEach((f, i) => { tone(f, t + i * 0.12, 0.35, 'triangle', 0.22); tone(f * 2, t + i * 0.12, 0.25, 'sine', 0.06) }); tone(1046, t + 0.5, 0.9, 'triangle', 0.25); tone(784, t + 0.5, 0.9, 'triangle', 0.15) },
  count: () => tone(880, now(), 0.18, 'square', 0.14),
  go: () => tone(1320, now(), 0.5, 'square', 0.14),
  wheel: () => tone(1700, now(), 0.03, 'square', 0.08),
  pop: () => tone(600, now(), 0.12, 'sine', 0.25, 1200),
}

export function play(name) {
  if (!ok() || !SOUNDS[name]) return
  try {
    SOUNDS[name]()
  } catch {
    // un son raté ne gêne pas le live
  }
}

// Musique de fond douce (boucle de 2 secondes, programmée par avance, très peu de calcul)
function bar() {
  if (!ok()) return
  const t = now() + 0.05
  const b = 0.5
  for (let i = 0; i < 8; i++) {
    tone(i % 2 ? 60 : 50, t + (i * b) / 2, 0.12, 'sine', i % 2 ? 0.1 : 0.25, 40)
    noise(t + (i * b) / 2 + b / 4, 0.05, 0.04, 8000, 6000, 'highpass')
  }
  ;[55, 55, 65.4, 49].forEach((f, i) => tone(f * 2, t + i * b, b * 0.9, 'triangle', 0.08))
  ;[[523, 659], [587, 698], [523, 784], [494, 587]].forEach((c, i) => c.forEach((f) => tone(f, t + i * b + 0.02, 0.25, 'sine', 0.03)))
}

export function setMusic(on) {
  clearInterval(musicTimer)
  musicTimer = null
  if (on) {
    bar()
    musicTimer = setInterval(bar, 2000)
  }
}
