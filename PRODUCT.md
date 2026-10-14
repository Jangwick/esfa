# Archon Nell — Support, Finance & Administration

Staff back office for the Archon Nell e-commerce marketplace cluster. Customers, shops, checkout, catalog and storefront are sibling systems and connect through scoped integrations.

The authoritative feature specification is `docs/SPECIFICATION.md`, supplied by the user from PDMS. Its delivery sequence is seven phases, with a checkpoint after each. This delivery implements Phase 1. Operational support, money, shipping, billing, analytics and the full external integration contract belong to Phases 2–7.

The audience is Admin, Finance, Support Agent, Logistics Coordinator and Analyst. The interface operates a business system: clear hierarchy, consistent records, visible validation, accessible controls and responsive layouts matter more than visual novelty.

Visual authority: `docs/DESIGN.md` and PDMS source. Copy the original logo assets, Inter, Tailwind 3 colors, layout and component classes. JavaScript + JSX, React 19, Vite 8, React Query, Zustand, Axios and the existing icon sets. Backend: Laravel 12, Sanctum and Spatie permissions. No alternate UI kit, font, chart library or color system.

Current success: staff can sign in, manage authorized administrative settings, inspect audit history, receive notifications, create scoped integration credentials and manage signed webhook delivery infrastructure. Every write is validated and checked by the server.
