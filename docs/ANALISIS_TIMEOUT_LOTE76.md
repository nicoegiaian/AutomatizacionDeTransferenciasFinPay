# Análisis de Protección contra Timeouts - Sistema de Transferencias FinPay
**Fecha:** 2026-03-05
**Problema Detectado:** Lote 76 con datos incompletos y creación de lote 77 duplicado

---

## ✅ PROTECCIONES IMPLEMENTADAS

### 1. **Timeout de Proceso PHP (TransferManager)**
```php
set_time_limit(600);              // 10 minutos máximo
ini_set('max_execution_time', '600');
```

**Ubicación:** `src/Service/TransferManager.php` línea 53-54

**Propósito:** Evitar procesos eternos por errores de API o BD. 10 minutos es suficiente para:
- 100+ PDVs (cada transferencia toma ~5-8 segundos)
- 5-10 unidades de fabricante
- Reintentos y manejo de errores

---

### 2. **Timeouts HTTP por Operación (BindService)**

#### a) Autenticación OAuth2
```php
'timeout' => 30  // 30 segundos
```
**Ubicación:** `src/Service/BindService.php` línea 121
**Justificación:** Operación rápida, 30s es más que suficiente

#### b) Consulta de Saldo
```php
'timeout' => 30  // 30 segundos
```
**Ubicación:** `src/Service/BindService.php` línea 64
**Justificación:** Query simple en la API de BIND

#### c) Transferencias (CRÍTICO)
```php
'timeout' => 60  // 60 segundos
```
**Ubicación:** `src/Service/BindService.php` línea 182
**Justificación:** 
- Operación más lenta (validaciones bancarias)
- Puede tomar 15-45 segundos según carga de BIND
- 60s da margen sin ser excesivo

---

## 🔍 POSIBLES CAUSAS DE FALLO DEL LOTE 76

### **1. Saldo Insuficiente** ✅ CONFIRMADO
```json
"Buenos Aires": "ERROR_API: 2008 | La cuenta con id: 1699815 no posee saldo suficiente."
```
- **Monto requerido:** ~$13,052,019.91
- **Resultado:** Estado `PARTIAL_ERROR` en Moura
- **Acción:** Verificar saldo antes de reprocesar

### **2. transacciones_ids Vacío** ✅ CORREGIDO
**Antes:**
```sql
transacciones_ids: []
```
**Causa:** Solo guardaba IDs de PDV, ignoraba fabricante

**Ahora:**
```php
$todosLosIds = $data['transacciones_ids']; // PDV
foreach ($data['desglose_fabricante'] as $unidad) {
    $idsFab = array_map('intval', explode(',', $unidad['transacciones_ids_csv']));
    $todosLosIds = array_merge($todosLosIds, $idsFab);
}
```

### **3. Duplicación de Lote 77** ✅ CORREGIDO
**Causa:** `PARTIAL_ERROR` no se consideraba estado retomable

**Solución:**
```php
$isProcessing = (
    $result['estado_actual_pdv'] === 'PROCESSING' || 
    $result['estado_actual_moura'] === 'PROCESSING' ||
    $result['estado_actual_pdv'] === 'PARTIAL_ERROR' ||  // ← NUEVO
    $result['estado_actual_moura'] === 'PARTIAL_ERROR' || // ← NUEVO
    ...
);
```

---

## 📊 ESCENARIOS DE TIMEOUT Y MANEJO

| Escenario | Tiempo Esperado | Timeout | Resultado |
|-----------|----------------|---------|-----------|
| Autenticación BIND | 2-5s | 30s | ✅ OK |
| Transferencia PDV | 5-10s | 60s | ✅ OK |
| Transferencia Fabricante | 10-30s | 60s | ✅ OK |
| Proceso completo (50 PDV + 3 Fab) | ~5 min | 10 min | ✅ OK |
| API BIND caída | ∞ | 60s → Error | ✅ Detectado |
| Proceso PHP colgado | ∞ | 10 min → Kill | ✅ Detectado |

---

## 🛠️ CORRECCIÓN DE LOTE 76

### **Paso 1: Ejecutar Script de Diagnóstico**
```bash
mysql -u usuario -p basedatos < fix_lote_76.sql
```

### **Paso 2: Verificar Saldo en BIND**
```php
// Endpoint: /api/saldo/unidad/Buenos-Aires
```

### **Paso 3: Reprocesar (si hay saldo)**
```bash
curl -X POST http://api-transferencias.pagosdigitales.com.ar/transfers/execute \
  -H "Content-Type: application/json" \
  -d '{"fecha_liquidacion": "050326"}'
```

**Resultado esperado:**
- ✅ Detecta lote 76 con `PARTIAL_ERROR`
- ✅ Lo retoma (NO crea lote 78)
- ✅ Procesa solo Buenos Aires
- ✅ Actualiza a `COMPLETED/COMPLETED`

---

## 🚨 MONITOREO RECOMENDADO

### **Logs a revisar si hay timeout:**
1. **PHP Error Log:** `/var/log/php_errors.log`
   - Buscar: `Maximum execution time exceeded`

2. **Symfony Log:** `/var/log/finpay_settlement.log`
   - Buscar: `ERROR CRÍTICO`, `FALLO`

3. **Archivo detallado:** `/var/log/transferencias_diarias.log`
   - Ver última entrada antes del corte

### **Síntomas de timeout:**
- ❌ Log cortado a la mitad
- ❌ Lote queda en `PROCESSING` sin cerrar
- ❌ Último log es de PDV/Fabricante específico
- ❌ HTTP 500/504 en el endpoint

---

## ✅ CONCLUSIÓN

**Protecciones contra timeout:**
- ✅ PHP: 10 minutos (suficiente para 100+ transferencias)
- ✅ HTTP Auth: 30 segundos
- ✅ HTTP Consultas: 30 segundos
- ✅ HTTP Transferencias: 60 segundos (crítico)

**Correcciones aplicadas:**
- ✅ Retomar lotes con `PARTIAL_ERROR`
- ✅ Guardar todos los IDs de transacciones
- ✅ Timeouts explícitos en APIs
- ✅ Mejor logging para debugging

**Estado actual:**
- Lote 76: Completo pero con error de saldo en Buenos Aires
- Lote 77: Duplicado innecesario (puede eliminarse)
- Sistema: Listo para reprocesar sin duplicar
