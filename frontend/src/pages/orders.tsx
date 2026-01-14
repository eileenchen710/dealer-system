import { StrictMode, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
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
    <div className="min-h-screen bg-white pt-32 pb-20 px-6">
      <div className="w-full max-w-5xl mx-auto box-border">
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
            className="space-y-3"
          >
            {config.orders.map((order, index) => (
              <motion.div
                key={order.id}
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: index * 0.05 }}
                className="bg-gray-50 rounded-2xl overflow-hidden"
              >
                {/* Order Header */}
                <div
                  className="p-5 flex items-center justify-between cursor-pointer hover:bg-gray-100/80 transition-colors"
                  onClick={() => toggleOrder(order.id)}
                >
                  <div className="flex items-center gap-5">
                    <div className="w-12 h-12 rounded-xl bg-white flex items-center justify-center shadow-sm">
                      <span className="text-lg">📦</span>
                    </div>
                    <div>
                      <div className="flex items-center gap-3">
                        <span className="font-semibold text-gray-900">Order #{order.number}</span>
                        <span className={`px-2.5 py-0.5 rounded-full text-xs font-medium ${getStatusStyle(order.status)}`}>
                          {order.status}
                        </span>
                      </div>
                      <p className="text-sm text-gray-500 mt-0.5">{order.date}</p>
                    </div>
                  </div>

                  <div className="flex items-center gap-4">
                    <span className="text-xl font-bold text-gray-900">${order.total.toFixed(2)}</span>
                    <motion.div
                      animate={{ rotate: expandedOrder === order.id ? 180 : 0 }}
                      className="w-8 h-8 rounded-full bg-white shadow-sm flex items-center justify-center"
                    >
                      <span className="text-gray-400 text-sm">▼</span>
                    </motion.div>
                  </div>
                </div>

                {/* Order Details */}
                <AnimatePresence>
                  {expandedOrder === order.id && (
                    <motion.div
                      initial={{ height: 0, opacity: 0 }}
                      animate={{ height: 'auto', opacity: 1 }}
                      exit={{ height: 0, opacity: 0 }}
                      transition={{ duration: 0.25, ease: 'easeInOut' }}
                      className="overflow-hidden"
                    >
                      <div className="px-5 pb-5">
                        <div className="bg-white rounded-xl p-4">
                          {/* Items List */}
                          <div className="space-y-3">
                            {order.items.map((item, itemIndex) => (
                              <div
                                key={itemIndex}
                                className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0"
                              >
                                <div className="flex-1">
                                  <p className="text-gray-900 font-medium">{item.name}</p>
                                  <p className="text-sm text-gray-500">Qty: {item.quantity}</p>
                                </div>
                                <span className="font-semibold text-gray-900">${item.total.toFixed(2)}</span>
                              </div>
                            ))}
                          </div>

                          {/* Total */}
                          <div className="mt-4 pt-4 border-t border-gray-200 flex justify-between items-center">
                            <span className="text-gray-500 font-medium">Total</span>
                            <span className="text-2xl font-bold text-gray-900">${order.total.toFixed(2)}</span>
                          </div>
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
