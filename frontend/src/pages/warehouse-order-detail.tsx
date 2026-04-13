import { StrictMode, useState, useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/Alert'
import { Dialog, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/Dialog'
import '@/index.css'

interface OrderItem {
  item_id: number
  name: string
  sku: string
  quantity: number
  order_type: string
  is_backorder: boolean
  backorder_status: string
}

interface DealerInfo {
  dealer_group: string
  dealer_company_name: string
  business_name: string
  delivery_address_full: string
  suburb: string
  state: string
  post_code: string
  operating_hours_weekday: string
  operating_hours_saturday: string
  accounts_payable: string
  accounts_payable_email: string
  accounts_payable_mobile: string
  accounts_payable_phone: string
  parts_manager: string
  parts_manager_email: string
  parts_manager_mobile: string
  parts_manager_phone: string
  parts_interpreter_front: string
  parts_interpreter_front_email: string
  parts_interpreter_front_mobile: string
  parts_interpreter_front_phone: string
  parts_interpreter_back: string
  parts_interpreter_back_email: string
  parts_interpreter_back_mobile: string
  parts_interpreter_back_phone: string
  parts_group: string
  parts_group_email: string
  parts_group_mobile: string
  parts_group_phone: string
}

interface OrderDetail {
  id: number
  status: string
  status_name: string
  date: string
  customer: string
  email: string
  phone: string
  items: OrderItem[]
  notes: string
  dealer_info: DealerInfo
  po_number: string
  con_note: string
  activity_number: string
}

declare global {
  interface Window {
    warehouseOrderDetail: {
      ajaxUrl: string
      nonce: string
      updateNonce: string
      orderId: number
      ordersPageUrl: string
      logoUrl: string
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

const ORDER_TYPE_LABELS: Record<string, string> = {
  stock_order: 'Stock Order',
  daily_order: 'Daily Order',
  vor_order: 'VOR Order',
}

function WarehouseOrderDetailPage() {
  const config = window.warehouseOrderDetail || {
    ajaxUrl: '',
    nonce: '',
    updateNonce: '',
    orderId: 0,
    ordersPageUrl: '/warehouse-orders/',
    logoUrl: '',
  }

  const handlePrint = () => {
    window.print()
  }

  const [order, setOrder] = useState<OrderDetail | null>(null)
  const [statuses, setStatuses] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [updating, setUpdating] = useState(false)
  const [alert, setAlert] = useState<{ show: boolean; message: string; error?: boolean } | null>(null)

  // Completion dialog state
  const [showCompletionDialog, setShowCompletionDialog] = useState(false)
  const [conNote, setConNote] = useState('')
  const [activityNumber, setActivityNumber] = useState('')

  const fetchOrderDetail = async () => {
    setLoading(true)
    try {
      const formData = new FormData()
      formData.append('action', 'warehouse_get_order_detail')
      formData.append('nonce', config.nonce)
      formData.append('order_id', String(config.orderId))

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setOrder(result.data.order)
        setStatuses(result.data.statuses)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to load order', error: true })
      }
    } catch (error) {
      console.error('Failed to fetch order:', error)
      setAlert({ show: true, message: 'Network error', error: true })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (config.orderId) {
      fetchOrderDetail()
    }
  }, [])

  const handleUpdateStatus = async (newStatus: string, extraData?: { con_note?: string; activity_number?: string }) => {
    if (!order) return

    // If changing to completed and no extra data provided, show dialog
    if (newStatus === 'completed' && !extraData) {
      setShowCompletionDialog(true)
      return
    }

    setUpdating(true)
    try {
      const formData = new FormData()
      formData.append('action', 'warehouse_update_order_status')
      formData.append('nonce', config.updateNonce)
      formData.append('order_id', String(order.id))
      formData.append('status', newStatus)

      // Add extra data if provided
      if (extraData?.con_note) {
        formData.append('con_note', extraData.con_note)
      }
      if (extraData?.activity_number) {
        formData.append('activity_number', extraData.activity_number)
      }

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setOrder(prev => prev ? {
          ...prev,
          status: result.data.new_status,
          status_name: result.data.new_status_name,
          con_note: result.data.con_note || prev.con_note,
          activity_number: result.data.activity_number || prev.activity_number,
        } : null)
        setAlert({ show: true, message: `Status updated to ${result.data.new_status_name}` })
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to update', error: true })
        setTimeout(() => setAlert(null), 4000)
      }
    } catch (error) {
      setAlert({ show: true, message: 'Network error', error: true })
      setTimeout(() => setAlert(null), 4000)
    } finally {
      setUpdating(false)
    }
  }

  const handleCompleteOrder = () => {
    if (!conNote.trim() || !activityNumber.trim()) {
      setAlert({ show: true, message: 'Please fill in both CON NOTE and Activity Number', error: true })
      setTimeout(() => setAlert(null), 4000)
      return
    }

    setShowCompletionDialog(false)
    handleUpdateStatus('completed', {
      con_note: conNote.trim(),
      activity_number: activityNumber.trim(),
    })

    // Reset form
    setConNote('')
    setActivityNumber('')
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

  const InfoItem = ({ label, value }: { label: string; value: string }) => (
    <div>
      <p className="text-sm text-gray-500">{label}</p>
      <p className="font-medium text-gray-900">{value || '-'}</p>
    </div>
  )

  const ContactCard = ({ title, name, email, mobile, phone }: {
    title: string
    name: string
    email: string
    mobile: string
    phone: string
  }) => {
    if (!name && !email && !mobile && !phone) return null
    return (
      <div className="bg-white rounded-lg p-4 border border-gray-100">
        <h4 className="text-sm font-semibold text-gray-700 mb-3 border-b pb-2">{title}</h4>
        <div className="space-y-2 text-sm">
          {name && <div><span className="text-gray-500">Name:</span> <span className="text-gray-900">{name}</span></div>}
          {email && <div><span className="text-gray-500">Email:</span> <span className="text-gray-900">{email}</span></div>}
          {mobile && <div><span className="text-gray-500">Mobile:</span> <span className="text-gray-900">{mobile}</span></div>}
          {phone && <div><span className="text-gray-500">Phone:</span> <span className="text-gray-900">{phone}</span></div>}
        </div>
      </div>
    )
  }

  return (
    <div className="page-container">
      <div className="page-content" style={{ paddingTop: '120px' }}>
        {/* Print Header - Only visible when printing */}
        {config.logoUrl && (
          <div className="print-header">
            <img src={config.logoUrl} alt="ZEEKR" style={{ maxHeight: '150px' }} />
          </div>
        )}

        {/* Print Footer - Page numbers */}
        <div className="print-footer"></div>

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

        {/* Completion Dialog */}
        <Dialog open={showCompletionDialog} onOpenChange={setShowCompletionDialog}>
          <DialogHeader>
            <DialogTitle>Complete Order</DialogTitle>
            <DialogDescription>
              Please enter the transport CON NOTE and warehouse Activity Number to complete this order.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 my-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                CON NOTE <span className="text-red-500">*</span>
              </label>
              <Input
                type="text"
                value={conNote}
                onChange={(e) => setConNote(e.target.value)}
                placeholder="Enter CON NOTE"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Warehouse Activity Number <span className="text-red-500">*</span>
              </label>
              <Input
                type="text"
                value={activityNumber}
                onChange={(e) => setActivityNumber(e.target.value)}
                placeholder="Enter Activity Number"
              />
            </div>
          </div>
          <DialogFooter>
            <Button
              onClick={() => {
                setShowCompletionDialog(false)
                setConNote('')
                setActivityNumber('')
              }}
              style={{ background: '#f3f4f6', color: '#374151' }}
            >
              Cancel
            </Button>
            <Button onClick={handleCompleteOrder} disabled={updating}>
              {updating ? 'Completing...' : 'Complete Order'}
            </Button>
          </DialogFooter>
        </Dialog>

        {/* Back Button */}
        <motion.div
          className="mb-6"
          initial={{ opacity: 0, x: -20 }}
          animate={{ opacity: 1, x: 0 }}
        >
          <a
            href={config.ordersPageUrl}
            className="inline-flex items-center gap-2 text-gray-600 hover:text-gray-900 transition-colors"
          >
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Orders
          </a>
        </motion.div>

        {/* Loading */}
        {loading ? (
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
            <p className="text-gray-500">Loading order details...</p>
          </motion.div>
        ) : order ? (
          <>
            {/* Header */}
            <motion.div
              className="mb-8"
              initial={{ opacity: 0, y: -20 }}
              animate={{ opacity: 1, y: 0 }}
            >
              <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                  <h1 className="text-4xl font-bold mb-2">
                    <GradientText animationSpeed={4}>
                      Invoice Number #{order.id}
                    </GradientText>
                  </h1>
                  <p className="text-gray-500">{order.date}</p>
                </div>
                <div className="flex items-center gap-4 no-print">
                  <select
                    value={order.status}
                    onChange={(e) => handleUpdateStatus(e.target.value)}
                    disabled={updating}
                    className="h-10 px-4 py-2 text-sm border border-gray-200 rounded-full bg-white focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent font-medium"
                    style={getStatusStyle(order.status)}
                  >
                    {Object.entries(statuses).map(([value, label]) => (
                      <option key={value} value={value.replace('wc-', '')}>
                        {label}
                      </option>
                    ))}
                  </select>
                  <Button onClick={handlePrint}>
                    Print
                  </Button>
                </div>
              </div>
            </motion.div>

            {/* Order Reference */}
            {(order.po_number || order.con_note || order.activity_number) && (
              <motion.div
                className="mb-8 bg-blue-50 rounded-xl p-6"
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.1 }}
              >
                <h2 className="text-lg font-semibold mb-4">Order Reference</h2>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  {order.po_number && (
                    <div>
                      <p className="text-sm text-gray-500">Dealer P.O. Number</p>
                      <p className="font-semibold text-lg text-gray-900">{order.po_number}</p>
                    </div>
                  )}
                  {order.con_note && (
                    <div>
                      <p className="text-sm text-gray-500">Transport CON NOTE</p>
                      <p className="font-semibold text-lg text-gray-900">{order.con_note}</p>
                    </div>
                  )}
                  {order.activity_number && (
                    <div>
                      <p className="text-sm text-gray-500">Warehouse Activity Number</p>
                      <p className="font-semibold text-lg text-gray-900">{order.activity_number}</p>
                    </div>
                  )}
                </div>
              </motion.div>
            )}

            {/* Customer Info */}
            <motion.div
              className="mb-8 bg-gray-50 rounded-xl p-6"
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.15 }}
            >
              <h2 className="text-lg font-semibold mb-4">Customer Information</h2>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <InfoItem label="Name" value={order.customer} />
                <InfoItem label="Email" value={order.email} />
                <InfoItem label="Phone" value={order.phone} />
              </div>
            </motion.div>

            {/* Dealer Information - Print Version (simplified) */}
            {order.dealer_info && (
              <motion.div
                className="mb-8 bg-gray-50 rounded-xl p-6 print-only"
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.15 }}
              >
                <h2 className="text-lg font-semibold mb-4">Dealer Information</h2>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <p className="text-sm text-gray-500">Dealer Name</p>
                    <p className="font-semibold text-gray-900">{order.dealer_info.dealer_company_name || order.dealer_info.business_name || '-'}</p>
                  </div>
                  <div>
                    <p className="text-sm text-gray-500">Delivery Address</p>
                    <p className="font-semibold text-gray-900">
                      {order.dealer_info.delivery_address_full}
                      {order.dealer_info.suburb && `, ${order.dealer_info.suburb}`}
                      {order.dealer_info.state && ` ${order.dealer_info.state}`}
                      {order.dealer_info.post_code && ` ${order.dealer_info.post_code}`}
                    </p>
                  </div>
                </div>
              </motion.div>
            )}

            {/* Dealer Information - Screen Version (detailed) */}
            {order.dealer_info && (
              <motion.div
                className="mb-8 bg-gray-50 rounded-xl p-6 no-print"
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.15 }}
              >
                <h2 className="text-lg font-semibold mb-4">Dealer Information</h2>

                {/* Business Info */}
                <div className="mb-6">
                  <h3 className="text-sm font-medium text-gray-600 mb-3">Business Details</h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <InfoItem label="Dealer Group" value={order.dealer_info.dealer_group} />
                    <InfoItem label="Company Name" value={order.dealer_info.dealer_company_name} />
                    <InfoItem label="Business Name" value={order.dealer_info.business_name} />
                    <InfoItem label="Delivery Address" value={order.dealer_info.delivery_address_full} />
                    <InfoItem label="Suburb" value={order.dealer_info.suburb} />
                    <InfoItem label="State" value={order.dealer_info.state} />
                    <InfoItem label="Post Code" value={order.dealer_info.post_code} />
                  </div>
                </div>

                {/* Operating Hours */}
                <div className="mb-6">
                  <h3 className="text-sm font-medium text-gray-600 mb-3">Operating Hours</h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <InfoItem label="Monday - Friday" value={order.dealer_info.operating_hours_weekday} />
                    <InfoItem label="Saturday" value={order.dealer_info.operating_hours_saturday} />
                  </div>
                </div>

                {/* Contacts */}
                <div>
                  <h3 className="text-sm font-medium text-gray-600 mb-3">Contacts</h3>
                  <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <ContactCard
                      title="Accounts Payable"
                      name={order.dealer_info.accounts_payable}
                      email={order.dealer_info.accounts_payable_email}
                      mobile={order.dealer_info.accounts_payable_mobile}
                      phone={order.dealer_info.accounts_payable_phone}
                    />
                    <ContactCard
                      title="Parts Manager"
                      name={order.dealer_info.parts_manager}
                      email={order.dealer_info.parts_manager_email}
                      mobile={order.dealer_info.parts_manager_mobile}
                      phone={order.dealer_info.parts_manager_phone}
                    />
                    <ContactCard
                      title="Parts Interpreter (Front)"
                      name={order.dealer_info.parts_interpreter_front}
                      email={order.dealer_info.parts_interpreter_front_email}
                      mobile={order.dealer_info.parts_interpreter_front_mobile}
                      phone={order.dealer_info.parts_interpreter_front_phone}
                    />
                    <ContactCard
                      title="Parts Interpreter (Back)"
                      name={order.dealer_info.parts_interpreter_back}
                      email={order.dealer_info.parts_interpreter_back_email}
                      mobile={order.dealer_info.parts_interpreter_back_mobile}
                      phone={order.dealer_info.parts_interpreter_back_phone}
                    />
                    <ContactCard
                      title="Parts Group"
                      name={order.dealer_info.parts_group}
                      email={order.dealer_info.parts_group_email}
                      mobile={order.dealer_info.parts_group_mobile}
                      phone={order.dealer_info.parts_group_phone}
                    />
                  </div>
                </div>
              </motion.div>
            )}

            {/* Order Items - Only show invoiced items (exclude backorders) */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 }}
              className="mb-8"
            >
              <h2 className="text-lg font-semibold mb-4">Order Items</h2>
              <div className="bg-white rounded-xl overflow-hidden border border-gray-100">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Part Number / Product</TableHead>
                      <TableHead>Type</TableHead>
                      <TableHead className="text-center">Qty</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    <AnimatePresence>
                      {order.items.filter(item => !item.is_backorder).map((item, index) => (
                        <motion.tr
                          key={item.item_id || index}
                          initial={{ opacity: 0, y: 10 }}
                          animate={{ opacity: 1, y: 0 }}
                          exit={{ opacity: 0, y: -10 }}
                          transition={{ delay: index * 0.02 }}
                          className="border-b border-gray-100 transition-colors hover:bg-gray-50"
                        >
                          <TableCell>
                            <div className="flex flex-col">
                              <span className="product-part-number font-mono font-semibold text-gray-900" style={{ fontSize: '14px' }}>
                                {item.sku || '-'}
                              </span>
                              <span className="product-name text-gray-500" style={{ fontSize: '12px' }}>
                                {item.name}
                              </span>
                            </div>
                          </TableCell>
                          <TableCell>
                            <span className="inline-block px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 rounded">
                              {ORDER_TYPE_LABELS[item.order_type] || item.order_type || 'Stock Order'}
                            </span>
                          </TableCell>
                          <TableCell className="text-center text-gray-900">
                            {item.quantity}
                          </TableCell>
                        </motion.tr>
                      ))}
                    </AnimatePresence>
                  </TableBody>
                </Table>
              </div>
            </motion.div>

            {/* Order Notes */}
            {order.notes && (
              <motion.div
                className="bg-yellow-50 border border-yellow-200 rounded-xl p-6"
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.4 }}
              >
                <h2 className="text-lg font-semibold mb-2 text-yellow-800">Order Notes</h2>
                <p className="text-yellow-700 whitespace-pre-wrap">{order.notes}</p>
              </motion.div>
            )}
          </>
        ) : (
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <p className="text-gray-500">Order not found</p>
            <Button
              onClick={() => window.location.href = config.ordersPageUrl}
              className="mt-4"
            >
              Back to Orders
            </Button>
          </motion.div>
        )}
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('warehouse-order-detail-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <WarehouseOrderDetailPage />
    </StrictMode>
  )
}

export default WarehouseOrderDetailPage
