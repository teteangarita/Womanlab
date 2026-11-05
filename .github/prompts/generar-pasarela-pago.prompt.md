---
name: "Generar pasarela de pago"
description: "Implementa o mejora el checkout y la integración de pagos de Woman Lab usando el catálogo vigente del proyecto."
argument-hint: "Indica proveedor o cambio deseado (opcional); por defecto, conserva la configuración existente."
agent: "agent"
---

Implementa o mejora de extremo a extremo la pasarela de pago del sitio Woman Lab, tomando este repositorio como fuente de verdad y conservando sus tecnologías y diseño actuales.

## Contexto y catálogo inicial

Antes de editar, inspecciona el flujo actual en `index.html`, `app.js`, `worker.js`, las migraciones y `wrangler.jsonc`. Reutiliza el carrito, las rutas, el almacenamiento D1 y las integraciones existentes cuando corresponda; no dupliques ni reemplaces una integración funcional sin una razón técnica concreta.

El catálogo inicial debe incluir los artículos vigentes del catálogo del servidor, actualmente:

- Membresía Woman Lab mensual: $40.000 CLP
- Membresía Woman Lab trimestral: $105.000 CLP
- Membresía Woman Lab semestral: $192.000 CLP
- Membresía Woman Lab anual: $336.000 CLP
- Asesoría Nutricionista Deportivo: $30.000 CLP
- Sesión Kinesiología & Fisioterapia: $38.000 CLP
- Agua Cachantun gasificada: $1.500 CLP
- Gatorade: $2.500 CLP
- Gatorade Zero: $3.000 CLP
- Gatorlit Recover: $3.000 CLP
- Redbull 250 ml: $2.500 CLP

Confirma nombres, identificadores y precios contra `CATALOG` en `worker.js` y las tarjetas del catálogo en `index.html`. Si discrepan, conserva los identificadores existentes y señala la discrepancia; no inventes precios ni uses valores enviados por el navegador para calcular cobros. Moneda: CLP.

## Requisitos

- Completa el recorrido desde selección de artículos y datos del cliente hasta inicio del pago, retorno al sitio y visualización del resultado.
- Conserva el proveedor configurado actualmente en `wrangler.jsonc` salvo que el usuario solicite otro. Si se solicita proveedor nuevo, usa su API oficial y sus credenciales en variables de entorno/secretos; nunca agregues secretos al código o al repositorio.
- Valida en el servidor cada identificador, cantidad, precio, total, datos del cliente y transición de estado. El cliente solo debe enviar identificadores y cantidades; el servidor debe derivar precios y totales del catálogo confiable.
- Verifica callbacks/webhooks con los mecanismos oficiales del proveedor y confirma el estado consultando al proveedor cuando corresponda. No marques una orden como pagada basándote únicamente en parámetros de retorno del navegador.
- Conserva controles contra órdenes inválidas, cantidades fuera de rango, montos excesivos, orígenes no autorizados y callbacks duplicados. No registres datos sensibles ni credenciales.
- Guarda las órdenes y cambios de estado en D1 mediante las migraciones existentes o una nueva migración compatible. Mantén consistencia e idempotencia en actualizaciones de pago.
- Mantén la interfaz en español, responsiva y coherente con los estilos existentes de Woman Lab; muestra CLP con formato local y estados claros de carga, error, cancelación y éxito.
- No despliegues ni ejecutes operaciones reales de cobro. No afirmes que pagos reales están habilitados si faltan credenciales o configuración.

## Validación y entrega

Realiza el cambio más acotado que complete el recorrido solicitado. Ejecuta las comprobaciones disponibles en el proyecto y, si no hay pruebas automatizadas, valida al menos sintaxis y los escenarios accesibles sin credenciales: orden válida, artículo o cantidad inválida, proveedor no configurado y retorno/callback no verificado.

Al terminar, resume los archivos modificados, el recorrido de pago implementado, las variables/secretos que deben configurarse y las validaciones ejecutadas. Distingue claramente lo probado en modo local de lo que requiere credenciales o una prueba real del proveedor.