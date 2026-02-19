import { createBrowserRouter, RouterProvider } from 'react-router-dom'
import Layout from './components/Layout.jsx'
import CreatePage from './pages/CreatePage.jsx'
import RetrievePage from './pages/RetrievePage.jsx'
import AboutPage from './pages/AboutPage.jsx'

const router = createBrowserRouter([
  {
    path: '/',
    element: <Layout />,
    children: [
      { index: true, element: <CreatePage /> },
      { path: 'secret/:secretId?', element: <RetrievePage /> },
      { path: 'about', element: <AboutPage /> },
    ],
  },
])

export default function App() {
  return <RouterProvider router={router} />
}
