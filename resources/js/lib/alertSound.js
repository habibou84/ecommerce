// Nouvelle mission : deux bips et une vibration, pour que le livreur l'entende même téléphone en poche.
let context = null

export function newMissionAlert() {
  try {
    navigator.vibrate?.([300, 150, 300])
    context ??= new (window.AudioContext || window.webkitAudioContext)()
    ;[0, 0.35].forEach((start) => {
      const osc = context.createOscillator()
      const gain = context.createGain()
      osc.frequency.value = 880
      gain.gain.setValueAtTime(0.25, context.currentTime + start)
      gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + start + 0.3)
      osc.connect(gain).connect(context.destination)
      osc.start(context.currentTime + start)
      osc.stop(context.currentTime + start + 0.3)
    })
  } catch {
    // son indisponible (navigateur, permission) : la mission s'affiche quand même
  }
}
