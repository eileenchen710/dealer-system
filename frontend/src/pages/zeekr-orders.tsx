import { StrictMode, useState, useEffect, useRef } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
import '@/index.css'

interface Order {
  id: number
  status: string
  status_name: string
  date: string
  completed_date: string
  total: string
  customer: string
  email: string
  items_count: number
  po_number: string
  part_numbers: string
}

declare global {
  interface Window {
    zeekrOrders: {
      ajaxUrl: string
      nonce: string
    }
  }
}

const STATUS_COLORS: Record<string, string> = {
  pending: 'background:#f3f4f6;color:#6b7280;',
  sent: 'background:#dbeafe;color:#2563eb;',
  received: 'background:#e0e7ff;color:#4f46e5;',
  processing: 'background:#fef9c3;color:#ca8a04;',
  completed: 'background:#dcfce7;color:#16a34a;',
  cancelled: 'background:#fee2e2;color:#dc2626;',
  failed: 'background:#fee2e2;color:#dc2626;',
}

function ZeekrOrdersPage() {
  const config = window.zeekrOrders || {
    ajaxUrl: '',
    nonce: '',
  }

  const [orders, setOrders] = useState<Order[]>([])
  const [statuses, setStatuses] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const fetchOrders = async (searchTerm: string = '', status: string = 'all') => {
    setLoading(true)
    try {
      const formData = new FormData()
      formData.append('action', 'zeekr_get_orders')
      formData.append('nonce', config.nonce)
      formData.append('search', searchTerm)
      formData.append('status', status)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setOrders(result.data.orders)
        setStatuses(result.data.statuses)
      }
    } catch (error) {
      console.error('Failed to fetch orders:', error)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchOrders()
  }, [])

  const handleSearchChange = (value: string) => {
    setSearch(value)
    if (searchTimeoutRef.current) {
      clearTimeout(searchTimeoutRef.current)
    }
    searchTimeoutRef.current = setTimeout(() => {
      fetchOrders(value, statusFilter)
    }, 500)
  }

  const handleStatusFilterChange = (status: string) => {
    setStatusFilter(status)
    fetchOrders(search, status)
  }

  const truncateText = (text: string, maxLen: number) => {
    if (!text) return '-'
    if (text.length <= maxLen) return text
    return text.substring(0, maxLen - 3) + '...'
  }

  const [copyToast, setCopyToast] = useState<string | null>(null)
  const [copiedId, setCopiedId] = useState<string | null>(null)

  const handleCopy = (text: string, id: string) => {
    if (!text || text === '-') return
    navigator.clipboard.writeText(text).then(() => {
      setCopiedId(id)
      setCopyToast(text.length > 30 ? text.substring(0, 30) + '...' : text)
      setTimeout(() => {
        setCopiedId(null)
        setCopyToast(null)
      }, 2000)
    })
  }

  const getStatusStyle = (status: string) => {
    const style = STATUS_COLORS[status] || 'background:#f3f4f6;color:#6b7280;'
    const parts = style.split(';').filter(Boolean)
    const styleObj: Record<string, string> = {}
    parts.forEach(part => {
      const [key, value] = part.split(':')
      if (key && value) {
        styleObj[key.trim()] = value.trim()
      }
    })
    return styleObj
  }

  return (
    <div className="page-container">
      <div style={{ width: '100%', maxWidth: '95vw', margin: '0 auto', paddingTop: '120px', paddingBottom: '80px', paddingLeft: '16px', paddingRight: '16px' }}>
        {/* Copy Toast */}
        {copyToast && (
          <div className="copy-toast">
            Copied: {copyToast}
          </div>
        )}

        {/* Header */}
        <motion.div
          className="mb-8 text-center"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-4xl font-bold mb-2">
            <GradientText animationSpeed={4}>
              Orders Overview
            </GradientText>
          </h1>
          <p className="text-gray-500">View all dealer orders (Read-only)</p>
        </motion.div>

        {/* Filters */}
        <motion.div
          className="flex gap-4 justify-center items-center"
          style={{ marginBottom: '24px' }}
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.1 }}
        >
          <Input
            type="text"
            placeholder="Search by order ID, customer..."
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
            className="max-w-xs w-full !rounded-full"
          />
          <select
            value={statusFilter}
            onChange={(e) => handleStatusFilterChange(e.target.value)}
            className="h-10 text-sm border border-gray-200 rounded-full bg-white focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent"
            style={{ padding: '0 15px' }}
          >
            <option value="all">All Statuses</option>
            {Object.entries(statuses).map(([value, label]) => (
              <option key={value} value={value.replace('wc-', '')}>
                {label}
              </option>
            ))}
          </select>
          <Button onClick={() => fetchOrders(search, statusFilter)}>
            Refresh
          </Button>
        </motion.div>

        {/* Loading */}
        {loading ? (
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
            <p className="text-gray-500">Loading orders...</p>
          </motion.div>
        ) : (
          <>
            {/* Orders Table */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 }}
              className="bg-white overflow-hidden"
            >
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Invoice Number</TableHead>
                    <TableHead>P.O. Number</TableHead>
                    <TableHead>Part Number</TableHead>
                    <TableHead>Order Date</TableHead>
                    <TableHead>Completed Date</TableHead>
                    <TableHead>Dealer</TableHead>
                    <TableHead className="text-right">Total (excl. GST)</TableHead>
                    <TableHead style={{ minWidth: '120px', whiteSpace: 'nowrap' }}>Status</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <AnimatePresence>
                    {orders.map((order, index) => (
                      <motion.tr
                        key={order.id}
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -10 }}
                        transition={{ delay: index * 0.02 }}
                        className="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                      >
                        <TableCell className="font-medium">
                          <a
                            href={`/my-account/view-order/${order.id}/`}
                            className="text-blue-600 hover:text-blue-800 hover:underline"
                          >
                            #{order.id}
                          </a>
                        </TableCell>
                        <TableCell className="text-gray-600">
                          {order.po_number ? (
                            <span
                              onClick={() => handleCopy(order.po_number, `po-${order.id}`)}
                              className={`copyable-cell ${copiedId === `po-${order.id}` ? 'copied' : ''}`}
                              title="Click to copy"
                            >
                              {order.po_number}
                            </span>
                          ) : '-'}
                        </TableCell>
                        <TableCell className="text-gray-600 font-mono text-sm">
                          {order.part_numbers ? (
                            <span
                              onClick={() => handleCopy(order.part_numbers, `part-${order.id}`)}
                              className={`copyable-cell ${copiedId === `part-${order.id}` ? 'copied' : ''}`}
                              title={`Click to copy: ${order.part_numbers}`}
                            >
                              {truncateText(order.part_numbers, 20)}
                            </span>
                          ) : '-'}
                        </TableCell>
                        <TableCell className="text-gray-600">
                          {order.date}
                        </TableCell>
                        <TableCell className="text-gray-600">
                          {order.completed_date || '-'}
                        </TableCell>
                        <TableCell>
                          <div className="text-gray-900">{order.customer}</div>
                          <div className="text-gray-500 text-xs">{order.email}</div>
                        </TableCell>
                        <TableCell className="text-right font-medium text-gray-900">
                          ${(parseFloat(order.total) / 1.1).toFixed(2)}
                        </TableCell>
                        <TableCell style={{ minWidth: '120px', whiteSpace: 'nowrap' }}>
                          <span
                            className="inline-block text-sm font-medium rounded-full"
                            style={{ padding: '4px 12px', ...getStatusStyle(order.status) }}
                          >
                            {order.status_name}
                          </span>
                        </TableCell>
                      </motion.tr>
                    ))}
                  </AnimatePresence>
                </TableBody>
              </Table>
            </motion.div>

            {/* Empty State */}
            {orders.length === 0 && (
              <motion.div
                className="text-center py-12"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <p className="text-gray-500">No orders found</p>
              </motion.div>
            )}

            {/* Stats */}
            <motion.div
              className="mt-6 text-center"
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
            >
              <p className="text-sm text-gray-400">
                Showing {orders.length} order{orders.length !== 1 ? 's' : ''}
              </p>
            </motion.div>
          </>
        )}
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('zeekr-orders-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <ZeekrOrdersPage />
    </StrictMode>
  )
}

export default ZeekrOrdersPage
