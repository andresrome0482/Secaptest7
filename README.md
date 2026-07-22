# Portal de verificación de identidad Didit ↔ Moodle (MoodleCloud)

Aplicación que exige verificar identidad (documento + selfie) **en cada
inicio de sesión** antes de que el usuario acceda a una instancia
MoodleCloud, sin necesidad de instalar plugins (que MoodleCloud no permite).

## Cómo funciona

```
1. El usuario entra al portal (Render) en vez de directo a Moodle.
2. Escribe su usuario o correo de Moodle.        → index.php / start.php
3. El portal crea una sesión de verificación
   en Didit y lo redirige allí.                  → verify.php
4. El usuario sube su documento + selfie en la
   página alojada por Didit.
5. Didit envía un webhook firmado con la
   decisión final.                               → webhook.php
6. Si es "Approved": el portal desuspende la
   cuenta en Moodle (suspended = 0) y guarda una
   fecha de expiración en un campo personalizado
   del usuario (diditsessionexpiry).
7. El usuario hace clic en "Ir al login de
   Moodle" e inicia sesión normalmente.           → callback.php
8. Un Cron Job separado revisa cada 5 minutos
   qué cuentas desuspendidas ya vencieron su
   ventana, y las vuelve a suspender.             → cli/resuspend.php
```

El paso 8 es la clave para que la verificación se exija **en cada inicio de
sesión**: la cuenta solo queda activa por una ventana corta (por defecto 15
minutos) y luego vuelve a bloquearse automáticamente, sin que el portal ni
Moodle necesiten guardar ningún estado propio — todo el estado vive en el
propio usuario de Moodle (campo personalizado), así que el servicio web y el
Cron Job no necesitan compartir base de datos ni disco.

## Qué necesitas confirmar/tener antes de desplegar

| Dato | Dónde se obtiene | ¿Ya lo tienes? |
|---|---|---|
| `DIDIT_API_KEY` | Consola de Didit → tu Application | ✅ (confirmado) |
| `DIDIT_WORKFLOW_ID` | Consola de Didit → tu Workflow (con `ID_VERIFICATION`) | ✅ (confirmado) |
| `DIDIT_WEBHOOK_SECRET` | Consola de Didit → Webhook destination → secret_shared_key | ✅ (confirmado) |
| `MOODLE_BASE_URL` | `https://aulapp-sintel-test.moodlecloud.com` | ✅ |
| `MOODLE_WS_TOKEN` | Ver sección "Configurar Moodle" abajo | ⬜ pendiente |
| Campo personalizado `diditsessionexpiry` en Moodle | Ver sección "Configurar Moodle" abajo | ⬜ pendiente |

## 1. Configurar Moodle

### 1.1 Crear el campo de perfil personalizado

*Administración del sitio → Usuarios → Permisos → Campos de perfil de
usuario → Perfil* → **Agregar un nuevo campo de perfil → Texto corto**:

- Nombre corto: `diditsessionexpiry`
- Nombre a mostrar: "Expiración de sesión Didit" (uso interno, puedes
  ocultarlo del formulario de edición de perfil del usuario si prefieres:
  en "Quién puede ver este campo" elige un valor que no exponga el dato a
  los propios usuarios, pero que sí sea legible por Servicios Web).

### 1.2 Habilitar Servicios Web REST

1. *Administración del sitio → Servidor → Servicios web → Vista general* →
   sigue el asistente y habilita **"Habilitar servicios web"**.
2. *Administración del sitio → Servidor → Servicios web → Gestionar
   protocolos* → habilita **REST**.
3. *Administración del sitio → Servidor → Servicios web → Servicios
   externos* → **Agregar** un servicio nuevo, por ejemplo `didit_portal`,
   marcado como "Solo usuarios autorizados", y agrégale estas funciones:
   - `core_user_get_users_by_field` (buscar usuario por username/email)
   - `core_user_get_users` (listar usuarios por criterio, usado por el cron)
   - `core_user_update_users` (suspender/desuspender y fijar el customfield)
4. Crea un usuario **dedicado** (no un administrador general) y asígnale
   únicamente la capability `moodle/user:update` (y `moodle/user:viewdetails`)
   en el contexto del sistema — evita usar una cuenta con permisos amplios.
5. Autoriza a ese usuario en el servicio `didit_portal`.
6. *Gestionar tokens* → genera un token para ese usuario+servicio. Ese es tu
   `MOODLE_WS_TOKEN`.

> Nota: la sintaxis exacta de estos menús puede variar levemente entre
> versiones de Moodle; si algún nombre de menú no coincide exactamente en tu
> instalación, busca "Servicios web" en el buscador de administración.

## 2. Desplegar en Render (100% gratis)

> **Importante sobre costos:** los Cron Jobs nativos de Render **nunca son
> gratis** (cargo mínimo de ~$1/mes; `plan: free` no es válido para
> `type: cron` — por eso viste ese error). Como pediste una solución
> gratuita, esta versión usa un solo **servicio web** (sí puede ser Free) y
> mueve la re-suspensión periódica a un endpoint HTTP protegido que
> disparas gratis desde afuera. Ver "Opción A" abajo.

`render.yaml` define un único servicio, `didit-moodle-portal`, en el plan
Free.

### Pasos

1. Sube esta carpeta a un repositorio de GitHub.
2. En Render: **New → Blueprint** → selecciona el repo. Render lee
   `render.yaml` y crea el servicio web.
3. Completa las variables marcadas `sync: false`:
   ```
   DIDIT_API_KEY=...
   DIDIT_WORKFLOW_ID=...
   DIDIT_WEBHOOK_SECRET=...
   MOODLE_BASE_URL=https://aulapp-sintel-test.moodlecloud.com
   MOODLE_WS_TOKEN=...
   PUBLIC_BASE_URL=  (complétalo después del primer deploy, ver paso 4)
   ```
   `CRON_SECRET` se genera solo (Render lo crea aleatoriamente); lo
   necesitarás copiado en el paso 4 de la sección "Opción A".
4. Una vez desplegado, Render asigna una URL pública (ej.
   `https://didit-moodle-portal.onrender.com`). Cópiala y actualiza
   `PUBLIC_BASE_URL` con ese valor; Render vuelve a desplegar solo.
5. Verifica que responde: `https://TU-URL.onrender.com/health.php` debe
   devolver un JSON con `"status":"ok"`.

   Nota: el plan Free "duerme" el servicio tras ~15 minutos sin tráfico; la
   primera petición tras dormir tarda unos segundos en responder. Esto
   también afecta al pinger de reactivación (ver Opción A) — algunas
   ejecuciones programadas simplemente despertarán el servicio en vez de
   ejecutar la re-suspensión a tiempo; el siguiente ciclo sí la aplicará.

### Opción A (gratis): disparar la re-suspensión desde afuera

El endpoint `public/cron-resuspend.php` hace el mismo trabajo que un Cron
Job, pero solo se ejecuta cuando alguien le hace una petición HTTP con el
secreto correcto. Dos formas gratuitas de dispararlo cada 5 minutos:

**A.1 — GitHub Actions (recomendado, incluido en este repo)**

El archivo `.github/workflows/resuspend.yml` ya está listo. Solo debes:

1. En tu repositorio de GitHub → **Settings → Secrets and variables →
   Actions → New repository secret**, crea:
   - `PORTAL_URL` = `https://TU-URL.onrender.com`
   - `CRON_SECRET` = el valor que Render generó (Dashboard del servicio →
     pestaña **Environment** → copia el valor de `CRON_SECRET`)
2. Listo — GitHub ejecutará el workflow cada 5 minutos automáticamente.

   Nota: GitHub puede retrasar workflows programados en momentos de alta
   carga en su plataforma, y los deshabilita automáticamente si el
   repositorio lleva 60 días sin actividad (un simple commit lo reactiva).
   Para un uso serio, revisa periódicamente la pestaña **Actions** de tu
   repo para confirmar que sigue corriendo.

**A.2 — cron-job.org (alternativa, sin depender de GitHub)**

1. Crea una cuenta gratuita en `cron-job.org`.
2. Crea un nuevo cronjob apuntando a:
   ```
   https://TU-URL.onrender.com/cron-resuspend.php?secret=TU_CRON_SECRET
   ```
3. Configura el intervalo cada 5 minutos.

### Opción B (de pago, ~$1+/mes): Cron Job nativo de Render

Si prefieres la simplicidad de un Cron Job administrado por Render en vez
de un pinger externo, descomenta el bloque `type: cron` al final de
`render.yaml` (usa `cli/resuspend.php`, ya incluido) y vuelve a sincronizar
el Blueprint.

## 3. Configurar el webhook en Didit

En la consola de Didit, **API & Webhooks → Webhook destinations**, agrega:

```
https://TU-URL.onrender.com/webhook.php
```

## 4. Cómo se le indica la nueva forma de entrar a los usuarios

En vez de compartir el enlace de login directo de Moodle
(`https://aulapp-sintel-test.moodlecloud.com/login/index.php`), comparte:

```
https://TU-URL.onrender.com/
```

Puedes poner esta URL como el enlace principal de acceso en tu sitio web,
correos de bienvenida, etc.

## 5. Probar el flujo completo

1. En Moodle, asegúrate de que el usuario de prueba tenga `suspended = 1`
   (estado de reposo) y que el campo `diditsessionexpiry` esté vacío.
2. Visita `https://TU-URL.onrender.com/`, escribe su usuario o correo.
3. Completa la verificación de prueba en Didit.
4. Deberías ver la pantalla "¡Identidad verificada!" con el botón hacia
   el login de Moodle.
5. En Moodle, confirma que el usuario quedó con `suspended = 0` y con un
   valor en `diditsessionexpiry`.
6. Inicia sesión normalmente en Moodle con su contraseña habitual.
7. Espera a que pase el tiempo de `SESSION_WINDOW_MINUTES` + hasta 5
   minutos (el ciclo del cron) y confirma que el usuario vuelve a quedar
   `suspended = 1` — el siguiente intento de acceso a Moodle debe fallar
   con "cuenta suspendida" hasta que repita la verificación.

## 6. Probar localmente antes de desplegar (opcional)

```bash
cp .env.example .env      # rellena tus credenciales de prueba
docker compose up --build
# el servidor queda en http://localhost:8080
```

## Seguridad

- Nunca subas `.env` ni el token de Moodle a un repositorio público
  (`.gitignore` ya lo excluye).
- El token de Moodle debe tener solo `moodle/user:update` (y
  `moodle/user:viewdetails`), nunca permisos de administrador completos.
- `webhook.php` valida la firma `X-Signature-V2` antes de procesar
  cualquier dato — no elimines esa verificación.
- `cli/resuspend.php` excluye explícitamente a las cuentas `admin` y
  `guest` como salvaguarda adicional; revisa esa lista si tienes otras
  cuentas de servicio que no deban tocarse.
- La identificación en `index.php`/`start.php` (usuario o correo) no es en
  sí misma una autenticación fuerte — la seguridad real la aporta Didit al
  verificar el documento y la selfie. Si quieres una capa adicional, puedes
  comparar el nombre extraído del documento por Didit contra el nombre
  registrado en Moodle antes de aprobar (mejora opcional no incluida en
  esta versión, para mantenerla simple).

## Límite conocido de esta arquitectura

La aplicación de la política "una verificación por sesión" depende de que
el Cron Job corra cada 5 minutos; existe una ventana de hasta
`SESSION_WINDOW_MINUTES + 5 minutos` en la que, técnicamente, la cuenta
sigue activa después de que debería haberse re-bloqueado. Para la mayoría
de los casos de uso esto es aceptable; si necesitas una re-suspensión al
segundo exacto de cerrar sesión, se requeriría interceptar el evento de
logout de Moodle directamente, lo cual no es posible sin un plugin — y
MoodleCloud no permite instalarlos.
