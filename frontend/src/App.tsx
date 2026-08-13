import { BrowserRouter as Router, Routes, Route, Link } from 'react-router-dom';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer,
  LineChart, Line
} from 'recharts';
import {
  Globe, Beer, Coins, Map as MapIcon, FileText, LayoutDashboard, Activity
} from 'lucide-react';

const mockCountryData = [
  { name: 'Brasil', population: 212 },
  { name: 'EUA', population: 331 },
  { name: 'China', population: 1441 },
  { name: 'Índia', population: 1393 },
  { name: 'Rússia', population: 145 },
];

const mockCryptoData = [
  { name: 'Jan', value: 40000 },
  { name: 'Fev', value: 45000 },
  { name: 'Mar', value: 42000 },
  { name: 'Abr', value: 50000 },
  { name: 'Mai', value: 48000 },
  { name: 'Jun', value: 60000 },
];

const Sidebar = () => (
  <div className="w-64 bg-gray-900 text-white min-h-screen p-4 shadow-xl">
    <div className="flex items-center gap-3 mb-8 px-2">
      <Activity className="text-blue-500 w-8 h-8" />
      <h1 className="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-emerald-400">
        DataMaster API
      </h1>
    </div>

    <nav className="space-y-2">
      <Link to="/" className="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition-colors bg-gray-800 border-l-4 border-blue-500">
        <LayoutDashboard className="w-5 h-5 text-blue-400" />
        <span className="font-medium">Dashboard Geral</span>
      </Link>
      <Link to="/countries" className="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition-colors text-gray-300">
        <Globe className="w-5 h-5 text-emerald-400" />
        <span>REST Countries</span>
      </Link>
      <Link to="/breweries" className="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition-colors text-gray-300">
        <Beer className="w-5 h-5 text-amber-400" />
        <span>Open Brewery DB</span>
      </Link>
      <Link to="/crypto" className="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition-colors text-gray-300">
        <Coins className="w-5 h-5 text-yellow-500" />
        <span>CoinGecko</span>
      </Link>
      <Link to="/ibge" className="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition-colors text-gray-300">
        <MapIcon className="w-5 h-5 text-green-500" />
        <span>IBGE Localidades</span>
      </Link>
      <Link to="/pncp" className="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition-colors text-gray-300">
        <FileText className="w-5 h-5 text-purple-400" />
        <span>PNCP Licitações</span>
      </Link>
    </nav>
  </div>
);

const Dashboard = () => (
  <div className="p-8 w-full max-w-7xl mx-auto">
    <header className="mb-8">
      <h2 className="text-3xl font-bold text-gray-800">Visão Geral dos Dados</h2>
      <p className="text-gray-500 mt-2">Métricas agregadas das principais APIs públicas acompanhadas.</p>
    </header>

    {/* KPI Cards */}
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div className="p-4 bg-emerald-100 rounded-xl text-emerald-600">
          <Globe className="w-6 h-6" />
        </div>
        <div>
          <p className="text-sm font-medium text-gray-500">Países Monitorados</p>
          <p className="text-2xl font-bold text-gray-900">250</p>
        </div>
      </div>

      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div className="p-4 bg-amber-100 rounded-xl text-amber-600">
          <Beer className="w-6 h-6" />
        </div>
        <div>
          <p className="text-sm font-medium text-gray-500">Cervejarias Catalogadas</p>
          <p className="text-2xl font-bold text-gray-900">8,241</p>
        </div>
      </div>

      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div className="p-4 bg-yellow-100 rounded-xl text-yellow-600">
          <Coins className="w-6 h-6" />
        </div>
        <div>
          <p className="text-sm font-medium text-gray-500">Cotação Bitcoin (USD)</p>
          <p className="text-2xl font-bold text-gray-900">$60,230</p>
        </div>
      </div>

      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center gap-4 hover:shadow-md transition-shadow">
        <div className="p-4 bg-purple-100 rounded-xl text-purple-600">
          <FileText className="w-6 h-6" />
        </div>
        <div>
          <p className="text-sm font-medium text-gray-500">Contratos PNCP Hoje</p>
          <p className="text-2xl font-bold text-gray-900">142</p>
        </div>
      </div>
    </div>

    {/* Charts */}
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <h3 className="text-lg font-semibold text-gray-800 mb-6">Top Países por População (Milhões)</h3>
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={mockCountryData}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e5e7eb" />
              <XAxis dataKey="name" axisLine={false} tickLine={false} />
              <YAxis axisLine={false} tickLine={false} />
              <Tooltip cursor={{ fill: '#f3f4f6' }} contentStyle={{ borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }} />
              <Bar dataKey="population" fill="#10b981" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </div>

      <div className="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <h3 className="text-lg font-semibold text-gray-800 mb-6">Histórico Bitcoin (6 Meses)</h3>
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={mockCryptoData}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e5e7eb" />
              <XAxis dataKey="name" axisLine={false} tickLine={false} />
              <YAxis axisLine={false} tickLine={false} />
              <Tooltip contentStyle={{ borderRadius: '8px', border: 'none', boxShadow: '0 4px 6px -1px rgb(0 0 0 / 0.1)' }} />
              <Line type="monotone" dataKey="value" stroke="#3b82f6" strokeWidth={3} dot={{ r: 4, strokeWidth: 2 }} activeDot={{ r: 6 }} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  </div>
);

function App() {
  return (
    <Router>
      <div className="flex min-h-screen bg-gray-50">
        <Sidebar />
        <main className="flex-1 overflow-y-auto">
          <Routes>
            <Route path="/" element={<Dashboard />} />
            {/* Outras rotas serão implementadas aqui */}
          </Routes>
        </main>
      </div>
    </Router>
  );
}

export default App;
