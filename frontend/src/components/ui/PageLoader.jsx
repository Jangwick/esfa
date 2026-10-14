/**
 * Minimal full-page loader shown during lazy route transitions.
 * The sidebar and topbar are not rendered here because the lazy
 * page component itself wraps content in MainLayout. This keeps
 * the transition lightweight and avoids a flash of empty chrome.
 */
export default function PageLoader() {
  return (
    <div className="flex h-screen items-center justify-center bg-brand-gray">
      <div className="flex flex-col items-center gap-3">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-slate-300 border-t-brand-blue" />
        <p className="text-sm text-slate-500">Loading…</p>
      </div>
    </div>
  )
}
