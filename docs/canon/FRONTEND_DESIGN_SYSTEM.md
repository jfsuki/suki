# SUKI Frontend Design System — Canónico

**Estado**: ACTIVO — Fuente de verdad para todo desarrollo frontend  
**Creado**: 2026-04-12  
**Aplicado a**: Todos los views del proyecto (app, builder, auth, dashboard, registro)

---

## Identidad Visual

**Concepto**: Blanco corporativo + Cyan tecnológico  
**Personalidad**: Elegante · Minimalista · Tecnológico · Empresarial  
**Diferenciador**: No usar morado, índigo ni gradientes oscuros — eso es identidad de Gemini/Google. SUKI usa blanco limpio con cyan profundo.

---

## Paleta de Colores — CSS Tokens

```css
:root {
  /* ── FONDOS ─────────────────────────────────────────── */
  --bg:           #F8FAFC;   /* slate-50 — fondo general de vistas */
  --bg-auth:      #F0F9FF;   /* sky-50  — fondo de login/registro  */
  --surface:      #FFFFFF;   /* blanco puro — tarjetas, paneles     */
  --surface2:     #F1F5F9;   /* slate-100 — hover, inputs           */
  --surface3:     #E8F4F8;   /* cyan-tint — activo/seleccionado     */

  /* ── BORDES ─────────────────────────────────────────── */
  --border:       #E2E8F0;         /* slate-200 — borde estándar     */
  --border-cyan:  #BAE6FD;         /* sky-200   — borde auth/cards   */

  /* ── ACENTO PRINCIPAL — CYAN ────────────────────────── */
  --accent:       #0891B2;         /* cyan-600  — acción primaria    */
  --accent-deep:  #0E7490;         /* cyan-700  — hover, gradiente   */
  --accent-light: #06B6D4;         /* cyan-500  — secundario/teal    */
  --accent-soft:  rgba(8,145,178,0.08);   /* fondo suave en chips    */
  --accent-glow:  rgba(8,145,178,0.18);   /* sombra/glow en botones  */

  /* ── TEAL SECUNDARIO ────────────────────────────────── */
  --teal:         #06B6D4;         /* cyan-500  — badges, avatares   */
  --teal-soft:    rgba(6,182,212,0.10);

  /* ── TEXTOS ─────────────────────────────────────────── */
  --text:         #0F172A;         /* slate-900 — texto principal    */
  --text-2:       #334155;         /* slate-700 — texto secundario   */
  --muted:        #64748B;         /* slate-500 — labels, placeholders */
  --muted-2:      #94A3B8;         /* slate-400 — muy sutil          */

  /* ── SEMÁNTICOS ─────────────────────────────────────── */
  --success:      #059669;         /* emerald-600 */
  --success-soft: rgba(5,150,105,0.10);
  --warning:      #D97706;         /* amber-600 */
  --warning-soft: rgba(217,119,6,0.10);
  --danger:       #EF4444;         /* red-500 */
  --danger-soft:  rgba(239,68,68,0.10);
  --amber:        #D97706;
  --amber-soft:   rgba(217,119,6,0.10);
  --rose:         #E11D48;
  --rose-soft:    rgba(225,29,72,0.10);

  /* ── DISEÑO ─────────────────────────────────────────── */
  --radius:       12px;
  --radius-sm:    8px;
  --radius-lg:    16px;
  --shadow:       0 1px 3px rgba(15,23,42,0.06), 0 4px 16px rgba(15,23,42,0.04);
  --shadow-md:    0 4px 24px rgba(15,23,42,0.08);
  --shadow-cyan:  0 4px 24px rgba(8,145,178,0.15);
  --font:         'Inter', system-ui, sans-serif;
  --tr:           0.20s cubic-bezier(0.4,0,0.2,1);
}
```

---

## Equivalencias Tailwind CSS

Para vistas que usen Tailwind CDN, usar estas clases en lugar de `indigo-*`:

| Propósito | Tailwind |
|---|---|
| Fondo primario | `bg-cyan-600` |
| Fondo hover | `bg-cyan-700` |
| Fondo suave | `bg-cyan-50` |
| Texto accent | `text-cyan-600` / `text-cyan-700` |
| Border accent | `border-cyan-200` |
| Badge/chip | `bg-cyan-50 text-cyan-600 border border-cyan-200` |
| Body bg | `bg-slate-50` |
| Texto principal | `text-slate-900` |
| Texto muted | `text-slate-500` |
| Border estándar | `border-slate-200` |
| Hover nav link | `hover:text-cyan-700 hover:bg-cyan-50` |

**Nunca usar**: `indigo-*`, `purple-*`, `violet-*` — son identidad de otras plataformas.

---

## Tipografía

- **Fuente primaria**: Inter (Google Fonts)
  - Weights: 300, 400, 500, 600, 700, 800
  - `<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">`
- **Fuente alternativa** (auth/registro): Outfit (Google Fonts)
  - `<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">`
- **Stack**: `'Inter', system-ui, sans-serif`

---

## Gradientes Canónicos

```css
/* Fondo de página (app/builder) — muy sutil */
background-image:
  radial-gradient(ellipse 70% 50% at 70% -10%, rgba(8,145,178,0.05) 0%, transparent 60%),
  radial-gradient(ellipse 50% 40% at 5% 100%,  rgba(6,182,212,0.04) 0%, transparent 50%);

/* Fondo de auth (login/registro) */
background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 50%, #CFFAFE 100%);

/* Logo / avatar principal */
background: linear-gradient(135deg, #0891B2 0%, #06B6D4 100%);

/* Botón/burbuja de usuario en chat */
background: linear-gradient(135deg, #0891B2 0%, #0E7490 100%);
```

---

## Componentes — Estilos Base

### Botón Primario
```css
background: #0891B2;
color: #FFFFFF;
border-radius: 10px;
padding: 12px 20px;
font-weight: 600;
transition: background 0.2s, transform 0.2s, box-shadow 0.2s;

/* hover */
background: #0E7490;
transform: translateY(-1px);
box-shadow: 0 8px 20px rgba(8,145,178,0.25);
```

### Input / Campo de formulario
```css
background: #F8FAFC;
border: 1px solid #E2E8F0;
border-radius: 10px;
padding: 12px 16px;
color: #0F172A;
font-family: 'Inter', sans-serif;

/* focus */
border-color: #0891B2;
background: #FFFFFF;
box-shadow: 0 0 0 3px rgba(8,145,178,0.12);
```

### Tarjeta / Panel
```css
background: #FFFFFF;
border: 1px solid #E2E8F0;
border-radius: 12px;
box-shadow: 0 1px 3px rgba(15,23,42,0.06), 0 4px 16px rgba(15,23,42,0.04);
```

### Navbar (app/dashboard)
```css
background: rgba(255,255,255,0.95);
backdrop-filter: blur(20px);
border-bottom: 1px solid #E2E8F0;
```

### Badge / Chip
```css
background: rgba(8,145,178,0.08);
color: #0891B2;
border: 1px solid rgba(8,145,178,0.20);
border-radius: 999px;
padding: 4px 10px;
font-size: 11px;
font-weight: 600;
text-transform: uppercase;
letter-spacing: 0.05em;
```

### Alert de error
```css
background: rgba(239,68,68,0.06);
border: 1px solid rgba(239,68,68,0.25);
color: #B91C1C;
border-radius: 10px;
```

### Alert de éxito
```css
background: rgba(5,150,105,0.08);
border: 1px solid rgba(5,150,105,0.25);
color: #065F46;
border-radius: 10px;
```

---

## Archivos Frontend — Guía de Aplicación

| Archivo | Tema | Notas |
|---|---|---|
| `project/views/includes/header.php` | Tailwind + Inter | Nav blanca, accents cyan-600 |
| `project/views/chat/app.php` | CSS vars | Usar tokens `:root` definidos arriba |
| `project/views/dashboard.php` | Tailwind | Usar clases `cyan-*`, no `indigo-*` |
| `framework/views/builder/includes/header.php` | CSS vars | Fondo `#F0F9FF`, accent cyan |
| `framework/views/auth/login.php` | CSS vars | Fondo gradiente cyan claro |
| `project/views/register_enterprise.php` | CSS vars | Mismo tratamiento que login |

---

## Reglas de Oro (NO ROMPER)

1. **Nunca fondo oscuro** en vistas de usuario final — solo blanco/light
2. **Nunca indigo/purple/violet** como color de marca — es identidad ajena
3. **Siempre Inter** como tipografía base
4. **Cyan-600 (`#0891B2`)** es el único color de acción primaria
5. **Bordes visibles pero sutiles** — `#E2E8F0` o `#BAE6FD`, nunca invisibles
6. **Sombras ligeras** — sin `box-shadow` de opacidad > 0.15 en light mode
7. **Gradientes solo en** logos, avatares, botones CTA y burbujas de chat
8. **Labels de formulario**: uppercase, tracking-wide, slate-500, tamaño 12px
