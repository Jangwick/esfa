const pad = (number) => String(number).padStart(2, '0')

// Today as YYYY-MM-DD on the user's own clock. (toISOString gives the UTC date, which in Manila
// is still yesterday until 8 in the morning.)
export function todayDate() {
  const now = new Date()
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

// The earliest value a date field for something still to come accepts (its `min`): today, or the
// value the record was opened with when that is already past, so an old record can still be saved
// without moving its date. Works for date (YYYY-MM-DD) and datetime-local (YYYY-MM-DDTHH:MM) values.
export function earliestDate(saved, today = todayDate()) {
  return saved && saved < today ? saved : today
}

// The later of two values of the same format; an empty one never wins.
export function laterDate(a, b) {
  return (a || '') > (b || '') ? a : b
}
