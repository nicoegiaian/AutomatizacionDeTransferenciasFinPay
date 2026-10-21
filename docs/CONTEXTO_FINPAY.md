# Contexto del proyecto FinPay / CredMoura

Mapa de cómo funciona todo el sistema: repos, servidor, procesos diarios, tablas y reglas de negocio.
Para el detalle del código usar el grafo de graphify (ver al final). Actualizado al 16/10/2026.

## 1. Repos y dónde corre cada uno

| Repo (local) | Qué es | En producción (VPS) | Contenedor |
|---|---|---|---|
| `AutomatizacionDeTransferenciasFinPay` (Symfony) | Transferencias por BIND a PDV y unidades (`TransferManager`), MUTE, reportes mensuales | `/opt/cobrosflex/app/AutomatizacionDeTransferencias` | `cobrosflex-cron` |
| `Moura-clean` (`api/`) | Procesos diarios: API de Menta, archivos diarios, cálculos, 12 cuotas, liquidación, Payway | `/opt/cobrosflex/app/api` | `cobrosflex-cron` y `cobrosflex-web` |
| `credimoura_mandala_pagosdigitales` | Portal web (ABMs, reportes, fondos, descuentos, conciliación) | `/opt/cobrosflex/app/portal` | `cobrosflex-web` |
| ProyectoFinPay | Orquestador diario (`app:orquestador_trx`) | `/opt/cobrosflex/app/ProyectoFinPay` | `cobrosflex-cron` |

- Base de datos: `cobrosflex-db` (MariaDB 10.11), base `u156146850_moura`. Buffer pool de InnoDB en 1 GB (`--innodb-buffer-pool-size=1G` en `/opt/cobrosflex/docker-compose.yml`, desde el 21/10/2026; con 128 MB la conciliación tardaba ~40 s). `SET GLOBAL` requiere root (`MYSQL_ROOT_PASSWORD` del contenedor). En local: Docker de credimoura, MySQL 8, base `localsix`.
- Los despliegues son copias de archivos (no hay `git pull` en el VPS). Siempre con backup en `/opt/cobrosflex/app/.deploy_backups/<fecha>_<motivo>/`.
- SSH: alias `cobrosflex-vps-admin` (usuario `finpayadmin` con sudo). Funciona desde PowerShell (OpenSSH de Windows), no desde Git Bash.
- El servidor viejo de Hostinger (`prepagasdigitales`) todavía ejecuta algunos crons (workers del portal, restaurantes, prisma sync, getcompanies, BindTransaccionesCron). MUTE, orquestador y reportes ya están solo en el VPS.

## 2. Procesos diarios (crons en `/etc/cron.d/cobrosflex-*`, horario UTC)

El cron del host ignora `CRON_TZ`: todos los horarios son UTC (Argentina = UTC−3).

| UTC | ART | Proceso | Qué hace |
|---|---|---|---|
| 06:00 | 03:00 | `cobrosflex-orquestador-trx` → `app:orquestador_trx` | 1) `procesador_API_Menta.php` (día anterior): ventas de la API; guarda devoluciones/anulaciones en `menta_operaciones_reversas`. 2) `archivosdiarios.php`: recibos TXT y transacciones. 3) `calcular_nuevos_campos.php`. 4) `procesar_financiacion_12cuotas.php`. 5) `liquidaciondiaria.php` (solo días hábiles) |
| 11:05 y 13:05 | 08:05 y 10:05 | `cobrosflex-payway` → `payway_download.sh` | Baja del SFTP de Payway Presentadas, Liquidadas y Contracargos (incremental; la 2.ª corrida cubre publicaciones tardías). Sincroniza fechas de liquidación, regenera archivos afectados, registra movimientos para conciliación (`conciliar_payway_diaria.php`) y genera descuentos "A revisar" (`generar_descuentos_reversas.php`) |
| 13:00 | 10:00 | `cobrosflex-mute-settlement` → `app:mute-settlement` | Liquidación MUTE: reparte el saldo de la cuenta MUTE (90 % del neto al tercero, resto a Pagos Digitales). Requiere CUIT de destino |
| 12:00 día 1 | 09:00 día 1 | `cobrosflex-mute-monthly-report` | Reporte mensual MUTE |
| 13:00 día 1 | 10:00 día 1 | `cobrosflex-generate-monthly-reports` | Reportes mensuales Moura (PDF por unidad) |
| — (al pago) | ~15:45 | `TransferManager` (lote del día por `fechapagobind`) | Transfiere por BIND a cada PDV y a cada unidad de negocio; aplica descuentos "Pendiente"; consume fondos de 12 cuotas; cierra el lote en `lotes_liquidacion` |

Logs: `/var/log/cobrosflex-*.log`, `/var/log/payway_sync.log` y `AutomatizacionDeTransferencias/var/log/` (`transferencias_diarias.log`, `respuesta_bind_json.log`, `automatizacion_mute.log`).

## 3. Tablas clave

| Tabla | Para qué |
|---|---|
| `transacciones` | Ventas. `fechapagobind` = fecha programada de pago; `transferencia_procesada/estado/id_bind/coelsa_id` = pago al PDV; `transferencia_fabricante_*` = pago a la unidad; `authorization_code` + `rrn` = clave contra Payway |
| `calculos_ahorro_credmoura` | Montos calculados por venta (`totalneto` canónico, aranceles, comisiones) |
| `splits` | Porcentaje PDV / unidad vigente por PDV (`porcentajepdv`; 0 = esquema 0-100) |
| `lotes_liquidacion` | Un lote por día de transferencias, con estado PDV y unidades |
| `descuentos_transferencias` | Descuentos a PDV o unidad. Estados: `A revisar` (automáticos, sin aplicar), `Pendiente` (TransferManager los aplica, incluso parcial), `Descontado`, `Cancelado` |
| `fondos_adelanto`, `adelantos_liquidacion(_fondos)`, `intereses_fondos_adelanto` | Fondos que Moura transfiere para adelantar las ventas en 12 cuotas |
| `payway_movimientos_conciliacion` | Cada fila de cada archivo de Payway (Presentadas, Liquidadas, Contracargos) con su `estado_cruce` y `nrotransaccion` |
| `payway_archivos_conciliacion` | Archivos de Payway ya procesados (idempotencia) |
| `menta_operaciones_reversas` | Devoluciones (`REFUND`) y anulaciones (`ANNULMENT`) de la API de Menta, con la venta original |
| `lotes_transferencias_mute` | Historial de liquidaciones MUTE |

## 4. Reglas de negocio

### Transferencias y descuentos
- El PDV cobra `totalneto × porcentajepdv`; la unidad cobra el resto menos comisiones, beneficio y arancel financiado.
- Descuento por devolución o contracargo de una venta ya pagada: **importe total de la venta al PDV** (la unidad compensa administrativamente su parte). Si el PDV tiene esquema 0-100, el descuento va a la **unidad de negocio**.
- Los descuentos automáticos se crean "A revisar" y se aprueban en el ABM de descuentos del portal (pasan a "Pendiente").
- Ventas con devolución o contracargo que todavía no se pagaron: **se bloquea el pago** (`BLOQUEADA_REVERSA`), salvo que Payway haya vuelto a presentar la venta.
- Si BIND da timeout, la transferencia puede haber salido igual: `TransferManager` la busca en los movimientos de BIND antes de marcar error (hasta 4 consultas cada 30 s).

### Fondos de 12 cuotas
- Los fondos activos se suman y se consumen del más antiguo al más nuevo según `fecha_transferencia` (fecha en que Moura transfirió el fondo, obligatoria). Un fondo en 0 pasa a `Agotado`.

### Conciliación con Payway
- **Cobertura**: desde el primer archivo de Liquidadas (18/09/2026). Lo anterior no se concilia.
- **Cruce**: un movimiento cruza solo si coinciden **código de autorización y RRN** (normalizados sin ceros a la izquierda). Si empatan varias ventas, desempata la fecha de compra y después el importe. Si no hay coincidencia exacta: `SIN_COINCIDENCIA`.
- **Códigos de operación**: `0005` venta, `1507` contracargo, `6000` devolución, `9100` cargos (sin autorización).
- **Contracargo** (lo inicia el banco del cliente): Payway informa la venta original (misma autorización y cupón); puede revertirse si se gana la disputa. **Devolución** (la inicia el comercio): es una operación nueva con su propia autorización; el vínculo con la venta solo está en la API (`reference_operation_number`). Payway a veces informa como contracargo la devolución de una venta en cuotas ("devolución de plan en cuotas").
- Los archivos de Presentadas repiten presentaciones en varias corridas; la pantalla agrupa por movimiento.
- Hay movimientos de otros comercios de FinPay en los mismos establecimientos de Payway (423400, 423459): aparecen "sin venta en CredMoura" y no generan descuentos.
- El TXT de recibos y la base pueden diferir en centavos (el TXT usa `calcularImporteNeto()`, la base el `totalneto` canónico). El validador de integridad tolera 0,02.

## 5. Operación y problemas conocidos
- Después de `cache:clear` como root, la caché de Symfony queda sin escritura para `www-data`: correrlo con `docker exec -u www-data`.
- SMTP: desde los contenedores el puerto 587 está bloqueado; se usa `smtp.gmail.com:465` con SSL.
- `dbal:run-sql` con comparaciones texto vs número entre tablas puede fallar por mezcla de colaciones (`uca1400` vs `general_ci`): forzar `COLLATE utf8mb4_general_ci` en ambos lados.
- Payway publica los archivos alrededor de las 08:00 ART, a veces más tarde (por eso la segunda corrida a las 10:05).
- El modo automático de Claude Code bloquea escrituras directas en la base de producción: se preparan los comandos y los corre una persona.

## 6. Pendientes al 19/10/2026
- Desplegado el 19/10 (backup `.deploy_backups/20261019_134543_descuentos_automaticos_conciliacion`): `generar_descuentos_reversas.php` + paso en `payway_download.sh`, bloqueo de pago en `TransferManager`, aprobación en el ABM de descuentos y pantalla de conciliación (tema emil por defecto; `?tema=apple` / `?tema=base`).
- Falta correr en prod `api/migration_descuentos_automaticos.sql` (estado "A revisar" y columnas `origen`, `aprobado_por`, `aprobado_en`): sin ella falla el generador de descuentos.
- Revisar el descuento #74 (RepCars, $25.000): la venta 39866582 se devolvió el mismo día y nunca se pagó al PDV.
- Opción B del TXT de recibos: que `archivosdiarios.php` escriba el total neto canónico.

## 7. Grafo de código (graphify)

Grafos locales generados con `graphify` en modo solo código (sin LLM, sin enviar código afuera):

- Por repo: `<repo>/graphify-out/graph.json`, `graph.html` (interactivo) y `GRAPH_REPORT.md`.
- Combinado de los 3 repos: `AutomatizacionDeTransferenciasFinPay/graphify-out/finpay-merged-graph.json`.
- Cada repo tiene un `.graphifyignore` que excluye dumps, `.sql` de datos, `.env`, `vendor`, planillas y logs.

```bash
python -m graphify query "como se aplican los descuentos" --graph graphify-out/finpay-merged-graph.json
python -m graphify explain "TransferManager" --graph graphify-out/finpay-merged-graph.json
python -m graphify path "conciliar_payway_diaria.php" "descuentos_transferencias" --graph graphify-out/finpay-merged-graph.json
```

Para actualizar después de cambios (desde cada repo): `GRAPHIFY_BACKEND=none python -m graphify update .` y volver a combinar con `python -m graphify merge-graphs ... --out graphify-out/finpay-merged-graph.json`.
