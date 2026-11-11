# Aybak's image. BKE installs it as an optional component, and anyone may run
# it, so it is built on the public PHP base and nothing else.

# The image's tag at 1x.ax/bke/aybak, read by the bahriya-images pipeline, which
# builds, pushes and signs it. A published tag is never re-pushed, so a change to
# the image changes this value, and the graph that installs it pins the new one.
ARG AYBAK_VERSION=1.0.1

# --platform=$BUILDPLATFORM: the dependencies are plain PHP, the same on every
# platform, so they are installed once, natively, rather than under emulation.
FROM --platform=$BUILDPLATFORM 1x.ax/mamluk/php/8.5:cli AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
COPY src ./src
# No development dependencies, and an authoritative class map: the autoloader
# loads only the classes listed at build time and never searches the disk.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --classmap-authoritative --no-scripts

# The base image carries git and the SSH client the git output needs, and the
# curl and pcntl extensions aybak needs. Nothing is installed on top of it.
FROM 1x.ax/mamluk/php/8.5:cli
WORKDIR /opt/aybak
COPY --from=vendor /src/vendor ./vendor
COPY src ./src
COPY bin ./bin
COPY LICENSE ./
# Fixed non-root UID. It needs an entry in /etc/passwd: ssh refuses to run for a
# UID it cannot look up ("No user exists for uid"), so a bare USER 10001 breaks
# the git output. No home directory: aybak gives git and ssh HOME=/tmp. The
# Deployment repeats the UID in its securityContext rather than trusting the
# image, so both say the same thing.
RUN groupadd --system --gid 10001 aybak \
    && useradd --system --uid 10001 --gid 10001 --no-create-home \
       --home-dir /tmp --shell /usr/sbin/nologin aybak
USER 10001:10001
ENTRYPOINT ["php", "/opt/aybak/bin/aybak"]
