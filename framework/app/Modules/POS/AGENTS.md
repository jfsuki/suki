# POS AGENTS

## Responsibility
- Esta carpeta es una guia local para el dominio POS.
- Hoy el runtime POS sigue siendo mayormente compartido y contract-driven; no existe un modulo cerrado separado.

## Key classes
- `framework/app/Core/ChatAgent.php`
- `framework/app/Core/CrudCommandHandler.php`
- `framework/app/Core/EntitySearchService.php`
- `framework/app/Core/MediaService.php`

## Contracts involved
- `project/contracts/entities/*`
- `project/contracts/invoices/*`
- `framework/contracts/forms/ticket_pos.contract.json`
- `docs/contracts/action_catalog.json`

## Notes
- Si agregas flujo POS, preserva el patron compartido actual.
- No crear bypasses especificos de POS fuera del motor.
