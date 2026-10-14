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
