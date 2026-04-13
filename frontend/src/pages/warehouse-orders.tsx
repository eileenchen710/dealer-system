import { StrictMode, useState, useEffect, useRef } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/Alert'
import '@/index.css'

interface Order {
  id: number
  status: string
  status_name: string
  date: string
  completed_date: string
  customer: string
  email: string
  items_count: number
  po_number: string
  part_numbers: string
  con_note: string
}

declare global {
  interface Window {
    warehouseOrders: {
      ajaxUrl: string
      nonce: string
      updateNonce: string
      orderDetailUrl: string
    }
  }
}

const STATUS_COLORS: Record<string, string> = {
  pending: 'background:#f3f4f6;color:#6b7280;',      // Unpaid
  sent: 'background:#dbeafe;color:#2563eb;',         // Sent
  received: 'background:#e0e7ff;color:#4f46e5;',     // Received
  processing: 'background:#fef9c3;color:#ca8a04;',   // Pending
  completed: 'background:#dcfce7;color:#16a34a;',    // Completed
  cancelled: 'background:#fee2e2;color:#dc2626;',    // Cancelled
  failed: 'background:#fee2e2;color:#dc2626;',       // Failed
}

function WarehouseOrdersPage() {
  const config = window.warehouseOrders || {
    ajaxUrl: '',
    nonce: '',
    updateNonce: '',
    orderDetailUrl: '/warehouse-order/',
  }

  const [orders, setOrders] = useState<Order[]>([])
  const [statuses, setStatuses] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')

  // Update header badge
  const updateHeaderBadge = (count: number) => {
    const badge = document.getElementById('received-orders-badge')
    if (badge) {
      badge.textContent = String(count)
      badge.style.display = count > 0 ? '' : 'none'
    }
  }
  const [statusFilter, setStatusFilter] = useState('all')
  const [updating, setUpdating] = useState<number | null>(null)
  const [alert, setAlert] = useState<{ show: boolean; message: string; error?: boolean } | null>(null)
  const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const fetchOrders = async (searchTerm: string = '', status: string = 'all') => {
    setLoading(true)
    try {
      const formData = new FormData()
      formData.append('action', 'warehouse_get_orders')
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
        updateHeaderBadge(result.data.received_count || 0)
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

  const handleUpdateStatus = async (orderId: number, newStatus: string) => {
    setUpdating(orderId)
    try {
      const formData = new FormData()
      formData.append('action', 'warehouse_update_order_status')
      formData.append('nonce', config.updateNonce)
      formData.append('order_id', String(orderId))
      formData.append('status', newStatus)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        // Update local state
        setOrders(prev => prev.map(order =>
          order.id === orderId
            ? {
                ...order,
                status: result.data.new_status,
                status_name: result.data.new_status_name,
                completed_date: result.data.completed_date || order.completed_date
              }
            : order
        ))
        // Update header badge
        if (result.data.received_count !== undefined) {
          updateHeaderBadge(result.data.received_count)
        }
        setAlert({ show: true, message: `Invoice Number #${orderId} status updated to ${result.data.new_status_name}` })
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to update', error: true })
        setTimeout(() => setAlert(null), 4000)
      }
    } catch (error) {
      setAlert({ show: true, message: 'Network error', error: true })
      setTimeout(() => setAlert(null), 4000)
    } finally {
      setUpdating(null)
    }
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

  // CON NOTE editing state
  const [editingConNote, setEditingConNote] = useState<number | null>(null)
  const [conNoteValue, setConNoteValue] = useState('')
  const [savingConNote, setSavingConNote] = useState(false)

  const handleEditConNote = (orderId: number, currentValue: string) => {
    setEditingConNote(orderId)
    setConNoteValue(currentValue || '')
  }

  const handleSaveConNote = async (orderId: number) => {
    setSavingConNote(true)
    try {
      const formData = new FormData()
      formData.append('action', 'warehouse_update_con_note')
      formData.append('nonce', config.updateNonce)
      formData.append('order_id', String(orderId))
      formData.append('con_note', conNoteValue)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        // Update local state
        setOrders(prev => prev.map(order =>
          order.id === orderId
            ? { ...order, con_note: result.data.con_note }
            : order
        ))
        setAlert({ show: true, message: `CON NOTE updated for Invoice #${orderId}` })
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to update CON NOTE', error: true })
        setTimeout(() => setAlert(null), 4000)
      }
    } catch (error) {
      setAlert({ show: true, message: 'Network error', error: true })
      setTimeout(() => setAlert(null), 4000)
    } finally {
      setSavingConNote(false)
      setEditingConNote(null)
    }
  }

  const handleCancelConNote = () => {
    setEditingConNote(null)
    setConNoteValue('')
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

        {/* Alert */}
        <AnimatePresence>
          {alert?.show && (
            <motion.div
              initial={{ opacity: 0, x: 20 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: 20 }}
              className="fixed bottom-6 right-6 z-50 w-full max-w-sm"
            >
              <Alert variant={alert.error ? 'destructive' : 'default'}>
                {alert.error ? (
                  <svg className="h-4 w-4 text-red-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                ) : (
                  <svg className="h-4 w-4 text-green-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                )}
                <div>
                  <AlertTitle>{alert.error ? 'Error' : 'Success'}</AlertTitle>
                  <AlertDescription>{alert.message}</AlertDescription>
                </div>
              </Alert>
            </motion.div>
          )}
        </AnimatePresence>

        {/* Header */}
        <motion.div
          className="mb-8 text-center"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-4xl font-bold mb-2">
            <GradientText animationSpeed={4}>
              Orders Management
            </GradientText>
          </h1>
          <p className="text-gray-500">View and manage all dealer orders</p>
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
                    <TableHead>Customer</TableHead>
                    <TableHead style={{ minWidth: '180px' }}>CON NOTE</TableHead>
                    <TableHead style={{ minWidth: '140px', whiteSpace: 'nowrap' }}>Status</TableHead>
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
                        <TableCell style={{ minWidth: '180px' }}>
                          {editingConNote === order.id ? (
                            <div style={{ display: 'flex', gap: '4px', alignItems: 'center' }}>
                              <Input
                                type="text"
                                value={conNoteValue}
                                onChange={(e) => setConNoteValue(e.target.value)}
                                className="h-8 text-sm"
                                style={{ width: '120px' }}
                                placeholder="Enter CON NOTE"
                                autoFocus
                                onKeyDown={(e) => {
                                  if (e.key === 'Enter') handleSaveConNote(order.id)
                                  if (e.key === 'Escape') handleCancelConNote()
                                }}
                              />
                              <button
                                onClick={() => handleSaveConNote(order.id)}
                                disabled={savingConNote}
                                className="p-1 text-green-600 hover:bg-green-50 rounded"
                                title="Save"
                              >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                </svg>
                              </button>
                              <button
                                onClick={handleCancelConNote}
                                className="p-1 text-gray-400 hover:bg-gray-50 rounded"
                                title="Cancel"
                              >
                                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                              </button>
                            </div>
                          ) : (
                            <div
                              onClick={() => handleEditConNote(order.id, order.con_note)}
                              className="cursor-pointer hover:bg-gray-50 rounded px-2 py-1 min-h-[28px] flex items-center"
                              title="Click to edit"
                            >
                              {order.con_note ? (
                                <span className="text-gray-700">{order.con_note}</span>
                              ) : (
                                <span className="text-gray-400 italic text-sm">Click to add</span>
                              )}
                            </div>
                          )}
                        </TableCell>
                        <TableCell style={{ minWidth: '140px', whiteSpace: 'nowrap' }}>
                          <select
                            value={order.status}
                            onChange={(e) => handleUpdateStatus(order.id, e.target.value)}
                            disabled={updating === order.id}
                            className="h-8 text-sm border border-gray-200 rounded-full bg-white focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent"
                            style={{
                              padding: '0 12px',
                              ...(() => {
                                const style = STATUS_COLORS[order.status] || 'background:#f3f4f6;color:#6b7280;'
                                const parts = style.split(';').filter(Boolean)
                                const styleObj: Record<string, string> = {}
                                parts.forEach(part => {
                                  const [key, value] = part.split(':')
                                  if (key && value) {
                                    styleObj[key.trim()] = value.trim()
                                  }
                                })
                                return styleObj
                              })()
                            }}
                          >
                            {Object.entries(statuses).map(([value, label]) => (
                              <option key={value} value={value.replace('wc-', '')}>
                                {label}
                              </option>
                            ))}
                          </select>
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
const container = document.getElementById('warehouse-orders-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <WarehouseOrdersPage />
    </StrictMode>
  )
}

export default WarehouseOrdersPage
