### lowriter installation on docker

```dockerfile
ARG ENABLE_LIBREOFFICE_WRITER=0
RUN if [ ${ENABLE_LIBREOFFICE_WRITER} = 1 ] ; then \
    mkdir -p /usr/share/man/man1 \
    && mkdir -p /.cache/dconf && chmod -R 777 /.cache/dconf \
    && apt-get update \
    && apt-get install -y --no-install-recommends openjdk-11-jre-headless \
    && apt-get install -y --no-install-recommends libreoffice-writer \
    && apt-get install -y --no-install-recommends libreoffice-java-common ;\
fi;
```
