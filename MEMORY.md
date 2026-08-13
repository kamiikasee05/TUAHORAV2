# MEMORY — TUAHORAV2

## Qué es TUAHORAV2

**TUAHORAV2 es la v2 de TuAhora**: un SaaS de **turnos online 24/7 con recordatorios automáticos por WhatsApp** para pequeños negocios locales (salones de uñas, consultorios, odontólogos, etc.) en ciudades intermedias de Argentina. Operador: **Ezequiel Godoy** — Chamical, La Rioja. Inspiración: [Ágora](https://agora.red/).

La **v1** vive en `E:\TUAHORA` (Easy!Appointments + MySQL + n8n + Redis + Baileys, con landing para salón piloto). **La v2 se desarrolla desde cero en `E:\TUAHORAV2`** como proyecto propio, sin la deuda técnica del stack ajeno.

## Reglas

1. Este es el proyecto del humano. El Dispatcher coordina y mantiene la memoria; delega tareas a agentes especializados.
2. Toda decisión importante se registra en este archivo (fecha, decisión, justificación).
3. Infraestructura productiva (backups, dominio, HTTPS) solo después de tener cliente pagando — mientras tanto, desarrollo local.

## Estado actual (13/8/2026)

### DECISIONES

- **DECISION (13/8/2026) — Creación del repo v2:** el humano crea `E:\TUAHORAV2` (carpeta con `.git` inicializado, sin commits) y pide copiar al Dispatcher desde KAMIIKASEE para trabajar el proyecto acá. **Hecho:** `.opencode/`, `scripts/send-ntfy.ps1`, `opencode.json` y este `MEMORY.md`.
- **DECISION (13/8/2026) — Alcance v2 "minimalista":** TUAHORA se reduce a una versión acotada con forma de "moneda de dos lados":
  - **LADO A:** landing de presentación del profesional (quién es, dónde presta servicios) con botón → LADO B.
  - **LADO B:** página de reserva: dropdown de servicios + calendario con turnos disponibles.
  - **DASHBOARD (paralelo):** panel acotado para el profesional: cargar días y horarios de trabajo, servicios a ofrecer, y el **QR de OPENWA** para notificaciones por WhatsApp.
  - **Idea del humano:** reutilizar el desarrollo PHP que ya existe en `E:\TUAHORA` (en versión acotada). Regla del producto: lo más simple posible.
- **DECISION (13/8/2026) — Infraestructura v2 "GitHub Pages + Railway":** el humano define el hosting del producto listo:
  - **GitHub Pages** (gratis) → LADO A (landing) + LADO B (reserva): SPA estática. No corre PHP, confirma que el frontend es estático.
  - **Railway** (PaaS) → "el resto": Scheduler Node + SQLite (con volumen persistente), OpenWA (WhatsApp, sesión persistente), y definir si el dashboard PHP va acá (Dockerfile PHP-FPM).
  - **CORS pendiente:** la SPA en `.github.io` llamará por HTTPS a la API en Railway → hay que habilitar CORS en el Scheduler (en v1 no hacía falta: nginx proxya same-origin `/api/v1`).
  - **A validar:** planes/costos Railway (free tier vs Hobby), volúmenes para SQLite y sesión OpenWA, soporte PHP en Railway. → agente general investigando.

### HALLAZGO DE LA INVESTIGACIÓN RAILWAY/GITHUB PAGES (13/8/2026, agente general)

- **Railway 2026:** base mensual + uso medido (CPU $20/vCPU/mes, RAM $10/GB/mes, volumen $0.15/GB/mes, egress $0.05/GB). Hobby $5/mes (incluye $5 de uso), Pro $20/mes. Free = $1 de crédito, 0.5 GB RAM y 0.5 GB volumen por servicio → **inviable** para este proyecto (solo Trial $5/30 días sin tarjeta).
- **Costos estimados:** TODO en Railway (Scheduler + OpenWA + PHP + volumen) ≈ **$37/mes**; sin OpenWA ≈ **$17/mes**.
- **Todo es viable técnicamente:** Scheduler Node+SQLite (volumen en `/app/data`, 1 réplica obligatoria por SQLite), OpenWA (templates oficiales de Railway, sesión persiste con volumen en `/app/data/sessions` + `SESSION_DATA_PATH` + PUPPETEER_ARGS `--no-sandbox,--disable-dev-shm-usage,--disable-gpu`), PHP (Dockerfile nginx+php-fpm, `$PORT`, Railpack reemplazó Nixpacks).
- **Trampas CORS (la SPA .github.io → API .railway.app):** NO usar `RAILWAY_PRIVATE_DOMAIN` (`.railway.internal`) en Allow-Origin (los navegadores no lo resuelven); usar el público. Manejar preflight OPTIONS. Allowlist por env var `CORS_ALLOWED_ORIGINS=https://tu.github.io`. Comunicación server-to-server por red privada (evita egress).
- **Riesgo WhatsApp (el existencial):** IPs de datacenter penalizadas por WhatsApp. Uso reactivo/bajo volumen ≈ 2% ban a 12 meses (aceptable); proactivo/masivo 15-30% (no aplica). whatsapp-web.js (Chromium) = menor huella que Baileys. Mitigación máxima: OpenWA en IP residencial (casa del humano) + Cloudflare Tunnel gratis.
- **GitHub Pages:** sobra (estático, ≤1 GB, 100 GB/mes tráfico, 10 builds/hora, dominio custom + HTTPS gratis vía CNAME).
- **Recomendación Dispatcher para MVP:** todo en Railway (~$37/mes, camino 3, rápido) y mover OpenWA a la casa (camino 2, ~$17/mes) cuando haya cliente pagando. Decisión de negocio del humano: pendiente.
- **PENDIENTE (13/8/2026):** el humano confirma camino 3 (MVP en Railway) pero pregunta **qué alternativas hay a OpenWA** para el envío de WhatsApp. Candidatas a evaluar: WhatsApp Cloud API oficial (Meta), Twilio, Baileys directo, Evolution API (open source LatAm), Z-API, etc. → agente general investigando con datos 2026 (costos, riesgo ban, requisitos, migración desde OpenWA).

### HALLAZGO DE LA INVESTIGACIÓN ALTERNATIVAS A OPENWA (13/8/2026, agente general)

- **Contexto 2026:** Meta cobra por mensaje de plantilla desde 1/7/2025 (no por conversación); respuestas en ventana 24h gratis hasta 1/10/2026; Argentina factura en ARS desde abril 2026. El free tier de 1.000 conversaciones ya no existe.
- **WhatsApp Cloud API directa (sin BSP):** costo ≈ **US$3-8/mes** para nuestro volumen (plantillas Utility: confirmación + recordatorio 24h); riesgo de ban **nulo** (solo control de calidad apelable); corre en Railway trivial (solo HTTPS + webhook, sin sesión ni Puppeteer); funciona con móvil argentino normal (dedicado o Coexistence ≤20 msg/s); **onboarding = fricción real**: Meta Business + verificación (2-10 días hábiles, CUIT/documentos) + display name + plantillas. Formato del número argentino (con/sin "9") es una trampa en dev.
- **Twilio:** no aporta vs Cloud API directa a este volumen; factura en USD (impuesto país, sin factura A). Descartado salvo necesidad de BSP.
- **OpenWA (baseline actual):** $0 mensajería, hosting ≈ US$5 en Railway; riesgo medio ~2% ban a 12 meses en uso reactivo (incidente real julio 2026: WhatsApp Web renombró `id._serialized` → `id.$1`, roto 3 días). Onboarding cero (QR).
- **Evolution API** (open source BR, 9.3k⭐): migración MUY baja (mismo patrón REST+webhook), multi-instancia, motor intercambiable Baileys/Cloud API, template oficial Railway, pero requiere Postgres/MySQL+Redis (infra US$7-15/mes). Comunidad LatAm enorme.
- **Baileys/whatsapp-web.js pelados:** no convienen (reescribís todo / re-implementás OpenWA). Z-API: US$18 flat, ban real. Meta On-Premises: **muerta** (expiró 23/10/2025). BSP locales (Basework, Sirena, Cliengo): plataforma cerrada, overkill/cara para quien ya programa su Scheduler.
- **RECOMENDACIÓN (agente + Dispatcher):** estrategia en 2 fases — (1) MVP con OpenWA tal cual (fricción cero, ya funciona); (2) tramitar en paralelo onboarding de Cloud API con número dedicado y migrar solo el módulo WhatsApp (send-text → template utility) al aprobarse. El Scheduler de cuándo enviar recordatorios no cambia. Decisión del humano: pendiente.
- **IDEA (13/8/2026) — OpenWA en Raspberry Pi (el humano pregunta):** variante del camino 2 (IP residencial anti-ban) pero con Raspberry Pi en vez del Ubuntu Server de la casa: $0 hosting, 5W, dedicado. Condiciones: Pi 4/5 con 4GB+ (whatsapp-web.js = Chromium 400-500MB), almacenamiento durable (SSD USB > SD por degradación), SPOF (corte luz/internet = sin recordatorios, aceptable solo para MVP), imagen Docker ARM64 a verificar. → agente general verificando viabilidad ARM + requisitos + experiencia comunidad 2026.

### HALLAZGO DE LA INVESTIGACIÓN RASPBERRY PI (13/8/2026, agente general)

- **OpenWA en Pi: VIABLE y soportado oficialmente.** Imagen multi-arch (linux/amd64 + linux/arm64) en Docker Hub/GHCR. El Dockerfile instala chromium de Debian (nativo arm64) en la etapa arm64; builder corre en $BUILDPLATFORM. No hay que buildear nada.
- **RAM (corrige el MEMORY):** motor whatsapp-web.js = **~300-500 MB/sesión** (Chromium real, MENOR riesgo de ban pero MAYOR RAM); motor baileys = 30-80 MB (mayor riesgo de ban). Docs OpenWA: "RAM ~2 GB for the default engine"; docker-compose oficial usa mem_limit: 2g. **Pi 4 4 GB = mínimo viable** (1 sesión). Pi 3/Zero descartados.
- **Gotchas ARM64 (FAQ oficial):** sesión stuck en `authenticating` tras escanear QR → pinear `WWEBJS_WEB_VERSION=<build>`; first boot lento → `WWEBJS_AUTH_TIMEOUT_MS=120000`; OOM al iniciar sesión → mem_limit 2g; `--disable-dev-shm-usage` obligatorio en Pi (OpenWA ya lo incluye); creep de RAM de WWWeb → reinicio semanal programado del contenedor.
- **Almacenamiento:** la sesión (perfil Chromium completo) escribe constantemente → SD muere a los 6-18 meses sin aviso. **SSD USB 3.0 (UASP) o NVMe HAT, bootear desde SSD.** Si SD: endurance + noatime + Log2Ram.
- **Backup/restauración sesión:** stop limpio → tar de `/app/data` (sesión + openwa.sqlite + `.api-key`). **CRÍTICO: el perfil es binario-dependiente del Chromium que lo creó — un perfil amd64 NO es portable a arm64 → migrar del Ubuntu al Pi requiere re-escanear el QR.** Nunca restaurar copias viejas (desloguea).
- **Exposición:** Cloudflare Tunnel (cloudflared) en el mismo Pi → `openwa.tudominio.com` → `http://127.0.0.1:2785` (nunca 0.0.0.0), sin abrir puertos. Auth server-to-server con **Cloudflare Access Service Token** (Client-ID/Secret en headers) configurado en el Scheduler (las IPs de egress de Railway no son estables → NO allowlist de IPs). CORS no aplica acá (es server-to-server). Alternativas: Tailscale Funnel (solo *.ts.net), ngrok (pago para dominio estable), WireGuard+VPS (~$5/mes).
- **REVELACIÓN clave (CORREGIDA 13/8/2026):** el "Ubuntu 24.04 en 192.168.18.20" **NO es un servidor dedicado — es una VM dentro de la laptop del CNC del humano** (Windows + VirtualBox), conectada solo por WiFi. Esa VM corre el stack v1 completo (nginx+PHP+Scheduler+OpenWA). Implicaciones: (a) la recomendación "Fase 1: usar el Ubuntu existente" queda **debilitada** (comparte los mismos riesgos de la laptop: WiFi, apagón, comparte CPU/RAM con el CNC); (b) la IP residencial/anti-ban sigue siendo real pero con un anfitrión frágil.
- **IDEA (13/8/2026) — Máquina dedicada para proyectos (el humano):** el humano quiere **una máquina física dedicada** para sus proyectos (TuAhora v2 y futuros), en vez de la VM en la laptop del CNC. Esto reordena las opciones de hosting OpenWA:
  1. **Raspberry Pi 4/5** (US$60-100): bajo consumo, silencioso, ya investigado (viable, ARM64 soportado oficialmente) — bueno para OpenWA dedicado, limitado para cargas mayores.
  2. **Mini PC x86** (US$100-300): más potente, DDR/NVMe, Docker nativo x86, puede correr TODO el stack v2 (Scheduler + OpenWA + dashboard) e incluso otros proyectos; más consumo (~10-25W) que el Pi.
  3. **PC usada/armada**: más potencia/consumo; overkill para esto.
  - **Decisión pendiente del humano:** presupuesto y si la máquina corre solo OpenWA o todo el stack v2.
- **DECISION (13/8/2026) — Hardware dedicado elegido: Lenovo ThinkCentre M625** (AMD A4-9120E, 2 núcleos 2017, 8 GB RAM, SSD 128 GB, Ethernet Gigabit, SFF de oficina 24/7). Precio AR 2026: ~$340-360k ARS (notebookspro.ar $360k oferta / $340k Facebook jun-2026). **Análisis Dispatcher: COMPRAR.** Razones: 8 GB RAM + x86 nativo corren TODO el stack v2 (OpenWA+Scheduler+PHP+SQLite+cloudflared); diseño de oficina para 24/7; ~15-30W; comparable o más barato que Pi 4 completo pero con doble RAM y x86. Único punto flojo: CPU débil (suficiente para bajo volumen). Ampliable a 32 GB RAM. **Instrucciones de setup:** conectar por Ethernet (nunca WiFi), Ubuntu Server 24.04 LTS, Docker + compose, OpenWA bound a 127.0.0.1:2785 + volumen sesión, Cloudflare Tunnel → Railway.
- **REVISIÓN DE COMPRA (13/8/2026) — Cymax Flash Ryzen 3 3300U vs M625:** el humano plantea un segundo candidato, **MINI PC CYMAX FLASH — Ryzen 3 3300U — SSD 128 GB — 8 GB RAM — 4 núcleos a $410.000** (mismo vendedor notebookspro.ar). Comparativa: 3300U (Zen+ 2019, 4 núcleos, 2.1-3.5 GHz) es **3-4x más rápido** que el A4-9120E (Excavator 2017, 2 núcleos) con el mismo consumo (~15W); $50.000 más (14%) por CPU que deja margen de crecimiento para "máquina de proyectos". **Recomendación Dispatcher: COMPRAR EL CYMAX 3300U** sobre el M625. Cautela: marca genérica china (pedir garantía por escrito, verificar Ethernet Gigabit y que sea para cable). Decisión final del humano: pendiente confirmación.
- **REVISIÓN DE COMPRA (13/8/2026) — Voltic Celeron N150 vs Cymax 3300U (EL GANADOR):** el humano plantea un tercer candidato, **MINI PC VOLTIC CELERON N150 — 8 GB DDR4 — 256 GB SSD — a $399.999** (pronotebooks; $434.999 Necxus, garantía 6 meses, marca argentina con soporte local). Benchmarks PassMark: 3300U multi 5.690 vs N150 5.391 (~igual), single-core N150 gana (1.901 vs 1.830); **N150 consume 6W vs 15W** (mitad, ideal 24/7) y es 2024 (más nuevo). **Análisis Dispatcher: COMPRAR EL VOLTIC N150** — mismo rendimiento que el Cymax por $10k menos de precio de lista, doble SSD (256 GB), marca argentina con garantía local, consumo mínimo. Orden final: Voltic N150 → Cymax 3300U → M625. Verificar al vendedor: RAM ampliable (existe versión 16 GB del mismo modelo → slot lo permite), Ethernet Gigabit presente (no solo WiFi). Decisión final del humano: pendiente confirmación.
- **PENDIENTE (13/8/2026) — Financiamiento de la mini PC:** el humano está consiguiendo **financiamiento para comprar la Voltic N150**. Mientras tanto: se puede avanzar con la implementación del v2 en local (Windows) sin depender del hardware. Cuando la máquina llegue: setup Ubuntu Server + Docker + OpenWA + Cloudflare Tunnel → Railway.

### FASE 0 COMPLETADA (13/8/2026) — Código v1 → v2 copiado y limpiado

- **Estructura v2 creada:** `scheduler\` (motor Node+SQLite, 38 archivos + `src/config.ts` nuevo), `php\` (capa PHP acotada: env-loader, admin/index+logout, 10 api/*.php), `web\` (SPA Lado A+B), `.env.example`, `.gitignore`, `README.md`.
- **`providerId=5` parametrizado:** nuevo `scheduler/src/config.ts` con `PROVIDER_ID` (env, default 5); aplicado en db.ts, appointments, slots, days_off, branding (`syncProvider`), workflows. `branding.ts` inyecta `providerId` al config.json servido. PHP: `env-loader.php` con `providerId()` helper; SPA: `config.providerId || 5`. Dejados con default 5: esquema SQL, tests, migrate one-off.
- **Verificación:** `npm test` → **44/44 passing** (vitest, node:sqlite). npm install OK (7 vulns heredadas de v1, pendientes).
- **Excluidos:** scheduler/data (DB viva), node_modules, .env, dist, logs, ecosystem.config.js + deploy scripts (**credenciales reales**), php: dashboard legacy + 4 endpoints públicos muertos + directorio-vacío config.json, web/config.json (lo sirve el scheduler).
- **CORRECCIÓN de dato:** la marca real de la v1 es **"TeToca"** (no "Cuchi Mua" — estaba mal en esta memoria).
- **BUGS HEREDADOS a corregir:** (1) `php/admin/index.php` reagendar usa `fetch('../api/horarios.php')` → archivo no copiado (era "muerto" pero el admin SÍ lo usa) → crear `api/slots.php` proxy; (2) `scheduler/Dockerfile` CMD apunta a `src/index.js` pero el fuente es `.ts` (build a dist/ nunca usado) → corregir para Railway; (3) `whatsapp-notifier.php` legacy hardcodea dirección, usa query inexistente y accede a campo que no existe en API → deprecated/marcar; (4) `cancel-appointment.php` depende de `N8N_WEBHOOK_TOKEN` (n8n eliminado) → adaptar a webhook token del scheduler o deprecar.
- **BUGS CORREGIDOS (13/8/2026, agente general):** (1) creado `php/api/slots.php` proxy + `php/admin/index.php:1142` ahora apunta a `slots.php`; (2) `scheduler/package.json` script `build: tsc`, Dockerfile multi-stage → `CMD ["node","dist/index.js"]`, `.dockerignore`+`dist`; **bug extra crítico:** `src/migrate.ts` resolvía `migrations/` relativo a `__dirname` (crasheaba desde dist/ → muerte segura en Railway con volumen fresco) → ahora busca candidatos y lanza error claro; smoke test real OK (`node dist/index.js` → /health OK, /slots responde); (3) `whatsapp-notifier.php` **deprecado** (el camino canónico es inline en scheduler: notifyCustomer + cron 24h en workflows.ts + webhook CANCELAR); (4) `cancel-appointment.php` usa `SCHEDULER_API_KEY` (ya en env-loader) en vez de `N8N_WEBHOOK_TOKEN`; `.env.example` limpiado de N8N. `npm test` → **44/44** tras los cambios. PHP CLI no disponible en este Windows (validar `php -l` al desplegar). Nota: `scheduler/src/webhooks.ts` conserva scaffolding N8N no-op (inofensivo).
- **Tabla de opciones OpenWA:** (1) Ubuntu actual: $0, mínimo esfuerzo, solo agregar cloudflared (necesita ~2 GB RAM libre); (2) Pi 4 4GB + SSD: US$60-100 + $1/mes, medio esfuerzo, aislamiento total; (3) Railway: ~US$5/mes, bajo esfuerzo, IP datacenter ~2% ban.
- **Recomendación Dispatcher:** Fase 1 = exponer el OpenWA actual de 192.168.18.20 con Cloudflare Tunnel (1h de setup, $0); Fase 2 = comprar el Pi cuando el v1 se contamine o quieras independizar WhatsApp.
- **IDEA (13/8/2026) — VM Ubuntu en la laptop del CNC (el humano):** el humano tiene una laptop (con su láser CNC) que está **siempre encendida, conectada solo por WiFi**; propone generar una VM con Ubuntu Server ahí para hostear lo que haga falta (OpenWA). Análisis Dispatcher: $0 + IP residencial, PERO es la opción con más puntos frágiles: (a) comparte máquina con el CNC (2-3 GB RAM + CPU de OpenWA; apagón/cierre de laptop = sin recordatorios); (b) WiFi laptop 24/7 es inestable (ahorro de energía, desconexiones); (c) VM sobre Windows = overhead + capa extra (WSL2 alternativo pero no arranca solo sin trucos). **Antes de esto: ¿por qué no usar el Ubuntu 192.168.18.20 existente?** (mismo beneficio anti-ban, $0, 1h de setup vs 4-8h de VM). Pendiente: respuesta del humano + decisión.

### HALLAZGOS DE LA EXPLORACIÓN (13/8/2026, agente explore sobre E:\TUAHORA)

**IMPORTANTE: la documentación vieja de la v1 está obsoleta.** La v1 evolucionó en 3 eras y hoy es:
- **Scheduler Node.js + Express + SQLite** (`E:\TUAHORA\scheduler\`) — el motor real de turnos: routes `/services`, `/slots`, `/availabilities`, `/customers`, `/appointments`, `/days_off`, `/payments`, `/branding`, `/support`. Auth por `X-API-Key`. 44 tests pasando. Datos vivos en `scheduler/data/scheduler.db`.
- **PHP** (`E:\TUAHORA\landing-salon\`, 19 archivos) — capa fina de proxy por curl al Scheduler. **Lo que se usa de verdad:** `env-loader.php` + `admin/index.php` (dashboard: login bcrypt, tabs Marca/Color/Logo/Galería/Servicios/Turnos/Horarios/Clientes/Pagos/WhatsApp) + `api/admin-*.php` + `api/whatsapp-qr.php` (QR OpenWA) + `api/whatsapp-relay.php` + `api/whatsapp-notifier.php` (cron fallback) + `cancel-appointment.php`. **Los 4 endpoints públicos** (`servicios.php`, `horarios.php`, `disponibilidad.php`, `crear-turno.php`) **son código muerto** (la landing SPA los bypasea).
- **OpenWA en Docker** (`E:\TUAHORA\openwa\`, puerto :2785, github.com/rmyndharis/OpenWA, motor whatsapp-web.js) — QR, send-text, webhooks `message.received`. Es el único contenedor activo.
- **Landing SPA** (`E:\TUAHORA\landing\index.html`, vanilla JS + Tailwind CDN) — presenta la marca piloto **"Cuchi Mua"** y hace booking en 3 pasos llamando directo al Scheduler (nginx proxya `/api/v1`). `config.json` con branding lo sirve el Scheduler.
- **Eliminados:** n8n, Baileys, MySQL, Easy!Appointments, Redis(parcial), Docker Compose general, Mailpit. Marca piloto: "Cuchi Mua" (Chamical), `providerId = 5` hardcodeado en ~5 lugares (PHP, SPA, seed, scripts) — debe salir a configuración en la v2.
- **Anomalías:** `landing-salon\config.json` es un DIRECTORIO VACÍO (debería ser archivo); `scheduler/ecosystem.config.js` tiene credenciales reales (gitignoreado, NO commitear); docs referencian archivos inexistentes.
- **Servidor de producción v1:** Ubuntu Server 24.04 en 192.168.18.20, `/home/kamiikasee/tetoca/` (nginx + PHP-FPM + Scheduler Node/pm2 + SQLite + OpenWA Docker). Último commit `47fbfae`.

### PENDIENTE (próximo paso con el humano)

- **Definir la implementación del v2 minimalista** — hay 2 caminos posibles:
  1. **Reutilizar Scheduler Node+SQLite** como motor (ya funciona, testeado, maneja disponibilidad y WhatsApp) + PHP acotado solo para el admin + SPA minimalista para Lado A/B. Menor esfuerzo.
  2. **Todo PHP puro** contra SQLite (el humano dijo "usar el desarrollo en php") — más simple de mantener para 1 profesional, pero hay que reescribir lógica de disponibilidad y WhatsApp que ya está resuelta en el Scheduler.
  - **Recomendación del Dispatcher:** camino 1 (reutilizar lo que ya funciona, acotar). Falta confirmación del humano.
- **Decisiones de implementación a definir:** dónde vive el código del v2 (¿copiar scheduler+php a TUAHORAV2 o referenciar TUAHORA?), `providerId` a configuración, qué se elimina (n8n-workflows, scripts stale, docs viejos).

## Habilidades del Dispatcher

- **Notificaciones:** `scripts/send-ntfy.ps1` (copia desde KAMIIKASEE). Uso: `& "E:\TUAHORAV2\scripts\send-ntfy.ps1" -Title "..." -Message "..." -Priority 3 -Tags "..."`.
- **LLM local (opcional):** LM Studio en `http://127.0.0.1:1234/v1` con Gemma 4 E4B (tool-use, cargar con `-c 32768`) y GLM-4-9B (solo chat). Provider `lmstudio` configurado en opencode.json. **Lección de KAMIIKASEE: el agente con tool-use DEBE usar big-pickle (Zen); los locales son para chat/experimentos.**

---

> Fuente de verdad de TUAHORAV2: este archivo. Lo edita el Dispatcher después de cada sesión o decisión. Estado actual: v2 recién arrancada — falta definir el alcance respecto de la v1.
