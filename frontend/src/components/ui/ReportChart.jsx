import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, ArcElement, Tooltip, Legend } from 'chart.js'
import { Bar, Doughnut } from 'react-chartjs-2'
ChartJS.register(CategoryScale, LinearScale, BarElement, ArcElement, Tooltip, Legend)
const colors = ['#1e3a8a', '#ea580c', '#16a34a', '#9333ea', '#0284c7', '#f59e0b', '#dc2626', '#475569', '#0891b2', '#4f46e5']
export default function ReportChart({ report }) {
  if (!report?.data?.some((row) => row.value !== 0)) return <div className="flex h-64 flex-col items-center justify-center gap-2 text-sm text-slate-500"><i className="fa-solid fa-chart-simple text-3xl text-slate-300" aria-hidden="true" /><p>No activity in this period</p></div>
  const money = report.unit === 'money'
  const data = { labels: report.data.map((row) => row.name.replaceAll('_', ' ')), datasets: [{ label: report.title, data: report.data.map((row) => money ? row.value / 100 : row.value), backgroundColor: report.type === 'pie' ? colors : '#1e3a8a', borderRadius: report.type === 'pie' ? 0 : 4, borderWidth: 0 }] }
  const format = (value) => money ? new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value) : `${value}${report.unit === 'percent' ? '%' : ''}`
  const options = { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: report.type === 'pie', position: 'bottom', labels: { boxWidth: 10, font: { family: 'Inter', size: 11 } } }, tooltip: { callbacks: { label: (context) => `${context.dataset.label}: ${format(context.parsed?.y ?? context.parsed)}` } } }, ...(report.type !== 'pie' ? { scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { callback: format, font: { family: 'Inter', size: 10 } } }, x: { grid: { display: false }, ticks: { maxRotation: 35, font: { family: 'Inter', size: 10 } } } } } : {}) }
  return <div className="h-72" role="img" aria-label={`${report.title}. ${report.data.map((row) => `${row.name}: ${format(money ? row.value / 100 : row.value)}`).join('; ')}`}><div className="h-full" aria-hidden="true">{report.type === 'pie' ? <Doughnut data={data} options={options} /> : <Bar data={data} options={options} />}</div></div>
}
