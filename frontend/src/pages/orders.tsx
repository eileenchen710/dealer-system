import { StrictMode, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
import '@/index.css'

interface OrderItem {
  name: string
  quantity: number
  total: number
}

interface Order {
  id: number
  number: string
  date: string
  status: string
  total: number
  items: OrderItem[]
}

declare global {
  interface Window {
    dealerOrders: {
      orders: Order[]
    }
  }
}

function OrdersPage() {
  const config = window.dealerOrders || { orders: [] }
  const [expandedOrder, setExpandedOrder] = useState<number | null>(null)

  const getStatusStyle = (status: string) => {
    switch (status.toLowerCase()) {
      case 'completed':
        return 'bg-green-100 text-green-700 border-green-200'
      case 'processing':
        return 'bg-blue-100 text-blue-700 border-blue-200'
      case 'pending':
        return 'bg-yellow-100 text-yellow-700 border-yellow-200'
      case 'on-hold':
        return 'bg-orange-100 text-orange-700 border-orange-200'
      case 'cancelled':
      case 'failed':
        return 'bg-red-100 text-red-700 border-red-200'
      default:
        return 'bg-gray-100 text-gray-700 border-gray-200'
    }
  }

  const toggleOrder = (orderId: number) => {
    setExpandedOrder(expandedOrder === orderId ? null : orderId)
  }

  return (
    <div className="min-h-screen bg-white py-8 px-6">
      <div className="max-w-5xl mx-auto">
        {/* Header */}
        <motion.div
          className="mb-8 text-center"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-4xl font-bold mb-2">
            <GradientText animationSpeed={4}>
              My Orders
            </GradientText>
          </h1>
          <p className="text-gray-500">View your order history</p>
        </motion.div>

        {config.orders.length > 0 ? (
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.1 }}
            className="space-y-4"
          >
            {config.orders.map((order, index) => (
              <motion.div
                key={order.id}
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: index * 0.05 }}
                className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm"
              >
                {/* Order Header */}
                <div
                  className="p-4 flex flex-wrap items-center justify-between gap-4 cursor-pointer hover:bg-gray-50 transition-colors"
                  onClick={() => toggleOrder(order.id)}
                >
                  <div className="flex items-center gap-4">
                    <div>
                      <span className="text-gray-600 font-mono">#{order.number}</span>
                      <p className="text-sm text-gray-400">{order.date}</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-4">
                    <span className={`px-3 py-1 rounded-full text-xs font-medium border ${getStatusStyle(order.status)}`}>
                      {order.status}
                    </span>
                    <span className="text-lg font-semibold text-gray-900">${order.total.toFixed(2)}</span>
                    <motion.span
                      animate={{ rotate: expandedOrder === order.id ? 180 : 0 }}
                      className="text-gray-400"
                    >
                      ▼
                    </motion.span>
                  </div>
                </div>

                {/* Order Details */}
                <AnimatePresence>
                  {expandedOrder === order.id && (
                    <motion.div
                      initial={{ height: 0, opacity: 0 }}
                      animate={{ height: 'auto', opacity: 1 }}
                      exit={{ height: 0, opacity: 0 }}
                      transition={{ duration: 0.2 }}
                      className="border-t border-gray-200"
                    >
                      <div className="p-4">
                        <Table>
                          <TableHeader>
                            <TableRow>
                              <TableHead>Product</TableHead>
                              <TableHead className="text-center">Qty</TableHead>
                              <TableHead className="text-right">Total</TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {order.items.map((item, itemIndex) => (
                              <TableRow key={itemIndex}>
                                <TableCell className="text-gray-900">{item.name}</TableCell>
                                <TableCell className="text-center text-gray-700">{item.quantity}</TableCell>
                                <TableCell className="text-right text-gray-900">${item.total.toFixed(2)}</TableCell>
                              </TableRow>
                            ))}
                          </TableBody>
                        </Table>

                        <div className="mt-4 flex justify-between items-center pt-4 border-t border-gray-200">
                          <span className="text-gray-500">Order Total</span>
                          <span className="text-xl font-bold">
                            <GradientText animationSpeed={4}>
                              ${order.total.toFixed(2)}
                            </GradientText>
                          </span>
                        </div>
                      </div>
                    </motion.div>
                  )}
                </AnimatePresence>
              </motion.div>
            ))}
          </motion.div>
        ) : (
          /* Empty State */
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
          >
            <div className="text-6xl mb-4">📦</div>
            <h2 className="text-2xl font-bold text-gray-900 mb-2">No orders yet</h2>
            <p className="text-gray-500 mb-6">Your order history will appear here</p>
            <Button onClick={() => window.location.href = '/'}>
              Browse Inventory
            </Button>
          </motion.div>
        )}
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('dealer-orders-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <OrdersPage />
    </StrictMode>
  )
}

export default OrdersPage
