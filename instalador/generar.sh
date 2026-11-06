#!/bin/bash
# Arma cartored-instalar.sh: instalador/cabecera.sh + el programa (bin, sbin, web, systemd, apache) embebido.
# Uso (desde la raiz del repositorio):  bash instalador/generar.sh
set -euo pipefail
cd "$(dirname "$0")/.."
salida=cartored-instalar.sh
{
    cat instalador/cabecera.sh
    # git archive: solo lo versionado (sin datos de la red ni secretos), con fin de linea LF
    git archive --format=tar HEAD bin sbin web systemd apache | gzip -9 | base64 -w 76
    printf '__CARGA__\n}\n\nmain "$@"\n'
} > "$salida"
chmod 755 "$salida"
bash -n "$salida"
echo "$salida: $(du -h "$salida" | cut -f1) (programa del commit $(git rev-parse --short HEAD))"
