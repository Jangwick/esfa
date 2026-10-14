import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    // SFA_API_PROXY lets the dev server reach an API on another port when 8000 is taken.
    proxy: { '/api': { target: process.env.SFA_API_PROXY || 'http://127.0.0.1:8000', changeOrigin: true } },
  },
  preview: { host: '127.0.0.1', port: 4173 },
})
