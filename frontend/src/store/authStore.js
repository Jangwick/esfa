import { create } from 'zustand'

function readStored(key, fallback) {
  try { return JSON.parse(localStorage.getItem(`sfa_${key}`)) ?? fallback }
  catch { localStorage.removeItem(`sfa_${key}`); return fallback }
}

const initialUser = readStored('user', null)
const initialToken = localStorage.getItem('sfa_token')
const names = (items = []) => items.map((item) => typeof item === 'string' ? item : item.name).filter(Boolean)

export const useAuthStore = create((set, get) => ({
  token: initialToken,
  user: initialUser,
  roles: names(initialUser?.roles ?? readStored('roles', [])),
  permissions: names(initialUser?.permissions ?? readStored('permissions', [])),
  isAuthenticated: Boolean(initialToken),
  login: (user, token, roles = [], permissions = []) => {
    const effectiveRoles = names(roles.length ? roles : user.roles)
    const effectivePermissions = names(permissions.length ? permissions : user.permissions)
    localStorage.setItem('sfa_token', token)
    localStorage.setItem('sfa_user', JSON.stringify(user))
    localStorage.setItem('sfa_roles', JSON.stringify(effectiveRoles))
    localStorage.setItem('sfa_permissions', JSON.stringify(effectivePermissions))
    localStorage.setItem('sfa_last_activity', String(Date.now()))
    set({ user, token, roles: effectiveRoles, permissions: effectivePermissions, isAuthenticated: true })
  },
  setUser: (incoming) => {
    // PUT /profile and the Users form return no ai_features; keep the session's until GET /user sends them.
    const user = { ai_features: get().user?.ai_features, ...incoming }
    const roles = names(user.roles)
    const permissions = names(user.permissions)
    localStorage.setItem('sfa_user', JSON.stringify(user))
    localStorage.setItem('sfa_roles', JSON.stringify(roles))
    localStorage.setItem('sfa_permissions', JSON.stringify(permissions))
    set({ user, roles, permissions })
  },
  logout: () => {
    for (const key of ['token', 'user', 'roles', 'permissions', 'last_activity']) localStorage.removeItem(`sfa_${key}`)
    set({ user: null, token: null, roles: [], permissions: [], isAuthenticated: false })
  },
  hasRole: (role) => get().roles.includes(role),
  hasPermission: (permission) => get().roles.includes('Admin') || get().permissions.includes(permission),
}))
