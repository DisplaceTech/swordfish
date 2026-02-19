import { NavLink, Outlet } from 'react-router-dom'

const navLinkClass = ({ isActive }) =>
  isActive
    ? 'text-white font-semibold border-b-2 border-white pb-0.5'
    : 'text-gray-400 hover:text-gray-200 transition-colors'

export default function Layout() {
  return (
    <div className="min-h-screen bg-gray-950 text-gray-100">
      <header className="border-b border-gray-800">
        <nav className="max-w-3xl mx-auto px-4 py-4 flex items-center gap-8">
          <span className="text-xl font-bold tracking-tight mr-auto">Swordfish</span>
          <NavLink to="/" end className={navLinkClass}>Create</NavLink>
          <NavLink to="/secret" className={navLinkClass}>Retrieve</NavLink>
          <NavLink to="/about" className={navLinkClass}>About</NavLink>
        </nav>
      </header>
      <main className="max-w-3xl mx-auto px-4 py-10">
        <Outlet />
      </main>
    </div>
  )
}
