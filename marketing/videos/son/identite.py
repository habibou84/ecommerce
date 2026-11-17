"""
Identité sonore de Jibiat Marchand, couleur « liquide » (gouttes, bulles, sons ronds sans aigus) : musique de fond,
logo sonore et effets, tout synthétisé ici (aucune banque
de sons extérieure, donc aucun droit à payer). Et mixage d'une vidéo : voix off + musique baissée sous la voix
+ effets placés aux instants donnés.

    python3 identite.py generer DOSSIER                 # musique-boucle.wav, logo-sonore.wav, effets/*.wav
    python3 identite.py mixer VOIX.mp3 EVENEMENTS.json SORTIE.wav DUREE [TEMPS_LOGO]

EVENEMENTS.json : [[secondes, "nom_de_l_effet", gain], ...] (gain facultatif, 1 par défaut).
"""
import json
import subprocess
import sys
import wave
from pathlib import Path

import numpy as np

SR = 44100
BPM = 104
BEAT = 60 / BPM
STEP = BEAT / 4  # double croche
BAR = BEAT * 4
rng = np.random.default_rng(7)  # même musique à chaque génération


def hz(midi):
    return 440.0 * 2 ** ((midi - 69) / 12)


def t_axis(sec):
    return np.arange(int(sec * SR)) / SR


def env(sec, attack=0.003, tau=0.3):
    t = t_axis(sec)
    a = np.clip(t / attack, 0, 1)
    return a * np.exp(-t / tau)


def lowpass(x, cutoff):
    """Filtre passe-bas d'ordre 1 (assez pour adoucir)."""
    a = np.exp(-2 * np.pi * cutoff / SR)
    y = np.empty_like(x)
    acc = 0.0
    for i, v in enumerate(x):
        acc = (1 - a) * v + a * acc
        y[i] = acc
    return y


def lowpass_fast(x, cutoff):
    # même filtre, vectorisé par blocs grâce à une convolution courte (approximation suffisante)
    a = np.exp(-2 * np.pi * cutoff / SR)
    n = int(np.ceil(np.log(1e-4) / np.log(a))) if a > 0 else 1
    k = (1 - a) * a ** np.arange(max(n, 1))
    return np.convolve(x, k)[: len(x)]


def highpass(x, cutoff):
    return x - lowpass_fast(x, cutoff)


# ——— Couleur « liquide » : gouttes, bulles, piano électrique qui ondule, basse qui glisse ———
def drop(f, sec=0.3, rise=1.8, glide=0.03, tau=0.08, vel=1.0):
    """Goutte d'eau : la hauteur remonte vite (bulle qui éclate), son rond et court."""
    t = t_axis(sec)
    fr = f * (1 + (rise - 1) * (1 - np.exp(-t / glide)))
    ph = 2 * np.pi * np.cumsum(fr) / SR
    x = np.sin(ph) + 0.12 * np.sin(2 * ph)
    return vel * lowpass_fast(x * env(sec, 0.001, tau), 2600)


def epiano(midi, sec=1.6, vel=1.0):
    """Piano électrique doux : modulation de fréquence légère, vibrato d'amplitude (ondule comme l'eau)."""
    f = hz(midi)
    t = t_axis(sec)
    idx = 0.9 * np.exp(-t / 0.25)
    x = np.sin(2 * np.pi * f * t + idx * np.sin(2 * np.pi * f * t))
    trem = 1 + 0.22 * np.sin(2 * np.pi * 4.2 * t)
    return vel * lowpass_fast(x * trem * env(sec, 0.004, 0.9), 2200)


def logdrum(midi, sec, vel=1.0):
    """Basse « log drum » (amapiano) : attaque qui glisse vers le bas, ronde et liquide."""
    f = hz(midi)
    t = t_axis(sec)
    fr = f * (1 + 0.9 * np.exp(-t / 0.035))
    x = np.sin(2 * np.pi * np.cumsum(fr) / SR)
    e = np.clip(t / 0.003, 0, 1) * np.exp(-t / 0.42)
    e[-int(0.02 * SR):] *= np.linspace(1, 0, int(0.02 * SR))
    return vel * lowpass_fast(np.tanh(1.8 * x * e) / 1.8, 700)


def kick(vel=1.0):
    sec = 0.35
    t = t_axis(sec)
    f = 42 + 70 * np.exp(-t / 0.03)
    return vel * lowpass_fast(np.sin(2 * np.pi * np.cumsum(f) / SR) * env(sec, 0.002, 0.13), 400)


def plop(vel=1.0):
    """Percussion d'eau grave, à la place du clap."""
    return drop(hz(55), 0.25, rise=1.5, glide=0.02, tau=0.06, vel=vel)


def droplet(vel=1.0):
    """Petite goutte, à la place du shaker (pas de souffle aigu)."""
    return drop(hz(rng.choice([67, 69, 71, 74])), 0.12, rise=1.6, glide=0.012, tau=0.03, vel=vel)


def pad(midis, sec, vel=1.0):
    t = t_axis(sec)
    x = np.zeros_like(t)
    for m in midis:
        for det in (-0.1, 0.0, 0.1):
            x += np.sin(2 * np.pi * hz(m) * 2 ** (det / 12) * t)
    wob = 1 + 0.15 * np.sin(2 * np.pi * 0.6 * t)
    e = np.clip(t / 0.5, 0, 1) * np.clip((sec - t) / 0.5, 0, 1)
    return vel * lowpass_fast(x * e * wob / (len(midis) * 3), 1200)


def place(buf, sig, at):
    i = int(round(at * SR))
    if i >= len(buf):
        return
    j = min(len(buf), i + len(sig))
    buf[i:j] += sig[: j - i]


# ——— Musique : 8 mesures en boucle, ré majeur, accords enrichis dans le grave-médium ———
CHORDS = [
    ('Dmaj9', [50, 57, 61, 64], 38), ('A', [52, 57, 61, 64], 33), ('Bm7', [50, 54, 57, 59], 35), ('Gmaj7', [50, 54, 55, 59], 31),
] * 2
# Motif de la marque en gouttes (mesures 5 à 8), en doubles croches : (position, note)
HOOK = {
    4: [(0, 69), (3, 66), (4, 69), (6, 71), (10, 69)],
    5: [(0, 69), (4, 64), (8, 61), (11, 64)],
    6: [(0, 71), (4, 74), (8, 71), (10, 69), (12, 66)],
    7: [(0, 67), (4, 69), (8, 66), (12, 62)],
}


def music_loop():
    n_bars = len(CHORDS)
    buf = np.zeros(int(n_bars * BAR * SR) + 2 * SR)
    for b, (_, tones, root) in enumerate(CHORDS):
        t0 = b * BAR
        place(buf, 0.12 * pad([m - 12 for m in tones[:3]], BAR + 0.4), t0)
        # Accords de piano électrique, rythme syncopé et souple
        for pos, vel in ((0, 0.30), (3, 0.18), (6, 0.24), (10, 0.22), (13, 0.16)):
            for m in tones:
                place(buf, vel * 0.5 * epiano(m, 1.2), t0 + pos * STEP)
        # Basse log drum
        for pos, dur, up in ((0, 3, 0), (3, 3, 0), (7, 3, 12), (10, 4, 0), (14, 2, 7)):
            place(buf, 0.55 * logdrum(root + 12 + up, dur * STEP * 0.95), t0 + pos * STEP)
        # Batterie d'eau : grosse caisse douce, plops et gouttelettes
        for pos in (0, 8):
            place(buf, 0.7 * kick(), t0 + pos * STEP)
        for pos in (4, 12):
            place(buf, 0.35 * plop(), t0 + pos * STEP)
        for pos in (2, 6, 7, 10, 14, 15):
            place(buf, 0.10 * droplet(), t0 + pos * STEP)
        # Motif de la marque en gouttes
        for pos, m in HOOK.get(b, []):
            place(buf, 0.32 * drop(hz(m), 0.5, rise=1.12, glide=0.02, tau=0.22), t0 + pos * STEP)
    loop_len = int(n_bars * BAR * SR)
    tail = buf[loop_len:]
    out = buf[:loop_len].copy()
    out[: len(tail)] += tail[: loop_len]  # boucle sans coupure
    return lowpass_fast(out, 4500)


def music(duration, fade_in=0.8, fade_out=1.6):
    loop = music_loop()
    reps = int(np.ceil(duration * SR / len(loop)))
    x = np.tile(loop, reps)[: int(duration * SR)]
    t = t_axis(duration)[: len(x)]
    x *= np.clip(t / fade_in, 0, 1) * np.clip((duration - t) / fade_out, 0, 1)
    return x


# ——— Logo sonore : quatre gouttes montantes ré – fa# – la – ré, sur un accord chaud ———
def sonic_logo():
    buf = np.zeros(int(3.0 * SR))
    for k, m in enumerate((62, 66, 69, 74)):
        place(buf, 0.55 * drop(hz(m), 0.7, rise=1.15, glide=0.02, tau=0.3), k * 0.12)
    for m in (50, 57, 61, 66):
        place(buf, 0.22 * epiano(m, 2.4), 0.48)
    place(buf, 0.6 * logdrum(38, 1.2), 0.48)
    place(buf, 0.18 * pad([50, 54, 57], 2.4), 0.48)
    return lowpass_fast(buf, 3500)


# ——— Effets « liquides » (pas d'aigus : tout reste sous 1 kHz environ) ———
def sfx_all():
    fx = {}
    fx['pop'] = 0.9 * drop(260, 0.18, rise=2.4, glide=0.025, tau=0.045)  # bloup d'envoi de message
    fx['tap'] = 0.6 * drop(330, 0.08, rise=1.6, glide=0.01, tau=0.015)  # toucher
    fx['key'] = 0.35 * drop(420, 0.06, rise=1.3, glide=0.008, tau=0.012)  # frappe douce
    sec = 0.6  # clapotis de transition
    n = lowpass_fast(rng.standard_normal(int(sec * SR)), 700)
    tt = t_axis(sec)
    sw = 0.6 * n * np.sin(np.pi * tt / sec) ** 2
    for k, f in enumerate((180, 240, 320)):
        place(sw, 0.25 * drop(f, 0.2, rise=1.8, glide=0.02, tau=0.05), 0.12 + k * 0.1)
    fx['whoosh'] = 0.6 * sw
    fx['whoosh_soft'] = 0.3 * sw
    ding = np.zeros(int(0.6 * SR))  # notification : double bloup, comme un message reçu
    place(ding, drop(330, 0.25, rise=1.9, glide=0.02, tau=0.06), 0)
    place(ding, drop(440, 0.3, rise=1.9, glide=0.02, tau=0.08), 0.11)
    fx['ding'] = 0.8 * ding
    cash = np.zeros(int(0.9 * SR))  # argent : gouttes en cascade + accord rond
    for k, f in enumerate((392, 494, 587, 494, 659)):
        place(cash, 0.4 * drop(f, 0.25, rise=1.25, glide=0.01, tau=0.07), k * 0.05)
    for m in (62, 66, 69):
        place(cash, 0.12 * epiano(m, 0.8), 0.18)
    fx['cash'] = cash
    fx['coin'] = 0.35 * drop(523, 0.15, rise=1.2, glide=0.01, tau=0.04)
    ok = np.zeros(int(1.2 * SR))  # confirmé : trois gouttes qui montent
    for k, m in enumerate((62, 66, 69)):
        place(ok, 0.5 * drop(hz(m), 0.5, rise=1.15, glide=0.015, tau=0.18), k * 0.09)
    for m in (50, 57, 62, 66):
        place(ok, 0.12 * epiano(m, 1.0), 0.27)
    fx['success'] = ok
    vib = np.zeros(int(0.7 * SR))  # vibration du téléphone, grave et douce
    tv = t_axis(0.22)
    buzz = lowpass_fast(np.sign(np.sin(2 * np.pi * 120 * tv)), 400) * np.clip(tv / 0.01, 0, 1) * np.clip((0.22 - tv) / 0.02, 0, 1)
    place(vib, 0.3 * buzz, 0)
    place(vib, 0.3 * buzz, 0.3)
    fx['vibrate'] = vib
    sec = 0.5  # tampon : choc sourd + éclaboussure grave
    tt = t_axis(sec)
    thump = np.sin(2 * np.pi * np.cumsum(130 * np.exp(-tt / 0.1) + 45) / SR) * env(sec, 0.001, 0.1)
    splash = lowpass_fast(rng.standard_normal(len(tt)), 600) * env(sec, 0.002, 0.08)
    fx['stamp'] = 0.9 * thump + 0.35 * splash
    fx['error'] = 0.7 * drop(300, 0.45, rise=0.55, glide=0.12, tau=0.15)  # goutte qui descend
    sh = np.zeros(int(0.25 * SR))  # photo : deux petits clics ronds
    place(sh, drop(380, 0.06, rise=1.3, glide=0.008, tau=0.012), 0)
    place(sh, drop(300, 0.06, rise=1.3, glide=0.008, tau=0.015), 0.07)
    fx['shutter'] = 0.7 * sh
    bub = np.zeros(int(1.1 * SR))  # mise en valeur : bulles qui remontent
    for k in range(9):
        place(bub, (0.35 - k * 0.025) * drop(float(rng.uniform(260, 620)), 0.2, rise=1.9, glide=0.02, tau=0.05), k * 0.07 + float(rng.uniform(0, 0.03)))
    fx['shimmer'] = bub
    fx['tick'] = 0.4 * drop(440, 0.1, rise=1.3, glide=0.01, tau=0.025)
    rg = np.zeros(int(0.55 * SR))  # sonnerie grave
    tr = t_axis(0.45)
    tone = (np.sin(2 * np.pi * 330 * tr) + np.sin(2 * np.pi * 392 * tr)) * (0.5 + 0.5 * np.sign(np.sin(2 * np.pi * 12 * tr)))
    place(rg, 0.15 * lowpass_fast(tone, 900) * env(0.45, 0.01, 0.3), 0)
    fx['ring'] = rg
    return fx


def write_wav(path, x, stereo=True):
    x = np.clip(x, -1, 1)
    data = (x * 32767).astype('<i2')
    if stereo:
        data = np.repeat(data[:, None], 2, axis=1)
    with wave.open(str(path), 'wb') as w:
        w.setnchannels(2 if stereo else 1)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(data.tobytes())


def read_audio(path):
    raw = subprocess.run(['ffmpeg', '-loglevel', 'error', '-i', str(path), '-f', 's16le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True, check=True).stdout
    return np.frombuffer(raw, '<i2').astype(np.float64) / 32768


def generate(folder):
    folder = Path(folder)
    (folder / 'effets').mkdir(parents=True, exist_ok=True)
    loop = music_loop()
    write_wav(folder / 'musique-boucle.wav', 0.8 * loop / np.max(np.abs(loop)))
    logo = sonic_logo()
    write_wav(folder / 'logo-sonore.wav', 0.8 * logo / np.max(np.abs(logo)))
    for name, sig in sfx_all().items():
        write_wav(folder / 'effets' / f'{name}.wav', sig)


def mix(voice_path, events_path, out_path, duration, logo_at=None):
    duration = float(duration)
    n = int(duration * SR)
    voice = np.zeros(n)
    v = read_audio(voice_path)
    voice[: min(n, len(v))] = v[:n]
    # Musique : fond constant, baissée quand la voix parle (enveloppe lissée de la voix)
    m = music(duration)
    m = m / np.max(np.abs(m))
    # Enveloppe de la voix par blocs de 10 ms, montée rapide et retour lent, puis ramenée à l'échelle des échantillons
    blk = SR // 100
    nb = int(np.ceil(n / blk))
    rms = np.sqrt(np.array([np.mean(voice[i * blk:(i + 1) * blk] ** 2) for i in range(nb)]))
    rms = rms / (np.max(rms) + 1e-9)
    target = np.clip(rms / 0.12, 0, 1)
    sm = np.zeros(nb)
    acc = 0.0
    for i, v in enumerate(target):
        acc += (v - acc) * (0.5 if v > acc else 0.04)  # ~20 ms pour baisser, ~250 ms pour remonter
        sm[i] = acc
    duck = np.interp(np.arange(n), np.arange(nb) * blk, sm)
    music_gain = 0.20 * (1 - 0.6 * duck)
    if logo_at is not None:  # la musique s'efface sous le logo sonore
        t = t_axis(duration)[:n]
        music_gain *= np.clip(1 - (t - float(logo_at)) / 0.6, 0.25, 1)
    out = voice * 0.92 + m * music_gain
    fx = sfx_all()
    for ev in json.loads(Path(events_path).read_text()):
        at, name = ev[0], ev[1]
        gain = ev[2] if len(ev) > 2 else 1.0
        place(out, 0.3 * gain * fx[name], at)
    if logo_at is not None:
        logo = sonic_logo()
        place(out, 0.5 * logo / np.max(np.abs(logo)), float(logo_at))
    peak = np.max(np.abs(out))
    if peak > 0.97:
        out = np.tanh(out / peak * 1.2) / np.tanh(1.2) * 0.97
    write_wav(out_path, out)


if __name__ == '__main__':
    if sys.argv[1] == 'generer':
        generate(sys.argv[2])
    elif sys.argv[1] == 'mixer':
        mix(*sys.argv[2:])
