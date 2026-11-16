# Visual-Browser des QA-Projekts (VC1): der eine Browser, in dem die reviewten
# Sollbilder aufgenommen und geprueft werden, lokal (Docker Desktop) wie in der
# CI (ubuntu-24.04). Reines QA-Tooling, wird nie ausgeliefert.
#
# Warum ein Container: Windows-Updates haben die Segoe-UI-Hashes des alten
# Windows-Vertrags dreimal veraendert, und die CI laeuft seit D01 auf Linux. Ein
# per Digest gepinntes Image friert Chromium, fontconfig und den Fontsatz fuer
# beide Seiten ein; tests/e2e/visual/runner-contract.json traegt die im laufenden
# Container gemessenen Werte.
#
# Der Container bekommt keinen Quellcode und keinen Bind-Mount. Der Testlauf,
# Seed, Vergleich und der einzige Writer bleiben auf dem Host; nur der Browser
# laeuft hier (Playwright run-server, Client per connectOptions). Die Anfragen
# des Browsers an das Portal laufen ueber exposeNetwork durch den Client, daher
# braucht dieser Dienst kein QA-Netz.
#
# Das Basisimage-Tag folgt der Playwright-Version aus tests/e2e/package-lock.json;
# Tag, Digest und LABEL prueft tests/e2e/tests/visual-contract.test.js gegen den
# Vertrag. --unsafe erlaubt dem Client, die Vertrags-Startargumente (launchArgs)
# zu setzen; der Port ist nur auf 127.0.0.1 veroeffentlicht.
FROM mcr.microsoft.com/playwright:v1.61.1-noble@sha256:5b8f294aff9041b7191c34a4bab3ac270157a28774d4b0660e9743297b697e48

LABEL org.opencontainers.image.base.name="mcr.microsoft.com/playwright:v1.61.1-noble@sha256:5b8f294aff9041b7191c34a4bab3ac270157a28774d4b0660e9743297b697e48"

# Schriftwahl fuer den Portal-Stack (Begruendung in der Datei); ohne sie rendert
# Chromium hier mit WenQuanYi Zen Hei.
COPY Docker/qa/visual-browser/fonts.conf /etc/fonts/local.conf

WORKDIR /opt/visual
COPY tests/e2e/package.json tests/e2e/package-lock.json ./
# Die Browser bringt das Basisimage unter PLAYWRIGHT_BROWSERS_PATH mit; npm ci
# installiert nur den lockfile-gepinnten Server ohne Download.
RUN PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm ci --ignore-scripts --no-audit --no-fund \
    && npm cache clean --force

USER pwuser
EXPOSE 3000
HEALTHCHECK --interval=2s --timeout=3s --retries=30 \
    CMD ["node", "-e", "require('net').connect(3000,'127.0.0.1').on('connect',()=>process.exit(0)).on('error',()=>process.exit(1))"]
ENTRYPOINT ["node", "/opt/visual/node_modules/playwright-core/cli.js", "run-server", "--host", "0.0.0.0", "--port", "3000", "--unsafe"]
