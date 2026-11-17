# Ajoute 1 s de couverture au début d'une vidéo et refait le mixage décalé d'autant.
# usage : ajouter.sh NUMERO NOM VOIX LOGO   (ex. ajouter.sh 1 1-recap-whatsapp pub8/voix-fin.mp3 49.68)
set -e
S=/tmp/claude-0/-home-user-ecommerce/6517de89-5ac3-5e3e-ad04-e69e7096dadb/scratchpad
cd $S
n=$1; nom=$2; voix=$3; logo=$4
[ -f sans-couverture/$nom.mp4 ] || { mkdir -p sans-couverture; cp livrables/$nom.mp4 sans-couverture/$nom.mp4; }
ffmpeg -loglevel error -y -loop 1 -framerate 30 -t 1 -i couvertures/couverture-$n.png -i sans-couverture/$nom.mp4 \
  -filter_complex "[0:v]format=yuv420p,setsar=1[c];[1:v]fps=30,format=yuv420p,setsar=1[v];[c][v]concat=n=2:v=1:a=0[o]" -map "[o]" \
  -c:v libx264 -pix_fmt yuv420p -crf 18 -preset medium -movflags +faststart couvertures/tmp-$n.mp4
d=$(ffprobe -v error -show_entries format=duration -of csv=p=0 couvertures/tmp-$n.mp4)
python3 son/identite.py mixer $voix son/evenements-$nom.json son/mix-$nom.wav $d $logo 1
ffmpeg -loglevel error -y -i couvertures/tmp-$n.mp4 -i son/mix-$nom.wav -map 0:v -map 1:a -c:v copy -af loudnorm=I=-14:TP=-1.5:LRA=11 -c:a aac -b:a 192k -ar 48000 -shortest -movflags +faststart livrables/$nom.mp4
rm -f couvertures/tmp-$n.mp4
echo "$nom $(ffprobe -v error -show_entries format=duration -of csv=p=0 livrables/$nom.mp4) s"
