export default function Pagination({ currentPage, lastPage, total, from, to, onPageChange }) {
  if (!total || total === 0) return null

  const pages = getPageRange(currentPage, lastPage)

  return (
    <div className="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-200">
      <p className="text-sm text-slate-600">
        Showing <span className="font-medium text-slate-900">{from || 0}</span> to{' '}
        <span className="font-medium text-slate-900">{to || 0}</span> of{' '}
        <span className="font-medium text-slate-900">{total}</span> results
      </p>
      <div className="flex flex-wrap items-center justify-center gap-1">
        <button
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage <= 1}
          aria-label="Previous page"
          className="px-3 py-1.5 text-sm font-medium text-slate-600 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <i className="bi bi-chevron-left" aria-hidden="true"></i>
        </button>
        {pages.map((p, i) =>
          p === '...' ? (
            <span key={`gap-${i}`} className="px-2 text-slate-400">…</span>
          ) : (
            <button
              key={p}
              onClick={() => onPageChange(p)}
              aria-current={p === currentPage ? 'page' : undefined}
              aria-label={`Page ${p}`}
              className={`min-w-[32px] px-2 py-1.5 text-sm font-medium rounded-lg border transition-colors ${
                p === currentPage
                  ? 'bg-brand-blue text-white border-brand-blue'
                  : 'text-slate-600 border-slate-200 hover:bg-slate-50'
              }`}
            >
              {p}
            </button>
          ),
        )}
        <button
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage >= lastPage}
          aria-label="Next page"
          className="px-3 py-1.5 text-sm font-medium text-slate-600 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        >
          <i className="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
      </div>
    </div>
  )
}

function getPageRange(current, last) {
  const delta = 2
  const range = []
  const rangeWithDots = []

  for (let i = Math.max(2, current - delta); i <= Math.min(last - 1, current + delta); i++) {
    range.push(i)
  }

  if (current - delta > 2) {
    rangeWithDots.push(1, '...')
  } else {
    rangeWithDots.push(1)
  }

  rangeWithDots.push(...range)

  if (current + delta < last - 1) {
    rangeWithDots.push('...', last)
  } else if (last > 1) {
    rangeWithDots.push(last)
  }

  return rangeWithDots
}
