import { useEffect, useState } from 'react'
import Modal from '../ui/Modal'
import { signOut } from '../../lib/api'
import { useAuthStore } from '../../store/authStore'

// After this long without mouse, keyboard or touch input the user is asked whether to stay...
const IDLE_MINUTES = 15
// ...and is signed out if the question is not answered within this long.
const ANSWER_SECONDS = 60

// When the user last did something. Every tab of the app shares it, so working in one tab keeps
// the others signed in, and answering the question in one tab answers it in all of them.
const LAST_ACTIVITY = 'sfa_last_activity'
const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'wheel', 'touchstart', 'scroll']

const markActive = () => localStorage.setItem(LAST_ACTIVITY, String(Date.now()))

// ponytail: the browser keeps the time, so a copied token is not cut off by this. Enforcing the
// limit on the server needs requests that tell user activity from the background polling.
export default function SessionTimeout() {
  // Seconds until sign-out while the question is on screen; null while the user is active.
  const [secondsLeft, setSecondsLeft] = useState(null)

  useEffect(() => {
    // No record means a fresh sign-in (signing out clears it). An old record is kept on purpose:
    // coming back to a browser that sat closed for longer than the limit signs out at once.
    if (!localStorage.getItem(LAST_ACTIVITY)) markActive()

    let asking = false
    let lastMark = 0
    let ended = false

    const onActivity = () => {
      // While the question is showing only its buttons answer it; one write a second is plenty.
      if (asking || Date.now() - lastMark < 1000) return
      lastMark = Date.now()
      markActive()
    }

    // Works from the clock rather than counting ticks: timers slow down in background tabs and
    // stop while the computer sleeps.
    const check = () => {
      if (ended) return

      if (!localStorage.getItem('sfa_token')) {
        // Signed out in another tab.
        ended = true
        useAuthStore.getState().logout()
        return
      }

      const idleSeconds = (Date.now() - Number(localStorage.getItem(LAST_ACTIVITY))) / 1000
      const left = Math.ceil(IDLE_MINUTES * 60 + ANSWER_SECONDS - idleSeconds)

      if (left <= 0) {
        ended = true
        sessionStorage.setItem('sfa_sign_out_reason', `You were signed out after ${IDLE_MINUTES} minutes without activity.`)
        signOut()
        return
      }

      asking = left <= ANSWER_SECONDS
      setSecondsLeft(asking ? left : null)
    }

    check()
    const timer = setInterval(check, 1000)
    const listening = { capture: true, passive: true }
    ACTIVITY_EVENTS.forEach((name) => window.addEventListener(name, onActivity, listening))
    document.addEventListener('visibilitychange', check)

    return () => {
      clearInterval(timer)
      ACTIVITY_EVENTS.forEach((name) => window.removeEventListener(name, onActivity, listening))
      document.removeEventListener('visibilitychange', check)
    }
  }, [])

  const stay = () => {
    markActive()
    setSecondsLeft(null)
  }

  return (
    // Closing the dialog (Escape, the X, a click outside) means the user is here: stay signed in.
    <Modal isOpen={secondsLeft !== null} onClose={stay} title="Are you still there?">
      <div className="flex flex-col gap-4">
        <div className="flex items-start gap-3">
          <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-amber-50">
            <i className="fa-solid fa-clock text-lg text-amber-500" aria-hidden="true"></i>
          </div>
          <p className="pt-1 text-sm text-slate-600">
            You have not done anything for {IDLE_MINUTES} minutes. For your security you will be signed out in{' '}
            <strong className="text-slate-900">{secondsLeft} {secondsLeft === 1 ? 'second' : 'seconds'}</strong>.
            Do you want to stay signed in?
          </p>
        </div>
        <div className="flex justify-end gap-3 pt-2">
          <button
            type="button"
            onClick={signOut}
            className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-200"
          >
            Log out
          </button>
          <button
            type="button"
            onClick={stay}
            className="rounded-lg bg-brand-blue px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700"
          >
            Stay signed in
          </button>
        </div>
      </div>
    </Modal>
  )
}
