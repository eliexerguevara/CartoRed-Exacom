# Proxy de CartoRed: contenedor para la red remota (MikroTik RouterOS 7 con el paquete container, o Docker).
# Se construye desde la raiz del repositorio:  docker build -f proxy/Dockerfile -t cartored-proxy .
FROM python:3.12-slim

# snmpwalk / snmpbulkwalk / snmpget y fping: los usa seccart2-anexar
RUN apt-get update \
 && apt-get install -y --no-install-recommends snmp fping \
 && rm -rf /var/lib/apt/lists/*

# SecureCartography2 sin la interfaz grafica (PyQt6): solo el recorrido por SNMP y el almacen de credenciales
RUN python -m venv /opt/seccart2 \
 && /opt/seccart2/bin/pip install --no-cache-dir --no-deps seccart2==1.2.1 \
 && /opt/seccart2/bin/pip install --no-cache-dir \
        pysnmp==7.1.30 pyasn1==0.6.5 cryptography paramiko==3.5.1 textfsm==2.1.0 PyYAML==6.0.3 aiofiles==25.1.0 \
 && find /opt/seccart2 -name '__pycache__' -prune -exec rm -rf {} +

COPY bin/seccart2-anexar proxy/seccart2-crawl proxy/cartored-proxy /usr/local/bin/
RUN chmod 755 /usr/local/bin/seccart2-anexar /usr/local/bin/seccart2-crawl /usr/local/bin/cartored-proxy

ENV PATH=/opt/seccart2/bin:$PATH \
    PYTHONUNBUFFERED=1 \
    CARTORED_DATOS=/data \
    SECCART2_HOME=/data/sc2
VOLUME /data
CMD ["/opt/seccart2/bin/python", "/usr/local/bin/cartored-proxy"]
