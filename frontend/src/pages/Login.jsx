import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'
import { api } from '../lib/api'

const inputClass = 'w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-blue/20 focus:border-brand-blue/50 transition-all'

// "4:59" under an hour, "23 h 59 min" above it.
// Only a 4xx answer is SFA's verdict on the sign-in itself. No answer, or a 5xx such as the 502 the local dev proxy returns
// while the API is not running, means SFA was not reached: calling that a wrong password sends people the wrong way.
function serverDown(err) {
  const status = err.response?.status
  if (status && status < 500) return ''
  return `SFA's server could not be reached${status ? ` (HTTP ${status})` : ''}. Your email and password were not checked. Try again in a moment.`
}

function formatWait(seconds) {
  if (seconds >= 3600) return `${Math.floor(seconds / 3600)} h ${Math.floor((seconds % 3600) / 60)} min`
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
}

export default function Login() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState('')
  // Set once the password is accepted for an account that also needs the emailed code:
  // { challenge, sentTo }. The challenge is not an access token; only the code completes the sign-in.
  const [otp, setOtp] = useState(null)
  const [code, setCode] = useState('')
  const [notice, setNotice] = useState('')
  // Seconds until another code may be requested.
  const [resendIn, setResendIn] = useState(0)
  // Seconds until sign-in is unlocked again after too many failed attempts.
  const [lockedFor, setLockedFor] = useState(0)
  // Why the last session ended, when the app ended it (SessionTimeout). Shown once.
  const [signedOutBecause] = useState(() => sessionStorage.getItem('sfa_sign_out_reason'))
  const navigate = useNavigate()
  const { login } = useAuthStore()

  useEffect(() => sessionStorage.removeItem('sfa_sign_out_reason'), [])

  useEffect(() => {
    if (resendIn <= 0) return undefined
    const timer = setTimeout(() => setResendIn(resendIn - 1), 1000)
    return () => clearTimeout(timer)
  }, [resendIn])

  useEffect(() => {
    if (lockedFor <= 0) return undefined
    const timer = setTimeout(() => setLockedFor(lockedFor - 1), 1000)
    return () => clearTimeout(timer)
  }, [lockedFor])

  // The server answers a locked sign-in with how long it lasts.
  const noteLock = (err) => {
    const seconds = Number(err.response?.data?.retry_after) || 0
    if (seconds > 0) setLockedFor(seconds)
    return seconds > 0
  }

  const finish = ({ user, token }) => {
    login(user, token, user.roles || [], user.permissions || [])
    navigate('/dashboard', { replace: true })
  }

  const backToPassword = (message = '') => {
    setOtp(null)
    setCode('')
    setNotice('')
    setPassword('')
    setError(message)
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setError('')

    if (!email || !password) {
      setError('Please enter both email and password.')
      return
    }

    setIsLoading(true)

    try {
      const { data } = await api.post('/login', { email, password })

      if (data.otp_required) {
        setOtp({ challenge: data.challenge, sentTo: data.sent_to })
        setCode('')
        setNotice('')
        setResendIn(60)
      } else {
        finish(data)
      }
    } catch (err) {
      noteLock(err)
      setError(serverDown(err) || err.response.data?.message || 'Invalid email or password.')
    } finally {
      setIsLoading(false)
    }
  }

  const handleVerify = async (e) => {
    e.preventDefault()
    setError('')
    setNotice('')
    setIsLoading(true)

    try {
      const { data } = await api.post('/login/otp', { challenge: otp.challenge, code })
      finish(data)
    } catch (err) {
      const message = serverDown(err) || err.response?.data?.message || 'The code could not be checked. Please try again.'
      // 410: the sign-in expired or ran out of tries, so it starts over from the password.
      // A lock ends it too.
      if (err.response?.status === 410 || noteLock(err)) backToPassword(message)
      else {
        setError(message)
        setCode('')
      }
    } finally {
      setIsLoading(false)
    }
  }

  const handleResend = async () => {
    setError('')
    setNotice('')

    try {
      const { data } = await api.post('/login/otp/resend', { challenge: otp.challenge })
      setOtp({ ...otp, sentTo: data.sent_to })
      setNotice('A new code is on its way. The earlier one no longer works.')
      setResendIn(60)
    } catch (err) {
      const message = serverDown(err) || err.response?.data?.message || 'Another code could not be sent. Please try again.'
      if (err.response?.status === 410) backToPassword(message)
      else setError(message)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center bg-brand-dark p-6">
      <div className="bg-white rounded-2xl shadow-xl w-full max-w-md p-8">
        <div className="text-center mb-8">
          <img src="/logo.png" alt="Archon Nell Incorporated" className="mx-auto mb-4 h-24 w-auto max-w-full" />
          <h1 className="sr-only">Archon Nell</h1>
          <p className="text-sm text-slate-500 mt-1">
            Support, Finance & Administration
          </p>
        </div>

        {signedOutBecause && !error && !otp && (
          <div role="status" className="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm flex items-center gap-2">
            <i className="fa-solid fa-clock" aria-hidden="true"></i>
            {signedOutBecause}
          </div>
        )}

        {error && (
          <div role="alert" className="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-red-600 text-sm flex items-center gap-2">
            <i className="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            {error}
          </div>
        )}

        {otp ? (
          <form onSubmit={handleVerify} className="space-y-5">
            <div className="rounded-lg border border-blue-100 bg-blue-50 p-3 text-sm text-slate-700">
              <p className="font-semibold text-slate-900"><i className="fa-solid fa-shield-halved mr-2 text-brand-blue" aria-hidden="true"></i>Check your email</p>
              <p className="mt-1">We sent a 6-digit code to <strong>{otp.sentTo}</strong>. Enter it to finish signing in. It expires in 10 minutes.</p>
            </div>

            {notice && <p role="status" className="text-sm text-emerald-700">{notice}</p>}

            <div>
              <label htmlFor="login-code" className="block text-sm font-medium text-slate-700 mb-1">
                Sign-in code
              </label>
              <div className="relative">
                <i className="fa-solid fa-key absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                <input
                  id="login-code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  autoFocus
                  maxLength={6}
                  value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  placeholder="000000"
                  className={`${inputClass} text-center text-lg font-semibold tracking-[0.5em]`}
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={isLoading || code.length !== 6}
              className="w-full bg-brand-dark hover:bg-slate-800 text-white py-2.5 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
            >
              {isLoading ? (
                <>
                  <i className="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>
                  Checking...
                </>
              ) : code.length !== 6 ? (
                'Enter the 6-digit code'
              ) : (
                <>
                  Verify and sign in
                  <i className="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </>
              )}
            </button>

            <div className="flex items-center justify-between text-sm">
              <button type="button" onClick={() => backToPassword()} className="text-slate-600 hover:underline">
                <i className="fa-solid fa-arrow-left mr-1.5" aria-hidden="true"></i>Use another account
              </button>
              <button type="button" onClick={handleResend} disabled={resendIn > 0} className="text-brand-blue hover:underline font-medium disabled:text-slate-400 disabled:no-underline disabled:cursor-not-allowed">
                {resendIn > 0 ? `Send a new code in ${resendIn}s` : 'Send a new code'}
              </button>
            </div>
          </form>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <label htmlFor="login-email" className="block text-sm font-medium text-slate-700 mb-1">
                Email
              </label>
              <div className="relative">
                <i className="fa-regular fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                <input
                  id="login-email"
                  type="email"
                  autoComplete="username"
                  value={email}
                  // The lock belongs to the email that was typed; another account may still sign in.
                  onChange={(e) => { setEmail(e.target.value); setLockedFor(0) }}
                  placeholder="you@company.com"
                  className={inputClass}
                />
              </div>
            </div>

            <div>
              <label htmlFor="login-password" className="block text-sm font-medium text-slate-700 mb-1">
                Password
              </label>
              <div className="relative">
                <i className="fa-solid fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                <input
                  id="login-password"
                  type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="Enter your password"
                  className={`${inputClass} pr-10`}
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                >
                  <i
                    className={`fa-regular ${
                      showPassword ? 'fa-eye-slash' : 'fa-eye'
                    }`}
                    aria-hidden="true"
                  ></i>
                </button>
              </div>
            </div>

            <button
              type="submit"
              disabled={isLoading || lockedFor > 0}
              className="w-full bg-brand-dark hover:bg-slate-800 text-white py-2.5 rounded-lg text-sm font-medium transition-colors flex items-center justify-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed"
            >
              {lockedFor > 0 ? (
                <>
                  <i className="fa-solid fa-lock" aria-hidden="true"></i>
                  Locked. Try again in {formatWait(lockedFor)}
                </>
              ) : isLoading ? (
                <>
                  <i className="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i>
                  Signing in...
                </>
              ) : (
                <>
                  Sign In
                  <i className="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </>
              )}
            </button>
          </form>
        )}

        <p className="text-center text-xs text-slate-500 mt-6">
          &copy; {new Date().getFullYear()} Archon Nell. All rights reserved.
        </p>
      </div>
    </div>
  )
}
