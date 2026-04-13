import { StrictMode, useState, useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { motion } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import {
  LineChart,
  Line,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts'
import '@/index.css'

interface RevenueData {
  date: string
  date_label: string
  gross_sales: number
  net_revenue: number
  orders_count: number
  items_sold: number
  coupons: number
  refunds: number
  taxes: number
  shipping: number
}

interface RevenueTotals {
  total_sales: number
  net_revenue: number
  gross_sales: number
  orders_count: number
  items_sold: number
  coupons: number
  refunds: number
  taxes: number
  shipping: number
}

interface AnalyticsData {
  intervals: RevenueData[]
  totals: RevenueTotals
}

interface OrderItem {
  id: number
  date: string
  date_time: string
  status: string
  status_name: string
  customer: string
  products: string
  products_count: number
  items_sold: number
  net_sales: number
  total: number
}

interface OrdersTotals {
  orders_count: number
  net_sales: number
  avg_order_value: number
  avg_items_per_order: number
  total_items: number
}

interface OrdersAnalyticsData {
  orders: OrderItem[]
  totals: OrdersTotals
  intervals: { date: string; date_label: string; orders_count: number; net_sales: number }[]
}

interface ProductItem {
  id: number
  name: string
  sku: string
  items_sold: number
  net_revenue: number
  orders_count: number
}

interface ProductsTotals {
  items_sold: number
  net_revenue: number
  orders_count: number
  products_count: number
}

interface ProductsAnalyticsData {
  products: ProductItem[]
  totals: ProductsTotals
}

interface BackorderItem {
  item_id: number
  order_id: number
  dealer_name: string
  part_number: string
  product_name: string
  quantity: number
  status: string
  order_date: string
}

interface BackordersTotals {
  total_items: number
  total_qty: number
  pending_count: number
  fulfilled_count: number
}

interface BackordersAnalyticsData {
  backorders: BackorderItem[]
  totals: BackordersTotals
}

declare global {
  interface Window {
    zeekrAnalytics: {
      ajaxUrl: string
      nonce: string
    }
  }
}

type DateRange = 'today' | '7days' | '30days' | '90days' | 'year' | 'total'
type IntervalType = 'day' | 'week' | 'month'
type TabType = 'revenue' | 'orders' | 'products' | 'backorders' | null

const TABS: { id: TabType; label: string }[] = [
  { id: 'revenue', label: 'Revenue' },
  { id: 'orders', label: 'Orders' },
  { id: 'products', label: 'Products' },
  { id: 'backorders', label: 'Back Orders' },
]

function ZeekrAnalyticsPage() {
  const config = window.zeekrAnalytics || {
    ajaxUrl: '',
    nonce: '',
  }

  const [activeTab, setActiveTab] = useState<TabType>(null)
  const [loading, setLoading] = useState(false)
  const [data, setData] = useState<AnalyticsData | null>(null)
  const [ordersData, setOrdersData] = useState<OrdersAnalyticsData | null>(null)
  const [productsData, setProductsData] = useState<ProductsAnalyticsData | null>(null)
  const [backordersData, setBackordersData] = useState<BackordersAnalyticsData | null>(null)
  const [dateRange, setDateRange] = useState<DateRange>('30days')
  const [interval, setInterval] = useState<IntervalType>('day')
  const [backorderFilter, setBackorderFilter] = useState<'all' | 'pending' | 'fulfilled'>('all')

  const getDateParams = () => {
    const now = new Date()
    let after: Date
    let intervalType: IntervalType = 'day'

    switch (dateRange) {
      case 'today':
        after = new Date(now.getFullYear(), now.getMonth(), now.getDate())
        intervalType = 'day'
        break
      case '7days':
        after = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000)
        intervalType = 'day'
        break
      case '30days':
        after = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)
        intervalType = 'day'
        break
      case '90days':
        after = new Date(now.getTime() - 90 * 24 * 60 * 60 * 1000)
        intervalType = 'week'
        break
      case 'year':
        after = new Date(now.getFullYear(), 0, 1)
        intervalType = 'month'
        break
      case 'total':
        after = new Date(2020, 0, 1) // Start from 2020 for all-time data
        intervalType = 'month'
        break
      default:
        after = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)
        intervalType = 'day'
    }

    setInterval(intervalType)

    return {
      before: now.toISOString(),
      after: after.toISOString(),
      interval: intervalType,
    }
  }

  const fetchAnalytics = async () => {
    setLoading(true)
    try {
      const params = getDateParams()

      const formData = new FormData()
      formData.append('action', 'zeekr_get_analytics')
      formData.append('nonce', config.nonce)
      formData.append('before', params.before)
      formData.append('after', params.after)
      formData.append('interval', params.interval)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setData(result.data)
      }
    } catch (error) {
      console.error('Failed to fetch analytics:', error)
    } finally {
      setLoading(false)
    }
  }

  const fetchOrdersAnalytics = async () => {
    setLoading(true)
    try {
      const params = getDateParams()

      const formData = new FormData()
      formData.append('action', 'zeekr_get_orders_analytics')
      formData.append('nonce', config.nonce)
      formData.append('before', params.before)
      formData.append('after', params.after)
      formData.append('interval', params.interval)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setOrdersData(result.data)
      }
    } catch (error) {
      console.error('Failed to fetch orders analytics:', error)
    } finally {
      setLoading(false)
    }
  }

  const fetchProductsAnalytics = async () => {
    setLoading(true)
    try {
      const params = getDateParams()

      const formData = new FormData()
      formData.append('action', 'zeekr_get_products_analytics')
      formData.append('nonce', config.nonce)
      formData.append('before', params.before)
      formData.append('after', params.after)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setProductsData(result.data)
      }
    } catch (error) {
      console.error('Failed to fetch products analytics:', error)
    } finally {
      setLoading(false)
    }
  }

  const fetchBackordersAnalytics = async () => {
    setLoading(true)
    try {
      const params = getDateParams()

      const formData = new FormData()
      formData.append('action', 'zeekr_get_backorders_analytics')
      formData.append('nonce', config.nonce)
      formData.append('before', params.before)
      formData.append('after', params.after)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setBackordersData(result.data)
      }
    } catch (error) {
      console.error('Failed to fetch backorders analytics:', error)
    } finally {
      setLoading(false)
    }
  }

  const updateBackorderStatus = async (orderId: number, itemId: number, status: string) => {
    try {
      const formData = new FormData()
      formData.append('action', 'zeekr_update_backorder_status')
      formData.append('nonce', config.nonce)
      formData.append('order_id', orderId.toString())
      formData.append('item_id', itemId.toString())
      formData.append('status', status)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        // Refresh the backorders data
        fetchBackordersAnalytics()
      } else {
        console.error('Failed to update backorder status:', result.data?.message)
      }
    } catch (error) {
      console.error('Failed to update backorder status:', error)
    }
  }

  useEffect(() => {
    if (!activeTab) return // Don't fetch until user selects a tab

    if (activeTab === 'revenue') {
      fetchAnalytics()
    } else if (activeTab === 'orders') {
      fetchOrdersAnalytics()
    } else if (activeTab === 'products') {
      fetchProductsAnalytics()
    } else if (activeTab === 'backorders') {
      fetchBackordersAnalytics()
    }
  }, [dateRange, activeTab])

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-AU', {
      style: 'currency',
      currency: 'AUD',
      minimumFractionDigits: 2,
    }).format(value)
  }

  const formatNumber = (value: number) => {
    return new Intl.NumberFormat('en-AU').format(value)
  }

  const downloadCSV = () => {
    if (!data) return

    const headers = ['Date', 'Orders', 'Gross Sales', 'Taxes', 'Total Sales']
    const rows = data.intervals.map((item) => [
      item.date_label,
      item.orders_count,
      item.gross_sales.toFixed(2),
      item.taxes.toFixed(2),
      (item.gross_sales + item.taxes).toFixed(2),
    ])

    // Add totals row
    rows.push([
      'Total',
      data.totals.orders_count,
      data.totals.gross_sales.toFixed(2),
      data.totals.taxes.toFixed(2),
      (data.totals.gross_sales + data.totals.taxes).toFixed(2),
    ])

    const csvContent = [
      headers.join(','),
      ...rows.map((row) => row.join(',')),
    ].join('\n')

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `revenue-report-${dateRange}.csv`
    link.click()
  }

  const downloadOrdersCSV = () => {
    if (!ordersData) return

    const headers = ['Date', 'Order #', 'Status', 'Dealer', 'Products', 'Items Sold', 'Net Sales']
    const rows = ordersData.orders.map((order) => [
      order.date,
      order.id,
      order.status_name,
      `"${order.customer}"`,
      `"${order.products}"`,
      order.items_sold,
      order.net_sales.toFixed(2),
    ])

    const csvContent = [
      headers.join(','),
      ...rows.map((row) => row.join(',')),
    ].join('\n')

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `orders-report-${dateRange}.csv`
    link.click()
  }

  const downloadProductsCSV = () => {
    if (!productsData) return

    const headers = ['Product', 'Part Number', 'Items Sold', 'Net Revenue', 'Orders']
    const rows = productsData.products.map((product) => [
      `"${product.name}"`,
      product.sku,
      product.items_sold,
      product.net_revenue.toFixed(2),
      product.orders_count,
    ])

    const csvContent = [
      headers.join(','),
      ...rows.map((row) => row.join(',')),
    ].join('\n')

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `products-report-${dateRange}.csv`
    link.click()
  }

  const downloadBackordersCSV = () => {
    if (!backordersData) return

    const headers = ['Dealer Name', 'Invoice #', 'Part Number', 'Product', 'Qty', 'Status']
    const rows = backordersData.backorders.map((item) => [
      `"${item.dealer_name}"`,
      item.order_id,
      item.part_number,
      `"${item.product_name}"`,
      item.quantity,
      item.status === 'fulfilled' ? 'Fulfilled' : 'Pending',
    ])

    const csvContent = [
      headers.join(','),
      ...rows.map((row) => row.join(',')),
    ].join('\n')

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = `backorders-report.csv`
    link.click()
  }

  const STATUS_COLORS: Record<string, { bg: string; color: string }> = {
    pending: { bg: '#f3f4f6', color: '#6b7280' },
    sent: { bg: '#dbeafe', color: '#2563eb' },
    received: { bg: '#e0e7ff', color: '#4f46e5' },
    processing: { bg: '#fef9c3', color: '#ca8a04' },
    completed: { bg: '#dcfce7', color: '#16a34a' },
    cancelled: { bg: '#fee2e2', color: '#dc2626' },
    failed: { bg: '#fee2e2', color: '#dc2626' },
  }

  const StatCard = ({
    title,
    value,
    isCurrency = true,
  }: {
    title: string
    value: number
    isCurrency?: boolean
  }) => (
    <motion.div
      style={{
        background: 'white',
        borderRadius: '12px',
        padding: '12px 24px',
        boxShadow: '0 1px 3px rgba(0,0,0,0.1)',
        border: '1px solid #f3f4f6',
        textAlign: 'center',
      }}
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
    >
      <p style={{ fontSize: '14px', color: '#6b7280', margin: '0 0 4px 0' }}>{title}</p>
      <p style={{ fontSize: '24px', fontWeight: 'bold', color: '#111827', margin: 0 }}>
        {isCurrency ? formatCurrency(value) : formatNumber(value)}
      </p>
    </motion.div>
  )

  return (
    <div className="page-container">
      <div
        style={{
          width: '100%',
          maxWidth: '95vw',
          margin: '0 auto',
          paddingTop: '120px',
          paddingBottom: '80px',
          paddingLeft: '16px',
          paddingRight: '16px',
        }}
      >
        {/* Header */}
        <motion.div
          style={{ marginBottom: '32px', textAlign: 'center' }}
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-4xl font-bold mb-4">
            <GradientText animationSpeed={4}>Analytics</GradientText>
          </h1>
          {/* Tabs */}
          <div
            style={{
              display: 'flex',
              justifyContent: 'center',
              gap: '8px',
              flexWrap: 'wrap',
            }}
          >
            {TABS.map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                style={{
                  padding: '8px 16px',
                  borderRadius: '20px',
                  border: 'none',
                  background: activeTab === tab.id ? '#000' : '#f3f4f6',
                  color: activeTab === tab.id ? '#fff' : '#6b7280',
                  fontSize: '14px',
                  fontWeight: activeTab === tab.id ? '600' : '400',
                  cursor: 'pointer',
                  transition: 'all 0.2s',
                }}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </motion.div>

        {/* No tab selected - show placeholder */}
        {!activeTab ? (
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <p style={{ color: '#6b7280' }}>Choose a tab above to view analytics data</p>
          </motion.div>
        ) : activeTab === 'revenue' ? (
          <>
            {loading ? (
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
            <p className="text-gray-500">Loading analytics...</p>
          </motion.div>
        ) : data ? (
          <>
            {/* Stats Cards */}
            <motion.div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(4, 1fr)',
                gap: '16px',
                marginBottom: '16px',
              }}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.1 }}
            >
              <StatCard title="Gross Sales" value={data.totals.gross_sales} />
              <StatCard title="Taxes" value={data.totals.taxes} />
              <StatCard
                title="Orders"
                value={data.totals.orders_count}
                isCurrency={false}
              />
              <StatCard
                title="Items Sold"
                value={data.totals.items_sold}
                isCurrency={false}
              />
            </motion.div>

            {/* Date Range Quick Filters */}
            <motion.div
              className="flex gap-2 justify-center items-center flex-wrap"
              style={{ marginBottom: '24px' }}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.15 }}
            >
              {[
                { value: 'today', label: 'Today' },
                { value: '7days', label: 'Last 7 Days' },
                { value: '30days', label: 'Last 30 Days' },
                { value: '90days', label: 'Last 90 Days' },
                { value: 'year', label: 'This Year' },
                { value: 'total', label: 'All Time' },
              ].map((option) => (
                <button
                  key={option.value}
                  onClick={() => setDateRange(option.value as DateRange)}
                  style={{
                    padding: '8px 16px',
                    borderRadius: '20px',
                    border: dateRange === option.value ? '2px solid #000' : '1px solid #e5e7eb',
                    background: dateRange === option.value ? '#000' : '#fff',
                    color: dateRange === option.value ? '#fff' : '#374151',
                    fontSize: '13px',
                    fontWeight: dateRange === option.value ? '600' : '400',
                    cursor: 'pointer',
                    transition: 'all 0.2s',
                  }}
                >
                  {option.label}
                </button>
              ))}
            </motion.div>

            {/* Chart */}
            <motion.div
              style={{ background: 'white', borderRadius: '12px', padding: '24px' }}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.3 }}
            >
              <h3 style={{ fontSize: '18px', fontWeight: '600', marginBottom: '16px' }}>
                Revenue Over Time ({interval === 'day' ? 'Daily' : interval === 'week' ? 'Weekly' : 'Monthly'})
              </h3>
              <div style={{ width: '100%', height: 400 }}>
                <ResponsiveContainer>
                  <LineChart data={data.intervals}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                    <XAxis
                      dataKey="date_label"
                      tick={{ fontSize: 12 }}
                      tickLine={false}
                    />
                    <YAxis
                      tick={{ fontSize: 12 }}
                      tickLine={false}
                      tickFormatter={(value) =>
                        `$${(value / 1000).toFixed(0)}k`
                      }
                    />
                    <Tooltip
                      formatter={(value) => [
                        formatCurrency(value as number),
                        'Revenue',
                      ]}
                      labelFormatter={(label) => `Date: ${label}`}
                    />
                    <Line
                      type="monotone"
                      dataKey="net_revenue"
                      stroke="#000"
                      strokeWidth={2}
                      dot={{ fill: '#000', strokeWidth: 0, r: 4 }}
                      activeDot={{ r: 6, fill: '#000' }}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </motion.div>

            {/* Orders Chart */}
            <motion.div
              style={{ background: 'white', borderRadius: '12px', padding: '24px', marginTop: '24px' }}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.35 }}
            >
              <h3 style={{ fontSize: '18px', fontWeight: '600', marginBottom: '16px' }}>Orders Over Time</h3>
              <div style={{ width: '100%', height: 300 }}>
                <ResponsiveContainer>
                  <LineChart data={data.intervals}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                    <XAxis
                      dataKey="date_label"
                      tick={{ fontSize: 12 }}
                      tickLine={false}
                    />
                    <YAxis tick={{ fontSize: 12 }} tickLine={false} />
                    <Tooltip
                      formatter={(value) => [value as number, 'Orders']}
                      labelFormatter={(label) => `Date: ${label}`}
                    />
                    <Line
                      type="monotone"
                      dataKey="orders_count"
                      stroke="#3b82f6"
                      strokeWidth={2}
                      dot={{ fill: '#3b82f6', strokeWidth: 0, r: 4 }}
                      activeDot={{ r: 6, fill: '#3b82f6' }}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </motion.div>

            {/* Revenue Table */}
            <motion.div
              style={{ background: 'white', borderRadius: '12px', padding: '24px', marginTop: '24px' }}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.4 }}
            >
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                <h3 style={{ fontSize: '18px', fontWeight: '600' }}>Revenue</h3>
                <button
                  onClick={downloadCSV}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '6px',
                    padding: '8px 16px',
                    background: '#f3f4f6',
                    border: 'none',
                    borderRadius: '8px',
                    cursor: 'pointer',
                    fontSize: '14px',
                    color: '#374151',
                  }}
                >
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                  </svg>
                  Download
                </button>
              </div>
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '14px' }}>
                  <thead>
                    <tr style={{ borderBottom: '1px solid #e5e7eb' }}>
                      <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Date</th>
                      <th style={{ textAlign: 'center', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Orders</th>
                      <th style={{ textAlign: 'right', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Gross sales</th>
                      <th style={{ textAlign: 'right', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Taxes</th>
                      <th style={{ textAlign: 'right', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Total sales</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.intervals.filter(item => item.orders_count > 0).map((item, index) => (
                      <tr key={index} style={{ borderBottom: '1px solid #f3f4f6' }}>
                        <td style={{ padding: '12px 8px', color: '#111827' }}>{item.date_label}</td>
                        <td style={{ padding: '12px 8px', textAlign: 'center', color: '#3b82f6' }}>{item.orders_count}</td>
                        <td style={{ padding: '12px 8px', textAlign: 'right', color: '#111827' }}>{formatCurrency(item.gross_sales)}</td>
                        <td style={{ padding: '12px 8px', textAlign: 'right', color: '#111827' }}>{formatCurrency(item.taxes)}</td>
                        <td style={{ padding: '12px 8px', textAlign: 'right', color: '#111827' }}>{formatCurrency(item.gross_sales + item.taxes)}</td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr style={{ background: '#f9fafb' }}>
                      <td style={{ padding: '12px 8px', fontWeight: '600', color: '#111827' }}>
                        {data.intervals.filter(item => item.orders_count > 0).length} {interval === 'day' ? 'days' : interval === 'week' ? 'weeks' : 'months'}
                      </td>
                      <td style={{ padding: '12px 8px', textAlign: 'center', fontWeight: '600', color: '#111827' }}>{data.totals.orders_count} orders</td>
                      <td style={{ padding: '12px 8px', textAlign: 'right', fontWeight: '600', color: '#111827' }}>{formatCurrency(data.totals.gross_sales)} Gross sales</td>
                      <td style={{ padding: '12px 8px', textAlign: 'right', fontWeight: '600', color: '#111827' }}>{formatCurrency(data.totals.taxes)} Taxes</td>
                      <td style={{ padding: '12px 8px', textAlign: 'right', fontWeight: '600', color: '#111827' }}>{formatCurrency(data.totals.gross_sales + data.totals.taxes)} Total</td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </motion.div>
          </>
        ) : (
          <motion.div
            className="text-center py-12"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <p className="text-gray-500">No analytics data available</p>
          </motion.div>
        )}
          </>
        ) : activeTab === 'orders' ? (
          <>
            {loading ? (
              <motion.div
                className="text-center py-16"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
                <p className="text-gray-500">Loading orders...</p>
              </motion.div>
            ) : ordersData ? (
              <>
                {/* Stats Cards */}
                <motion.div
                  style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(4, 1fr)',
                    gap: '16px',
                    marginBottom: '16px',
                  }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.1 }}
                >
                  <StatCard title="Orders" value={ordersData.totals.orders_count} isCurrency={false} />
                  <StatCard title="Net Sales" value={ordersData.totals.net_sales} />
                  <StatCard title="Avg. Order Value" value={ordersData.totals.avg_order_value} />
                  <StatCard title="Avg. Items/Order" value={ordersData.totals.avg_items_per_order} isCurrency={false} />
                </motion.div>

                {/* Date Range Quick Filters */}
                <motion.div
                  className="flex gap-2 justify-center items-center flex-wrap"
                  style={{ marginBottom: '24px' }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.15 }}
                >
                  {[
                    { value: 'today', label: 'Today' },
                    { value: '7days', label: 'Last 7 Days' },
                    { value: '30days', label: 'Last 30 Days' },
                    { value: '90days', label: 'Last 90 Days' },
                    { value: 'year', label: 'This Year' },
                    { value: 'total', label: 'All Time' },
                  ].map((option) => (
                    <button
                      key={option.value}
                      onClick={() => setDateRange(option.value as DateRange)}
                      style={{
                        padding: '8px 16px',
                        borderRadius: '20px',
                        border: dateRange === option.value ? '2px solid #000' : '1px solid #e5e7eb',
                        background: dateRange === option.value ? '#000' : '#fff',
                        color: dateRange === option.value ? '#fff' : '#374151',
                        fontSize: '13px',
                        fontWeight: dateRange === option.value ? '600' : '400',
                        cursor: 'pointer',
                        transition: 'all 0.2s',
                      }}
                    >
                      {option.label}
                    </button>
                  ))}
                </motion.div>

                {/* Orders Chart */}
                {ordersData.intervals && ordersData.intervals.length > 0 && (
                  <motion.div
                    style={{ background: 'white', borderRadius: '12px', padding: '24px', marginBottom: '24px' }}
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.25 }}
                  >
                    <h3 style={{ fontSize: '18px', fontWeight: '600', marginBottom: '16px' }}>
                      Orders Over Time ({interval === 'day' ? 'Daily' : interval === 'week' ? 'Weekly' : 'Monthly'})
                    </h3>
                    <div style={{ width: '100%', height: 300 }}>
                      <ResponsiveContainer>
                        <BarChart data={ordersData.intervals}>
                          <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                          <XAxis
                            dataKey="date_label"
                            tick={{ fontSize: 12 }}
                            tickLine={false}
                          />
                          <YAxis tick={{ fontSize: 12 }} tickLine={false} />
                          <Tooltip
                            formatter={(value) => [value as number, 'Orders']}
                            labelFormatter={(label) => `Date: ${label}`}
                          />
                          <Bar
                            dataKey="orders_count"
                            fill="#3b82f6"
                            radius={[4, 4, 0, 0]}
                          />
                        </BarChart>
                      </ResponsiveContainer>
                    </div>
                  </motion.div>
                )}

                {/* Orders Table */}
                <motion.div
                  style={{ background: 'white', borderRadius: '12px', padding: '24px' }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.3 }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                    <h3 style={{ fontSize: '18px', fontWeight: '600' }}>Orders</h3>
                    <button
                      onClick={downloadOrdersCSV}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '6px',
                        padding: '8px 16px',
                        background: '#f3f4f6',
                        border: 'none',
                        borderRadius: '8px',
                        cursor: 'pointer',
                        fontSize: '14px',
                        color: '#374151',
                      }}
                    >
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                      </svg>
                      Download
                    </button>
                  </div>
                  <div style={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '14px' }}>
                      <thead>
                        <tr style={{ borderBottom: '1px solid #e5e7eb' }}>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Date</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Order #</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Status</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Dealer</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Product(s)</th>
                          <th style={{ textAlign: 'center', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Items</th>
                          <th style={{ textAlign: 'right', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Net Sales</th>
                        </tr>
                      </thead>
                      <tbody>
                        {ordersData.orders.map((order) => (
                          <tr key={order.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                            <td style={{ padding: '12px 8px', color: '#111827' }}>{order.date}</td>
                            <td style={{ padding: '12px 8px' }}>
                              <a
                                href={`/my-account/view-order/${order.id}/`}
                                style={{ color: '#3b82f6', textDecoration: 'none' }}
                              >
                                #{order.id}
                              </a>
                            </td>
                            <td style={{ padding: '12px 8px' }}>
                              <span
                                style={{
                                  display: 'inline-block',
                                  padding: '4px 12px',
                                  borderRadius: '20px',
                                  fontSize: '12px',
                                  fontWeight: '500',
                                  background: STATUS_COLORS[order.status]?.bg || '#f3f4f6',
                                  color: STATUS_COLORS[order.status]?.color || '#6b7280',
                                }}
                              >
                                {order.status_name}
                              </span>
                            </td>
                            <td style={{ padding: '12px 8px', color: '#111827' }}>{order.customer}</td>
                            <td style={{ padding: '12px 8px', color: '#6b7280', maxWidth: '200px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                              {order.products}
                            </td>
                            <td style={{ padding: '12px 8px', textAlign: 'center', color: '#111827' }}>{order.items_sold}</td>
                            <td style={{ padding: '12px 8px', textAlign: 'right', color: '#111827', fontWeight: '500' }}>{formatCurrency(order.net_sales)}</td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot>
                        <tr style={{ background: '#f9fafb' }}>
                          <td colSpan={5} style={{ padding: '12px 8px', fontWeight: '600', color: '#111827' }}>
                            {ordersData.orders.length} orders
                          </td>
                          <td style={{ padding: '12px 8px', textAlign: 'center', fontWeight: '600', color: '#111827' }}>
                            {ordersData.totals.total_items}
                          </td>
                          <td style={{ padding: '12px 8px', textAlign: 'right', fontWeight: '600', color: '#111827' }}>
                            {formatCurrency(ordersData.totals.net_sales)}
                          </td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </motion.div>
              </>
            ) : (
              <motion.div
                className="text-center py-12"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <p className="text-gray-500">No orders data available</p>
              </motion.div>
            )}
          </>
        ) : activeTab === 'products' ? (
          <>
            {loading ? (
              <motion.div
                className="text-center py-16"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
                <p className="text-gray-500">Loading products...</p>
              </motion.div>
            ) : productsData ? (
              <>
                {/* Stats Cards */}
                <motion.div
                  style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(4, 1fr)',
                    gap: '16px',
                    marginBottom: '16px',
                  }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.1 }}
                >
                  <StatCard title="Items Sold" value={productsData.totals.items_sold} isCurrency={false} />
                  <StatCard title="Net Revenue" value={productsData.totals.net_revenue} />
                  <StatCard title="Orders" value={productsData.totals.orders_count} isCurrency={false} />
                  <StatCard title="Products" value={productsData.totals.products_count} isCurrency={false} />
                </motion.div>

                {/* Date Range Quick Filters */}
                <motion.div
                  className="flex gap-2 justify-center items-center flex-wrap"
                  style={{ marginBottom: '24px' }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.15 }}
                >
                  {[
                    { value: 'today', label: 'Today' },
                    { value: '7days', label: 'Last 7 Days' },
                    { value: '30days', label: 'Last 30 Days' },
                    { value: '90days', label: 'Last 90 Days' },
                    { value: 'year', label: 'This Year' },
                    { value: 'total', label: 'All Time' },
                  ].map((option) => (
                    <button
                      key={option.value}
                      onClick={() => setDateRange(option.value as DateRange)}
                      style={{
                        padding: '8px 16px',
                        borderRadius: '20px',
                        border: dateRange === option.value ? '2px solid #000' : '1px solid #e5e7eb',
                        background: dateRange === option.value ? '#000' : '#fff',
                        color: dateRange === option.value ? '#fff' : '#374151',
                        fontSize: '13px',
                        fontWeight: dateRange === option.value ? '600' : '400',
                        cursor: 'pointer',
                        transition: 'all 0.2s',
                      }}
                    >
                      {option.label}
                    </button>
                  ))}
                </motion.div>

                {/* Products Table */}
                <motion.div
                  style={{ background: 'white', borderRadius: '12px', padding: '24px' }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.3 }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                    <h3 style={{ fontSize: '18px', fontWeight: '600' }}>Products</h3>
                    <button
                      onClick={downloadProductsCSV}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '6px',
                        padding: '8px 16px',
                        background: '#f3f4f6',
                        border: 'none',
                        borderRadius: '8px',
                        cursor: 'pointer',
                        fontSize: '14px',
                        color: '#374151',
                      }}
                    >
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                      </svg>
                      Download
                    </button>
                  </div>
                  <div style={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '14px' }}>
                      <thead>
                        <tr style={{ borderBottom: '1px solid #e5e7eb' }}>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Product</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Part Number</th>
                          <th style={{ textAlign: 'center', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Items Sold</th>
                          <th style={{ textAlign: 'right', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Net Revenue</th>
                          <th style={{ textAlign: 'center', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Orders</th>
                        </tr>
                      </thead>
                      <tbody>
                        {productsData.products.map((product) => (
                          <tr key={product.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                            <td style={{ padding: '12px 8px', color: '#111827', fontWeight: '500' }}>{product.name}</td>
                            <td style={{ padding: '12px 8px', color: '#6b7280', fontFamily: 'monospace' }}>{product.sku || '-'}</td>
                            <td style={{ padding: '12px 8px', textAlign: 'center', color: '#111827' }}>{product.items_sold}</td>
                            <td style={{ padding: '12px 8px', textAlign: 'right', color: '#111827', fontWeight: '500' }}>{formatCurrency(product.net_revenue)}</td>
                            <td style={{ padding: '12px 8px', textAlign: 'center', color: '#3b82f6' }}>{product.orders_count}</td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot>
                        <tr style={{ background: '#f9fafb' }}>
                          <td colSpan={2} style={{ padding: '12px 8px', fontWeight: '600', color: '#111827' }}>
                            {productsData.products.length} products
                          </td>
                          <td style={{ padding: '12px 8px', textAlign: 'center', fontWeight: '600', color: '#111827' }}>
                            {productsData.totals.items_sold}
                          </td>
                          <td style={{ padding: '12px 8px', textAlign: 'right', fontWeight: '600', color: '#111827' }}>
                            {formatCurrency(productsData.totals.net_revenue)}
                          </td>
                          <td style={{ padding: '12px 8px', textAlign: 'center', fontWeight: '600', color: '#111827' }}>
                            {productsData.totals.orders_count}
                          </td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </motion.div>
              </>
            ) : (
              <motion.div
                className="text-center py-12"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <p className="text-gray-500">No products data available</p>
              </motion.div>
            )}
          </>
        ) : activeTab === 'backorders' ? (
          <>
            {loading ? (
              <motion.div
                className="text-center py-16"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
                <p className="text-gray-500">Loading back orders...</p>
              </motion.div>
            ) : backordersData ? (
              <>
                {/* Stats Cards */}
                <motion.div
                  style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(4, 1fr)',
                    gap: '16px',
                    marginBottom: '16px',
                  }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.1 }}
                >
                  <StatCard title="Total Back Orders" value={backordersData.totals.total_items} isCurrency={false} />
                  <StatCard title="Total Qty" value={backordersData.totals.total_qty} isCurrency={false} />
                  <StatCard title="Pending" value={backordersData.totals.pending_count} isCurrency={false} />
                  <StatCard title="Fulfilled" value={backordersData.totals.fulfilled_count} isCurrency={false} />
                </motion.div>

                {/* Date Range Quick Filters */}
                <motion.div
                  className="flex gap-2 justify-center items-center flex-wrap"
                  style={{ marginBottom: '16px' }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.15 }}
                >
                  {[
                    { value: 'today', label: 'Today' },
                    { value: '7days', label: 'Last 7 Days' },
                    { value: '30days', label: 'Last 30 Days' },
                    { value: '90days', label: 'Last 90 Days' },
                    { value: 'year', label: 'This Year' },
                    { value: 'total', label: 'All Time' },
                  ].map((option) => (
                    <button
                      key={option.value}
                      onClick={() => setDateRange(option.value as DateRange)}
                      style={{
                        padding: '8px 16px',
                        borderRadius: '20px',
                        border: dateRange === option.value ? '2px solid #000' : '1px solid #e5e7eb',
                        background: dateRange === option.value ? '#000' : '#fff',
                        color: dateRange === option.value ? '#fff' : '#374151',
                        fontSize: '13px',
                        fontWeight: dateRange === option.value ? '600' : '400',
                        cursor: 'pointer',
                        transition: 'all 0.2s',
                      }}
                    >
                      {option.label}
                    </button>
                  ))}
                </motion.div>

                {/* Status Filter */}
                <motion.div
                  className="flex gap-2 justify-center items-center flex-wrap"
                  style={{ marginBottom: '24px' }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.2 }}
                >
                  <select
                    value={backorderFilter}
                    onChange={(e) => setBackorderFilter(e.target.value as 'all' | 'pending' | 'fulfilled')}
                    className="h-10 text-sm border border-gray-200 rounded-full bg-white focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent"
                    style={{ padding: '0 15px' }}
                  >
                    <option value="all">All Status</option>
                    <option value="pending">Pending Only</option>
                    <option value="fulfilled">Fulfilled Only</option>
                  </select>
                </motion.div>

                {/* Back Orders Table */}
                <motion.div
                  style={{ background: 'white', borderRadius: '12px', padding: '24px' }}
                  initial={{ opacity: 0, y: 20 }}
                  animate={{ opacity: 1, y: 0 }}
                  transition={{ delay: 0.2 }}
                >
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
                    <h3 style={{ fontSize: '18px', fontWeight: '600' }}>Back Orders</h3>
                    <button
                      onClick={downloadBackordersCSV}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: '6px',
                        padding: '8px 16px',
                        background: '#f3f4f6',
                        border: 'none',
                        borderRadius: '8px',
                        cursor: 'pointer',
                        fontSize: '14px',
                        color: '#374151',
                      }}
                    >
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                      </svg>
                      Download
                    </button>
                  </div>
                  <div style={{ overflowX: 'auto' }}>
                    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '14px' }}>
                      <thead>
                        <tr style={{ borderBottom: '1px solid #e5e7eb' }}>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Dealer Name</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Invoice #</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Part Number</th>
                          <th style={{ textAlign: 'left', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Product</th>
                          <th style={{ textAlign: 'center', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Qty</th>
                          <th style={{ textAlign: 'center', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Status</th>
                          <th style={{ textAlign: 'center', padding: '12px 8px', fontWeight: '500', color: '#6b7280' }}>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        {backordersData.backorders
                          .filter((item) => backorderFilter === 'all' || item.status === backorderFilter)
                          .map((item) => (
                          <tr key={`${item.order_id}-${item.item_id}`} style={{ borderBottom: '1px solid #f3f4f6' }}>
                            <td style={{ padding: '12px 8px', color: '#111827', fontWeight: '500' }}>{item.dealer_name}</td>
                            <td style={{ padding: '12px 8px' }}>
                              <a
                                href={`/my-account/view-order/${item.order_id}/`}
                                style={{ color: '#3b82f6', textDecoration: 'none' }}
                              >
                                #{item.order_id}
                              </a>
                            </td>
                            <td style={{ padding: '12px 8px', color: '#6b7280', fontFamily: 'monospace' }}>{item.part_number || '-'}</td>
                            <td style={{ padding: '12px 8px', color: '#111827' }}>{item.product_name}</td>
                            <td style={{ padding: '12px 8px', textAlign: 'center', color: '#111827' }}>{item.quantity}</td>
                            <td style={{ padding: '12px 8px', textAlign: 'center' }}>
                              <span
                                style={{
                                  display: 'inline-block',
                                  padding: '4px 12px',
                                  borderRadius: '20px',
                                  fontSize: '12px',
                                  fontWeight: '500',
                                  background: item.status === 'fulfilled' ? '#dcfce7' : '#fff7ed',
                                  color: item.status === 'fulfilled' ? '#16a34a' : '#ea580c',
                                }}
                              >
                                {item.status === 'fulfilled' ? 'Fulfilled' : 'Pending'}
                              </span>
                            </td>
                            <td style={{ padding: '12px 8px', textAlign: 'center' }}>
                              {item.status === 'pending' ? (
                                <button
                                  onClick={() => updateBackorderStatus(item.order_id, item.item_id, 'fulfilled')}
                                  style={{
                                    padding: '6px 12px',
                                    fontSize: '12px',
                                    fontWeight: '500',
                                    background: '#16a34a',
                                    color: 'white',
                                    border: 'none',
                                    borderRadius: '6px',
                                    cursor: 'pointer',
                                    transition: 'all 0.2s',
                                  }}
                                  onMouseOver={(e) => (e.currentTarget.style.background = '#15803d')}
                                  onMouseOut={(e) => (e.currentTarget.style.background = '#16a34a')}
                                >
                                  Mark Fulfilled
                                </button>
                              ) : (
                                <button
                                  onClick={() => updateBackorderStatus(item.order_id, item.item_id, 'pending')}
                                  style={{
                                    padding: '6px 12px',
                                    fontSize: '12px',
                                    fontWeight: '500',
                                    background: '#f3f4f6',
                                    color: '#6b7280',
                                    border: 'none',
                                    borderRadius: '6px',
                                    cursor: 'pointer',
                                    transition: 'all 0.2s',
                                  }}
                                  onMouseOver={(e) => (e.currentTarget.style.background = '#e5e7eb')}
                                  onMouseOut={(e) => (e.currentTarget.style.background = '#f3f4f6')}
                                >
                                  Undo
                                </button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                      <tfoot>
                        <tr style={{ background: '#f9fafb' }}>
                          <td colSpan={4} style={{ padding: '12px 8px', fontWeight: '600', color: '#111827' }}>
                            {backordersData.backorders.filter((item) => backorderFilter === 'all' || item.status === backorderFilter).length} back order items
                          </td>
                          <td style={{ padding: '12px 8px', textAlign: 'center', fontWeight: '600', color: '#111827' }}>
                            {backordersData.backorders.filter((item) => backorderFilter === 'all' || item.status === backorderFilter).reduce((sum, item) => sum + item.quantity, 0)}
                          </td>
                          <td></td>
                          <td></td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </motion.div>
              </>
            ) : (
              <motion.div
                className="text-center py-12"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <p className="text-gray-500">No back orders found</p>
              </motion.div>
            )}
          </>
        ) : null}
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('zeekr-analytics-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <ZeekrAnalyticsPage />
    </StrictMode>
  )
}

export default ZeekrAnalyticsPage
