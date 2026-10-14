# DESIGN.md — Archon Nell design system

The visual language of the Performance & Development Management System (PDMS) of Archon Nell.
Every system built in the same cluster must follow it, so that all of them look and behave like one product.

Everything here is taken from the PDMS source (`frontend/src`). Class strings are Tailwind CSS 3 utilities and
are meant to be copied exactly. When this document and a guess disagree, this document wins.

---

## 1. Brand

| Item | Value |
|---|---|
| Company name | Archon Nell (full: "Archon Nell Incorporated") |
| Browser tab title | `Archon Nell` |
| Logo on light backgrounds | `/logo.png` (login card, printable documents) |
| Logo on the dark sidebar | `/logo-dark.png` (same logo with white lettering) |
| Favicon / touch icon | `/favicon.png` |
| Product subtitle pattern | "<System name>" under the company name, e.g. "Performance & Development Management" |
| Footer text | `© {current year} Archon Nell. All rights reserved.` |

### 1.1 Logo files

Three PNG files with transparent backgrounds. They live in `frontend/public/`, so the app serves them from the site root.

| File | Size | Made for | Where it is used | How |
|---|---|---|---|---|
| `logo.png` | 900 × 329 px | White / light backgrounds | Login card; header of printable pages (receipts, invoices, statements, certificates) | Login: `<img src="/logo.png" alt="Archon Nell Incorporated" className="mx-auto mb-4 h-24 w-auto max-w-full" />` |
| `logo-dark.png` | 900 × 329 px | The dark sidebar (`#0f172a`) | Sidebar logo bar | `<img src="/logo-dark.png" alt="Archon Nell Incorporated" className="h-14 w-auto shrink-0" />` |
| `favicon.png` | 256 × 256 px | Browser tab, home-screen icon | `index.html` | `<link rel="icon" type="image/png" href="/favicon.png" />` and `<link rel="apple-touch-icon" href="/favicon.png" />` |

What the logo looks like (so it is recognised and placed correctly): on the left, an atom symbol made of three crossing elliptical
orbits in rainbow colors (blue, green, yellow-orange, red) around a gold coin with green "ANI" letters; on the right, the word mark
"ARCHON NELL" in tall crimson-red capitals, "INCORPORATED" in spaced navy capitals beneath it, and the tagline
"PARTNER • INNOVATOR • SOLUTIONS PROVIDER" in black. In `logo-dark.png` the navy and black lettering is white, so it reads on the
dark sidebar. The favicon is the gold "ANI" coin with its orbits, cropped square.

Colors sampled from the logo, used only on printable documents (certificates in PDMS): navy `#183664`, red `#b00f30`.
They are not UI colors; the app UI uses the tokens in §2.1.

Rules:
- Use the files exactly as they are, through `<img>` with a fixed height and `w-auto` (aspect ratio about 2.74 : 1); minimum height 40 px.
- Never redraw the logo in SVG, CSS or text, never recolor, stretch, crop, rotate, outline or add shadows to it, and never place
  `logo.png` on a dark background or `logo-dark.png` on a light one.
- Alt text is always "Archon Nell Incorporated".
- A new system copies the three files unchanged from PDMS `frontend/public/` into its own `frontend/public/`.

---

## 2. Foundations

### 2.1 Color tokens (`tailwind.config.js`, verbatim)

```js
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        'brand-dark': '#0f172a',
        'brand-blue': '#1e3a8a',
        'brand-light-blue': '#e0f2fe',
        'brand-orange': '#ea580c',
        'brand-light-orange': '#ffedd5',
        'brand-red': '#dc2626',
        'brand-gray': '#f8fafc',
        'sidebar-bg': '#0f172a',
        'sidebar-hover': '#1e293b',
      },
      fontFamily: {
        sans: ['Inter', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
```

| Token | Hex | Used for |
|---|---|---|
| `brand-dark` | `#0f172a` | Login background, dashboard hero banner, dark buttons, avatar initials tiles |
| `brand-blue` | `#1e3a8a` | Primary buttons, active tab, links, focus borders, current page in pagination, eyebrow text |
| `brand-gray` | `#f8fafc` | Background of the content area inside the app shell |
| `sidebar-bg` | `#0f172a` | Sidebar |
| `sidebar-hover` | `#1e293b` | Sidebar item hover |
| `brand-red` | `#dc2626` | Destructive emphasis |
| `brand-orange`, `brand-light-orange`, `brand-light-blue` | | Available accents (rarely used) |

Everything else uses the stock Tailwind palette, mostly `slate` for neutrals:

| Purpose | Classes |
|---|---|
| Page body (outside the shell) | `#f1f5f9` background, `#1e293b` text (set in `index.css`) |
| Headings | `text-slate-900` |
| Body text | `text-slate-700` / `text-slate-600` |
| Secondary / helper text | `text-slate-500`, quieter `text-slate-400` |
| Card border | `border-slate-200`; dividers inside tables `divide-slate-100` |
| Input border | `border-slate-300` |
| Subtle fills | `bg-slate-50`, `bg-slate-100` |

Semantic tones (light background + dark text, used by pills, icon tiles and notices):

| Meaning | Pill / tile | Ring (pills) |
|---|---|---|
| Success / active / approved / paid | `bg-emerald-50 text-emerald-700` | `ring-emerald-600/20` |
| Warning / pending / inactive-ish | `bg-amber-50 text-amber-700` | `ring-amber-600/20` |
| Danger / failed / terminated / rejected | `bg-red-50 text-red-700` | `ring-red-600/20` |
| Info / in progress | `bg-blue-50 text-blue-700` | `ring-blue-600/20` |
| Neutral / draft / unknown | `bg-slate-100 text-slate-700` | `ring-slate-500/20` |
| Extra category accents | `violet`, `teal`, `orange`, `purple` at `-50` background / `-700` text | |

### 2.2 Global stylesheet (`src/index.css`, verbatim)

```css
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  body {
    font-family: 'Inter', sans-serif;
    background-color: #f1f5f9;
    color: #1e293b;
  }
}

/* Force <select> option lists to always open downward.
   Uses Chromium's customizable select (appearance: base-select); other
   browsers ignore this and keep native behavior. */
select,
::picker(select) {
  appearance: base-select;
}

::picker(select) {
  position-area: block-end span-inline-end;
  position-try-fallbacks: none;
  margin-block-start: 4px;
  min-width: anchor-size(width);
  max-height: 16rem;
  overflow-y: auto;
  border: 1px solid #cbd5e1;
  border-radius: 0.5rem;
  background: #ffffff;
  box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
}

select option {
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
}

select option:hover,
select option:focus {
  background-color: #eff6ff;
}

@layer components {
  .custom-scrollbar::-webkit-scrollbar {
    width: 6px;
  }
  .custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
  }
  .custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #cbd5e1;
    border-radius: 20px;
  }
}
```

`postcss.config.js`:

```js
export default {
  plugins: {
    tailwindcss: {},
    autoprefixer: {},
  },
}
```

Imports at the top of `src/main.jsx` (both icon sets are used):

```js
import '@fortawesome/fontawesome-free/css/all.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
import './index.css'
```

### 2.3 Typography

Font: **Inter**, weights 400, 500, 600, 700 only.

| Role | Classes |
|---|---|
| Page title (h1) | `text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl` |
| Page eyebrow (above h1) | `text-xs font-semibold uppercase tracking-[0.18em] text-brand-blue` |
| Page description | `text-sm text-slate-500` |
| Section / card title (h2) | `text-base font-semibold text-slate-900` |
| Section description | `mt-0.5 text-xs text-slate-500` |
| Card label / stat label | `text-xs font-semibold uppercase tracking-wider text-slate-500` |
| Big stat number | `text-3xl font-bold tracking-tight text-slate-900` (stat card), `text-2xl font-bold text-slate-900` (summary card) |
| Table header | `text-[11px] font-semibold uppercase tracking-wider text-slate-500` |
| Body / table cell | `text-sm` |
| Form label | `text-xs font-semibold text-slate-700` |
| Codes / IDs (e.g. employee code, reference no.) | `font-mono text-xs font-semibold text-slate-600` |
| Numbers in tiles | add `tabular-nums` |

### 2.4 Shape, depth, spacing

| Item | Value |
|---|---|
| Cards, sections, panels | `rounded-xl border border-slate-200 bg-white shadow-sm` |
| Card padding | `p-5` (`sm:p-6` for large cards); compact summary cards `p-4` |
| Buttons, inputs, icon tiles | `rounded-lg` |
| Pills / badges / avatars | `rounded-full` |
| Login card | `rounded-2xl shadow-xl` |
| Dropdown menus | `rounded-xl border border-slate-200 bg-white shadow-lg` |
| Vertical rhythm of a page | `space-y-6` between sections |
| Grid gaps | `gap-4` (stat cards), `gap-3` (summary cards), `gap-5`/`gap-6` (charts, larger cards) |
| Content width | `mx-auto w-full min-w-0 max-w-7xl` |

### 2.5 Icons

- **Font Awesome Free 7** is the main set: `fa-solid` for actions and navigation, `fa-regular` for light glyphs
  (`fa-regular fa-eye`, `fa-regular fa-bell`, `fa-regular fa-calendar`, `fa-regular fa-user`).
- **Bootstrap Icons 1.11** appear in a few places: pagination chevrons (`bi bi-chevron-left/right`),
  the confirm dialog icon and score badges. Keep them there; use Font Awesome everywhere else.
- Decorative icons always get `aria-hidden="true"`. Icon-only buttons always get an `aria-label`.
- Spinner: `fa-solid fa-circle-notch animate-spin`. Refresh: `fa-solid fa-rotate-right` (spins while fetching).

### 2.6 Motion

Only small, functional motion: `transition` / `transition-colors` on interactive elements, `hover:-translate-y-0.5 hover:shadow-md`
on stat cards, `animate-pulse` skeletons, `animate-spin` spinners, sidebar slide `transition-transform duration-200 ease-in-out`.
No page transitions, no bouncing, no parallax.

---

## 3. App shell

```
┌──────────────┬──────────────────────────────────────────────────────┐
│              │ Topbar  h-16  white, border-b                        │
│  Sidebar     ├──────────────────────────────────────────────────────┤
│  w-64        │ main  p-4 sm:p-6  bg-brand-gray  (scrolls)           │
│  #0f172a     │   max-w-7xl centered                                 │
│  (off-canvas │   page content …                                     │
│   below md)  │   footer (border-t, © Archon Nell …)                 │
└──────────────┴──────────────────────────────────────────────────────┘
```

### 3.1 `MainLayout.jsx` (verbatim)

```jsx
import { useState } from 'react'
import Sidebar from './Sidebar'
import Topbar from './Topbar'

export default function MainLayout({ children }) {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="flex h-screen overflow-hidden text-slate-800">
      <Sidebar isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-brand-gray">
        <Topbar sidebarOpen={sidebarOpen} onMenuClick={() => setSidebarOpen(true)} />

        <main className="custom-scrollbar flex-1 overflow-y-auto overflow-x-hidden p-4 sm:p-6">
          <div className="mx-auto w-full min-w-0 max-w-7xl">
            {children}

            <footer className="mt-8 flex flex-col items-center justify-between gap-4 border-t border-slate-200 pb-2 pt-4 md:flex-row">
              <p className="text-center text-xs text-slate-500 md:text-left">
                &copy; {new Date().getFullYear()} Archon Nell. All rights reserved.
              </p>
              <div className="flex flex-wrap items-center justify-center gap-4 text-xs text-slate-500">
                <a className="hover:text-brand-blue" href="#">Privacy Policy</a>
                <a className="hover:text-brand-blue" href="#">Terms of Service</a>
                <a className="hover:text-brand-blue" href="#">Help Center</a>
              </div>
            </footer>
          </div>
        </main>
      </div>
    </div>
  )
}
```

Every signed-in page renders inside `<MainLayout>`. The login page does not.

### 3.2 Sidebar

- `aside`: `fixed inset-y-0 left-0 z-40 flex h-full w-72 max-w-[85vw] flex-col bg-sidebar-bg text-white shadow-2xl transform transition-transform duration-200 ease-in-out md:static md:z-auto md:w-64 md:max-w-none md:translate-x-0 md:shadow-none`,
  plus `translate-x-0` when open and `-translate-x-full` when closed (mobile only).
- Logo bar: `flex h-16 shrink-0 items-center justify-between gap-3 border-b border-slate-700 px-4 md:px-6`, containing
  `<img src="/logo-dark.png" alt="Archon Nell Incorporated" className="h-14 w-auto shrink-0" />` and, below `md`,
  a close button (`fa-solid fa-xmark`).
- Nav list wrapper: `flex-1 overflow-y-auto custom-scrollbar py-4 px-3`; list `space-y-1 text-sm font-medium`.
- Nav item: `flex items-center gap-3 px-3 py-2 rounded-lg transition-colors` +
  active `bg-blue-900/50 text-blue-400`, inactive `text-slate-300 hover:bg-sidebar-hover hover:text-white`.
- Item icon: `<i className="fa-solid {icon} w-5 text-center" />`, then the module name in Title Case.
- Bottom spacer: `p-4 border-t border-slate-700`.
- Mobile backdrop (when open): `fixed inset-0 z-30 bg-slate-950/55 backdrop-blur-[1px] md:hidden`; Escape closes it; body scroll locked.
- Items come from one array `{ name, path, icon }`; Admin-only items are appended for Admins (PDMS adds "Audit Logs" with `fa-shield-halved`).
- Active rule: exact match, or `location.pathname.startsWith(item.path)`; Dashboard is also active on `/`.

### 3.3 Topbar

- `header`: `relative z-20 flex h-16 shrink-0 items-center justify-between gap-2 border-b border-slate-200 bg-white px-3 sm:px-6`.
- Left: hamburger (below `md`, `fa-solid fa-bars`, `h-10 w-10 rounded-lg border border-slate-200 bg-white text-slate-500 shadow-sm`),
  the page title on phones (`text-sm font-semibold text-slate-700 sm:hidden`), and from `sm` up a search box:
  wrapper `relative hidden w-full max-w-md sm:block`, icon `fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400`,
  input `w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-4 text-sm transition-all focus:border-brand-blue/50 focus:outline-none focus:ring-2 focus:ring-brand-blue/20`.
- Right (`flex shrink-0 items-center gap-3 sm:gap-5`): notification bell, then the profile pill.
- Profile pill: `flex items-center gap-2 rounded-full border border-slate-200 bg-white py-1.5 pl-1.5 pr-3 text-left shadow-sm transition-all hover:border-slate-300 hover:bg-slate-50`,
  avatar `h-8 w-8 rounded-full` (fallback `https://ui-avatars.com/api/?name=<name>&background=1e3a8a&color=fff`),
  name `text-sm font-semibold leading-tight text-slate-700` and role `text-xs leading-tight text-slate-500` (from `md`), chevron rotates 180° when open.
- Profile menu: `absolute right-0 z-50 mt-2 w-48 rounded-xl border border-slate-200 bg-white py-1 shadow-lg`; header block with name/email;
  items `flex w-full items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50` ("Profile" → `/settings`);
  "Logout" item `text-red-600 hover:bg-red-50` with `fa-solid fa-arrow-right-from-bracket`. Closes on outside click.
- Notification bell: `fa-regular fa-bell text-lg` in `relative text-slate-400 hover:text-slate-600 rounded-lg p-1`; unread badge
  `absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full border-2 border-white bg-red-500 px-1 text-[10px] font-bold text-white`;
  panel `absolute right-0 z-50 mt-2 w-80 sm:w-96 rounded-xl border border-slate-200 bg-white py-2 shadow-lg`, header row
  "Notifications" + "Mark all read" link (`text-xs font-medium text-brand-blue`) + refresh icon, list `max-h-96 overflow-y-auto`. Unread count polls every 30 s.

---

## 4. Page anatomy

A list/management page is built from these blocks, top to bottom, with `space-y-6`:

1. **Page header**
   ```jsx
   <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
     <div>
       <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-blue">People operations</p>
       <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Employee directory</h1>
       <p className="mt-1 text-sm text-slate-500">Search and view workforce records.</p>
     </div>
     <div className="flex flex-wrap gap-2">{/* secondary buttons first, primary button last */}</div>
   </header>
   ```
2. **Notice** (optional, after an action) — see §5.10.
3. **Summary cards row** — `grid grid-cols-2 gap-3 lg:grid-cols-4` of summary cards (§5.6).
4. **Main section card** with a toolbar, the list (table or card grid), and pagination (§5.7, §5.11).
5. **Modals** for create / edit / view (§5.8).

The **dashboard** opens with a dark hero banner instead of a page header:

```jsx
<section className="overflow-hidden rounded-xl bg-brand-dark text-white shadow-sm">
  <div className="grid gap-6 px-5 py-6 sm:px-7 lg:grid-cols-[1fr_auto] lg:items-center">
    <div>
      <p className="text-xs font-semibold uppercase tracking-[0.18em] text-blue-200">Organization overview</p>
      <h1 className="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Welcome back, {firstName}</h1>
      <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-300">One-line description of the system.</p>
      <p className="mt-4 flex items-center gap-2 text-xs font-medium text-slate-400">
        <i className="fa-regular fa-calendar" aria-hidden="true" />{today /* e.g. "Friday, October 9" */}
      </p>
    </div>
    <nav className="flex flex-wrap gap-2" aria-label="Dashboard shortcuts">
      <a className="inline-flex items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-3.5 py-2.5 text-xs font-semibold text-white transition hover:border-white/25 hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-blue-300">
        <i className="fa-solid fa-bullseye" aria-hidden="true" />Shortcut
      </a>
    </nav>
  </div>
</section>
```

Then: a "snapshot" section of stat cards (`grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3`), health/score cards,
status distribution cards (`grid grid-cols-1 gap-5 lg:grid-cols-3`), and a "Recent activity" table card.

Section heading pattern (outside cards):

```jsx
<div className="mb-3 flex items-end justify-between gap-4">
  <div>
    <h2 className="text-base font-semibold text-slate-900">Workforce snapshot</h2>
    <p className="mt-0.5 text-xs text-slate-500">Key indicators across the organization</p>
  </div>
  {isFetching && !isLoading && <span className="text-xs font-medium text-slate-400">Refreshing data...</span>}
</div>
```

---

## 5. Components

### 5.1 Buttons

| Kind | Classes |
|---|---|
| Primary (create, save) | `inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-300 disabled:opacity-60` |
| Dark (strong secondary, "Try again", login submit) | `inline-flex items-center justify-center gap-2 rounded-lg bg-brand-dark px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400` |
| Secondary / outline (Cancel, Export CSV) | `inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:opacity-60` |
| Ghost (Clear filters) | `rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300` |
| Danger (confirm delete) | `px-4 py-2 rounded-lg text-sm font-medium transition-colors bg-red-600 hover:bg-red-700 text-white` |
| Small dark (inline actions) | `inline-flex items-center gap-2 rounded-lg bg-brand-dark px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800` |
| Icon-only (row actions) | `flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300` + `title` + `aria-label` |
| Text link | `inline-flex items-center gap-1.5 text-xs font-semibold text-brand-blue hover:text-blue-700` (often ends with `fa-arrow-right`) |

Rules: every button has a leading Font Awesome icon (`fa-plus` create, `fa-floppy-disk` save, `fa-file-arrow-down` export,
`fa-pen` edit, `fa-trash` delete, `fa-regular fa-eye` view). While busy the icon becomes `fa-circle-notch animate-spin`
and the label becomes "Saving...", "Exporting...", etc. Buttons are disabled while their request runs. Labels are verb-first,
sentence case: "Create course", "Export CSV", "Update goal".

### 5.2 Form fields

```jsx
const fieldClass = 'mt-1.5 w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-brand-blue focus:ring-2 focus:ring-blue-100'

function Field({ label, htmlFor, children }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="text-xs font-semibold text-slate-700">{label}</label>
      {children}
    </div>
  )
}
```

- `input`, `select` and `textarea` all use `fieldClass`. Every control has a `<label htmlFor>`.
- Optional hint under a field: `mt-1 text-xs text-slate-500`.
- Layout: `form className="space-y-5"`; two columns with `grid grid-cols-1 gap-4 sm:grid-cols-2`.
- Grouped fields: `<fieldset className="rounded-lg border border-slate-200 p-4">` with `<legend className="px-1 text-xs font-semibold text-slate-700">`.
- Toggle row (checkbox with explanation):
  `<label className="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">`, checkbox first,
  then a `text-sm font-semibold text-slate-700` title and a `text-xs text-slate-500` line.
- Form-level error at the top of the form:
  ```jsx
  <div role="alert" className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
    <i className="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true" />{formError}
  </div>
  ```
- Footer (always last):
  ```jsx
  <div className="flex flex-col-reverse gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
    <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
    <button type="submit" disabled={isPending} className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-blue px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-900 disabled:opacity-60">
      <i className={`fa-solid ${isPending ? 'fa-circle-notch animate-spin' : 'fa-floppy-disk'}`} aria-hidden="true" />
      {isPending ? 'Saving...' : editingId ? 'Update item' : 'Create item'}
    </button>
  </div>
  ```
- Native validation is used (`required`, `min`, `max`, `maxLength`); server 422 messages are shown in the form-level alert.
- Date fields for things still to come get `min` = today (local date, `YYYY-MM-DD`, built from `getFullYear/getMonth/getDate`, never
  `toISOString()`); end dates get `min` = the start date. A record opened for editing keeps its existing past date valid.

Filter toolbar controls (inside a section card) use a slightly different, compact style:

- Search: `<label className="relative">` with `fa-solid fa-magnifying-glass absolute left-3 top-3 text-xs text-slate-400` and input
  `w-full rounded-lg border border-slate-300 py-2.5 pl-9 pr-3 text-sm outline-none focus:border-brand-blue focus:ring-2 focus:ring-blue-100` (`type="search"`, debounced 350 ms, resets to page 1).
- Filter select: `rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-brand-blue focus:ring-2 focus:ring-blue-100`, first option "All …", with `aria-label`.
- A ghost "Clear" button resets all filters.

### 5.3 Status pills

```jsx
const statusColors = {
  active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  inactive: 'bg-slate-100 text-slate-700 ring-slate-500/20',
  terminated: 'bg-red-50 text-red-700 ring-red-600/20',
}

<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold capitalize ring-1 ring-inset ${statusColors[status] || statusColors.inactive}`}>{status}</span>
```

Map every status of a new system onto the tones in §2.1 (success, warning, danger, info, neutral). Keep one map per
entity in a `src/lib/<module>.js` file, next to the human labels.

Score/health badge (no ring, with an icon): `inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold` + tone, e.g.
`bg-emerald-50 text-emerald-700` + `bi bi-trophy-fill`.

### 5.4 Avatars / initials

Initials tile in tables: `flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-brand-dark text-xs font-bold text-white`
(first letters of the first two name parts, uppercase). Photo avatars: `h-8 w-8 rounded-full`.

### 5.5 Stat card (dashboard)

```jsx
export default function StatCard({ title, value, icon, iconBg, iconColor, accent, helper, isLoading }) {
  return (
    <article className="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md">
      <span className={`absolute inset-x-0 top-0 h-1 ${accent}`} aria-hidden="true" />
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</p>
          {isLoading ? (
            <div className="mt-3 h-8 w-16 animate-pulse rounded bg-slate-100" aria-label={`Loading ${title}`} />
          ) : (
            <p className="mt-2 text-3xl font-bold tracking-tight text-slate-900">{value}</p>
          )}
          <p className="mt-2 truncate text-xs text-slate-500">{helper}</p>
        </div>
        <div className={`flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-lg ${iconBg} ${iconColor}`}>
          <i className={`fa-solid ${icon} text-base`} aria-hidden="true" />
        </div>
      </div>
    </article>
  )
}
```

Accent/icon pairs used: `bg-slate-800` + `bg-slate-100 text-slate-700`; `bg-blue-600` + `bg-blue-50 text-blue-700`;
`bg-violet-600` + `bg-violet-50 text-violet-700`; `bg-teal-600` + `bg-teal-50 text-teal-700`;
`bg-amber-500` + `bg-amber-50 text-amber-700`; `bg-orange-500` + `bg-orange-50 text-orange-700`.

### 5.6 Summary card (top of list pages)

```jsx
function SummaryCard({ label, value, icon, tone, isLoading }) {
  return (
    <article className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="flex items-center justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{label}</p>
          {isLoading ? <div className="mt-2 h-7 w-12 animate-pulse rounded bg-slate-100" /> : <p className="mt-1 text-2xl font-bold text-slate-900">{value}</p>}
        </div>
        <span className={`flex h-10 w-10 items-center justify-center rounded-lg ${tone}`}><i className={`fa-solid ${icon}`} aria-hidden="true" /></span>
      </div>
    </article>
  )
}
```

`tone` is a semantic tone from §2.1 (e.g. `bg-emerald-50 text-emerald-700`). The numbers come from a `summary` object the list API returns (§ API conventions in the tech stack document).

### 5.7 Section card with table

```jsx
<section className="rounded-xl border border-slate-200 bg-white shadow-sm" aria-labelledby="list-heading">
  <div className="border-b border-slate-200 p-4 sm:p-5">
    <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <h2 id="list-heading" className="text-base font-semibold text-slate-900">Employees</h2>
        <p className="mt-0.5 text-xs text-slate-500">{total} matching records</p>
      </div>
      <div className="grid flex-1 grid-cols-1 gap-2 sm:grid-cols-2 xl:max-w-4xl xl:grid-cols-[1.5fr_repeat(3,1fr)_auto]">
        {/* search, filter selects, Clear */}
      </div>
    </div>
  </div>

  <div className="overflow-x-auto">
    <table className="w-full min-w-[940px] text-left text-sm">
      <thead className="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
        <tr><th className="py-3 pl-4 pr-4">Name</th><th className="py-3 pr-4">Status</th><th className="py-3 pr-4 text-right">Actions</th></tr>
      </thead>
      <tbody className="divide-y divide-slate-100">
        <tr className="transition-colors hover:bg-slate-50">
          <td className="py-3.5 pl-4 pr-4">…</td><td className="py-3.5 pr-4">…</td>
          <td className="py-3.5 pr-4"><div className="flex items-center justify-end gap-1">{/* icon buttons */}</div></td>
        </tr>
      </tbody>
    </table>
  </div>
  {isFetching && !isLoading && <div className="border-t border-slate-100 px-5 py-2 text-xs font-medium text-slate-400">Refreshing...</div>}
  <div className="px-4 pb-4"><Pagination … /></div>
</section>
```

Cell content: primary text `font-semibold text-slate-900` with a secondary line `text-xs text-slate-500`; plain columns
`text-slate-600`; dates `text-slate-500`; missing values render as an em dash `—`. Money is right-aligned with `tabular-nums`.

Lists of rich items (courses, trainings) are a vertical stack of expandable white cards (`space-y-4`); tiles such as badges use
`grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3`. Both use the card classes from §2.4.

### 5.8 Modal (verbatim)

```jsx
import { useEffect, useRef } from 'react'

export default function Modal({ isOpen, onClose, title, children }) {
  const closeRef = useRef(null)
  const onCloseRef = useRef(onClose)
  onCloseRef.current = onClose

  useEffect(() => {
    if (!isOpen) return
    const handleKey = (e) => { if (e.key === 'Escape') onCloseRef.current() }
    document.addEventListener('keydown', handleKey)
    closeRef.current?.focus()
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', handleKey)
      document.body.style.overflow = ''
    }
  }, [isOpen])

  if (!isOpen) return null

  return (
    <div className="fixed inset-0 z-50 flex items-start sm:items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" onClick={onClose} role="dialog" aria-modal="true" aria-labelledby="modal-title">
      <div className="bg-white rounded-xl border border-slate-200 shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center justify-between p-5 border-b border-slate-200">
          <h2 id="modal-title" className="text-lg font-semibold text-slate-800">{title}</h2>
          <button ref={closeRef} onClick={onClose} className="w-8 h-8 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors" aria-label="Close modal">
            <i className="fa-solid fa-xmark"></i>
          </button>
        </div>
        <div className="p-5">{children}</div>
      </div>
    </div>
  )
}
```

Create, edit and detail views all open in this modal; the page does not navigate. Titles: "Create course" / "Edit course" / "Employee profile".

### 5.9 Confirm dialog

Built on the modal. Message row: a `w-10 h-10 rounded-full bg-slate-100` circle with a Bootstrap icon
(`bi-exclamation-triangle-fill text-red-500` for danger, `bi-info-circle-fill text-brand-blue` for primary,
`bi-exclamation-triangle-fill text-amber-500` for warning) and `text-sm text-slate-600 pt-2` text. Buttons right-aligned
(`flex justify-end gap-3 pt-2`): Cancel `px-4 py-2 rounded-lg text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200`,
confirm `px-4 py-2 rounded-lg text-sm font-medium` + `bg-red-600 hover:bg-red-700 text-white` (danger) /
`bg-brand-blue hover:bg-blue-700 text-white` (primary) / `bg-amber-500 hover:bg-amber-600 text-white` (warning).
Every delete asks first. Never use `window.confirm`.

### 5.10 Notices, alerts and states

- Success / error notice after an action (top of the page, disappears after 4 seconds):
  `role="status" aria-live="polite"` + `flex items-center gap-2 rounded-lg border p-3 text-sm font-medium` +
  success `border-emerald-200 bg-emerald-50 text-emerald-700` (`fa-circle-check`) or error `border-red-200 bg-red-50 text-red-700` (`fa-circle-exclamation`).
- Page-level load error:
  ```jsx
  <div className="flex flex-col gap-3 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 sm:flex-row sm:items-center sm:justify-between" role="alert">
    <div className="flex items-start gap-3"><i className="fa-solid fa-circle-exclamation mt-0.5" aria-hidden="true" />
      <div><p className="font-semibold">Dashboard data could not be loaded</p><p className="mt-0.5 text-red-700">{message}</p></div></div>
    <button type="button" onClick={refetch} className="inline-flex items-center justify-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100">
      <i className="fa-solid fa-rotate-right" aria-hidden="true" />Try again</button>
  </div>
  ```
- Error inside a list card: centered `p-10 text-center`, `fa-solid fa-triangle-exclamation text-2xl text-red-500`, `mt-3 font-semibold text-slate-900` title,
  `mt-1 text-sm text-slate-500` message, and `mt-4 rounded-lg bg-brand-dark px-4 py-2 text-sm font-semibold text-white` "Try again".
- Empty state in a table: one row spanning all columns, `px-4 py-14 text-center`, a relevant icon `text-2xl text-slate-300`,
  `mt-3 font-semibold text-slate-700` title ("No employees found"), `mt-1 text-sm text-slate-500` hint ("Try adjusting or clearing the current filters.").
- Empty state for a card list: `rounded-xl border border-dashed border-slate-300 bg-white p-12 text-center`, icon `text-3xl text-slate-300`,
  `mt-3 font-semibold text-slate-800` title ("No courses found"), `mt-1 text-sm text-slate-500` hint ("Create one or adjust the current filters.").
- Loading: skeleton rows `h-10 animate-pulse rounded-lg bg-slate-100` (6 rows), skeleton numbers `h-8 w-16 animate-pulse rounded bg-slate-100`.
  Never a blank page; never a spinner where the layout is known. Route-level loader (lazy pages): centered
  `h-8 w-8 animate-spin rounded-full border-2 border-slate-300 border-t-brand-blue` with "Loading…" (`text-sm text-slate-500`) on `bg-brand-gray`.
- Background refresh: keep the previous data on screen and show "Refreshing..." (`text-xs font-medium text-slate-400`).

### 5.11 Pagination

Laravel paginator data drives it (`current_page`, `last_page`, `total`, `from`, `to`). Hidden when `total` is 0.
Wrapper `flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-200`; text
"Showing **from** to **to** of **total** results" (`text-sm text-slate-600`, numbers `font-medium text-slate-900`); prev/next
buttons with `bi bi-chevron-left/right` (`px-3 py-1.5 text-sm font-medium text-slate-600 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed`);
page buttons `min-w-[32px] px-2 py-1.5 text-sm font-medium rounded-lg border`, current `bg-brand-blue text-white border-brand-blue`,
others `text-slate-600 border-slate-200 hover:bg-slate-50`; first, last and current ±2 pages are shown, gaps as `…`.

### 5.12 Tabs

- Page-level tabs (underline): container `flex items-center gap-4 border-b border-slate-200`; tab `pb-2 text-sm font-medium transition-colors`
  + active `border-b-2 border-brand-blue text-brand-blue`, inactive `text-slate-500 hover:text-slate-700`.
- In-card tabs (underline with icon): `-mb-px inline-flex items-center gap-2 border-b-2 px-3 pb-2 text-sm font-semibold transition`
  + active `border-brand-blue text-brand-blue`, inactive `border-transparent text-slate-500 hover:text-slate-800`.
- Segmented tabs (inside modals, e.g. Today / Upcoming / Past): container `flex gap-1 rounded-lg bg-slate-100 p-1` `role="tablist"`;
  tab `flex-1 rounded-md px-3 py-1.5 text-xs font-semibold transition` + active `bg-white text-brand-blue shadow-sm`, inactive `text-slate-600 hover:text-slate-900`;
  count bubble `ml-1.5 rounded-full px-1.5 py-0.5 text-[10px]` (active `bg-blue-50 text-brand-blue`, inactive `bg-slate-200 text-slate-600`).
- Tabs use `role="tab"` and `aria-selected`.

### 5.13 Charts (Chart.js 4 + react-chartjs-2)

```js
import { Chart as ChartJS, ArcElement, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js'
ChartJS.register(ArcElement, BarElement, CategoryScale, LinearScale, Tooltip, Legend)

const PALETTE = ['#1e3a8a', '#2563eb', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', '#06b6d4', '#ec4899']

const pieOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
}

const barOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    x: { ticks: { font: { size: 11 } }, grid: { display: false } },
    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: '#f1f5f9' } },
  },
}
// Pie/doughnut datasets: backgroundColor from PALETTE in order, borderColor '#fff', borderWidth 2.
// Bar datasets: one color, borderRadius 6, maxBarThickness 50.
```

Chart container:

```jsx
function ChartCard({ title, children, height = 280 }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
      <h3 className="mb-4 text-sm font-semibold text-slate-800">{title}</h3>
      <div style={{ height }}>{children}</div>
    </div>
  )
}
// Empty: <div className="flex h-full items-center justify-center text-sm text-slate-400">No data available</div>
```

Charts sit in `grid grid-cols-1 gap-6 lg:grid-cols-2`. A ring/progress gauge is an SVG circle: track `#e2e8f0`, value `#1e3a8a`, stroke width 10, round caps.

### 5.14 Login page

- Page: `min-h-screen flex items-center justify-center bg-brand-dark p-6`.
- Card: `bg-white rounded-2xl shadow-xl w-full max-w-md p-8`.
- Header (centered, `mb-8`): `<img src="/logo.png" className="mx-auto mb-4 h-24 w-auto max-w-full" />`, `h1` "Archon Nell" (`text-2xl font-bold text-slate-800`),
  subtitle = system name (`text-sm text-slate-500 mt-1`).
- Inputs with a leading icon (`fa-regular fa-envelope`, `fa-solid fa-lock`, `absolute left-3 top-1/2 -translate-y-1/2 text-slate-400`):
  `w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-blue/20 focus:border-brand-blue/50 transition-all`;
  labels `block text-sm font-medium text-slate-700 mb-1`; show/hide password eye button on the right.
- Submit: `w-full bg-brand-dark hover:bg-slate-800 text-white py-2.5 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed`,
  label "Sign In" + `fa-arrow-right`; while busy `fa-circle-notch fa-spin`; while locked `fa-lock` and a countdown.
- Error box above the form: `mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-600 text-sm flex items-center gap-2` with `fa-circle-exclamation`.
- Last line of the card: `<p className="text-center text-xs text-slate-500 mt-6">&copy; {year} Archon Nell. All rights reserved.</p>`.
- No "Remember me" and no "Forgot password" link.

### 5.15 Session timeout dialog

After 15 minutes without mouse, keyboard, scroll or touch input, a modal titled "Are you still there?" shows a clock tile
(`h-10 w-10 rounded-full bg-amber-50` + `fa-solid fa-clock text-lg text-amber-500`), the text "You have not done anything for 15 minutes.
For your security you will be signed out in **N seconds**. Do you want to stay signed in?", and two buttons: "Log out"
(`rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-200`) and "Stay signed in"
(`rounded-lg bg-brand-blue px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700`). With no answer in 60 seconds the user is
signed out and the login page shows an amber notice (`mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2`, `fa-clock`):
"You were signed out after 15 minutes without activity." The idle clock is a timestamp in `localStorage` shared by all tabs.

---

## 6. Content and formatting

- Sentence case everywhere except sidebar module names and page titles, which use Title Case ("Learning Management").
- Plain, specific wording: "No employees found", "Could not load employees", "3 matching records".
- Dates: `toLocaleDateString()` for tables; long form `Intl.DateTimeFormat('en', { weekday: 'long', month: 'long', day: 'numeric' })` in the hero.
  Business time zone is `Asia/Manila`; the API stores UTC instants and dates without time as `YYYY-MM-DD`.
- Money (new systems): Philippine peso, `new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })` → `₱1,234.50`; right-aligned, `tabular-nums`.
- Percentages: one decimal at most; "—" when there is no value.
- CSV export: a secondary "Export CSV" button in the page header; the file name includes the date, e.g. `Reports 2026-10-09.csv`.

## 7. Accessibility

- Visible focus on every control (`focus:ring-2` + a tone ring); never remove outlines without a replacement.
- Icon-only buttons have `aria-label` and `title`; decorative icons `aria-hidden="true"`.
- Alerts use `role="alert"`; success notices `role="status" aria-live="polite"`.
- Dialogs: `role="dialog" aria-modal="true" aria-labelledby`, focus moves to the close button, Escape closes.
- Sections use `aria-labelledby` pointing at their `h2`. Tables use real `<table>/<thead>/<th>`.
- Body text is at least `text-xs` (12 px); only counters use `text-[10px]`/`text-[11px]`.

## 8. Responsive behavior

- Breakpoints are Tailwind defaults (`sm` 640, `md` 768, `lg` 1024, `xl` 1280).
- Below `md` the sidebar is off-canvas (hamburger in the topbar); from `md` it is static at `w-64`.
- Tables scroll horizontally inside their card (`overflow-x-auto` + `min-w-[...]`); the page itself never scrolls sideways.
- Headers and toolbars stack vertically on small screens (`flex-col … lg:flex-row`), form grids collapse to one column.
- Modals are full-width with `p-4` margin on phones and `max-w-2xl` from `sm`.

## 9. Do and don't

Do:
- Reuse the exact class strings above; build new screens from §4 and §5.
- Keep each module's labels, status tones and option lists in `src/lib/<module>.js`.
- Show skeletons while loading, keep previous data while refetching, confirm before deleting, disable buttons while saving.

Don't:
- Add other UI kits (MUI, Ant, Chakra, shadcn, Bootstrap CSS) or another icon set, font, or chart library.
- Introduce new brand colors, gradients, glassmorphism, dark mode, or rounded-3xl/pill-shaped buttons.
- Use `alert()`/`confirm()`, toasts that cover content, or full-page spinners for data inside the shell.
