# syntax=docker/dockerfile:1.10
# ddd-browser renderer: screenshots of public web pages and browser sessions (README.md has the contract).
# Real Google Chrome stable (headed in Xvfb, driven by Patchright), Camoufox as fallback, consent rules and the cookie
# filter list baked in: nothing is downloaded at runtime. One image, two deployments: RENDER_ROLE=stateless|session.
# Base pinned by digest: node:24-bookworm-slim (multi-arch index digest, 2026-11-22).
FROM node:24-bookworm-slim@sha256:dc26ae92c9fb15cf6b1d63e20f0944b2efe0f115b20e00be48fb23fceb061c54

ENV NODE_ENV=production \
    HOME=/tmp/home \
    XDG_CONFIG_HOME=/tmp/home/.config \
    XDG_CACHE_HOME=/tmp/home/.cache \
    DISPLAY=:99 \
    TZ=Europe/Berlin \
    LANG=de_DE.UTF-8 \
    LC_ALL=de_DE.UTF-8 \
    PORT=8080 \
    CAMOUFOX_INSTALL_DIR=/opt/camoufox \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1

# Chrome stable from Google's APT repository (amd64 and arm64): the version the build finds is recorded in
# /app/chrome-version.txt and logged at startup. Xvfb for the headed browser, fonts for German pages and emoji,
# the de_DE locale, the libraries Firefox (Camoufox) needs.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg locales tzdata \
    && sed -i 's/^# *de_DE.UTF-8/de_DE.UTF-8/' /etc/locale.gen && locale-gen \
    && curl -fsSL https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends google-chrome-stable xvfb xauth \
       fonts-liberation fonts-dejavu-core fonts-noto-core fonts-noto-color-emoji fonts-noto-cjk \
       libgtk-3-0 libdbus-glib-1-2 libxt6 libx11-xcb1 libasound2 libpci3 \
    && google-chrome --version | tee /chrome-version.txt \
    && apt-get purge -y gnupg && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
# --ignore-scripts: no package needs its install script here (browsers are not downloaded by npm; the native
# better-sqlite3 of camoufox-js is optional and would need a compiler)
COPY package.json package-lock.json ./
# the Camoufox fetch asks the GitHub API for its release: with the optional build secret github_token it is not
# subject to the unauthenticated rate limit (60/h per IP, shared on CI runners)
RUN --mount=type=secret,id=github_token,env=GITHUB_TOKEN,required=false \
    npm ci --omit=dev --ignore-scripts --no-audit --no-fund \
    && env -u PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD npx camoufox-js fetch \
    && chmod -R a+rX /opt/camoufox \
    && npm cache clean --force

# EasyList Cookie (Fanboy's Cookie List), cosmetic rules only: the list of the build day
RUN mkdir -p /app/lists \
    && curl -fsSL https://secure.fanboy.co.nz/fanboy-cookiemonster.txt -o /app/lists/fanboy-cookiemonster.txt \
    && test "$(wc -l < /app/lists/fanboy-cookiemonster.txt)" -gt 1000

COPY server.mjs docker-entrypoint.sh ./
COPY lib ./lib
COPY test ./test
RUN mv /chrome-version.txt /app/chrome-version.txt && node --test test/units.test.mjs test/browser.test.mjs && chmod +x /app/docker-entrypoint.sh

# uid/gid 1000 (the image's `node` user); works with readOnlyRootFilesystem when /tmp and /dev/shm are writable emptyDirs
USER 1000:1000
EXPOSE 8080
ENTRYPOINT ["/app/docker-entrypoint.sh"]
