import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { motion } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import '@/index.css'

function OrdersHeader() {
  return (
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
  )
}

// Mount the app to the header container (separate from table)
const container = document.getElementById('dealer-orders-header')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <OrdersHeader />
    </StrictMode>
  )
}

export default OrdersHeader
