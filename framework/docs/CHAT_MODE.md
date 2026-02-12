# CHAT_MODE

## Principle
Chat is a client. It executes the same contracts and commands as the UI.

## Command layer
- CreateApp, CreateTable, CreateField
- CreateForm, CreateRecord, UpdateRecord, QueryRecords
- RenderView (compact or link to UI)

## Slot filling
- If required fields missing, ask user.
- Do not ask for calculated fields.

## Example flows
- "Crea un cliente con nombre X" -> CreateRecord(cliente)
- "Crea factura con 2 items" -> CreateRecord(factura) + grid items
- "Muestrame facturas de hoy" -> QueryRecords(facturas)

## Contracts
- ChatContract v1 (intents -> commands)
- WorkflowContract v1 (triggers -> actions)

## Output policy
- Text summary + optional link to UI view.

## Editor JSON (pruebas)
- El editor incluye una pestaña "Chat/Pruebas" para ejecutar mensajes contra /api/command.
- Permite validar cambios al guardar proyecto (auto-run opcional).
