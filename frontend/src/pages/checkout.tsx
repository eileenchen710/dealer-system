import { StrictMode, useState, useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { motion } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import '@/index.css'

interface CartItem {
  key: string
  id: number
  name: string
  sku: string
  price: number
  quantity: number
  subtotal: number
  orderType: string
  orderTypeLabel: string
  isBackorder: boolean
  backorderOriginalPrice: number
}

declare global {
  interface Window {
    dealerCheckout: {
      items: CartItem[]
      total: number
      cartUrl: string
      nonce: string
      ajaxUrl: string
      placeOrderNonce: string
    }
  }
}

function CheckoutPage() {
  const config = window.dealerCheckout || {
    items: [],
    total: 0,
    cartUrl: '/cart/',
    nonce: '',
    ajaxUrl: '',
    placeOrderNonce: ''
  }

  const [items] = useState(config.items)
  const [placing, setPlacing] = useState(false)
  const [orderNotes, setOrderNotes] = useState('')
  const [poNumber, setPoNumber] = useState('')

  // Load PO Number from localStorage on mount
  useEffect(() => {
    const savedPoNumber = localStorage.getItem('dealer_po_number') || ''
    setPoNumber(savedPoNumber)
  }, [])

  // Split items into in-stock and backorder
  const inStockItems = items.filter(item => !item.isBackorder)
  const backorderItems = items.filter(item => item.isBackorder)

  // Only charge for in-stock items
  const total = inStockItems.reduce((sum, item) => sum + item.subtotal, 0)

  const getOrderTypeBadgeClass = (orderType: string) => {
    switch (orderType) {
      case 'stock_order':
        return 'bg-green-100 text-green-700'
      case 'daily_order':
        return 'bg-blue-100 text-blue-700'
      case 'vor_order':
        return 'bg-purple-100 text-purple-700'
      default:
        return 'bg-gray-100 text-gray-700'
    }
  }

  const handlePlaceOrder = async () => {
    if (!poNumber.trim()) {
      alert('Purchase Order Number is required. Please go back to cart.')
      window.location.href = config.cartUrl
      return
    }

    setPlacing(true)
    try {
      const formData = new FormData()
      formData.append('action', 'dealer_place_order')
      formData.append('nonce', config.placeOrderNonce)
      formData.append('order_notes', orderNotes)
      formData.append('po_number', poNumber.trim())

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        // Clear PO number from localStorage after successful order
        localStorage.removeItem('dealer_po_number')
        window.location.href = result.data.redirect || '/my-account/orders/'
      } else {
        alert('Failed to place order: ' + (result.data?.message || 'Unknown error'))
      }
    } catch (error) {
      console.error('Failed to place order:', error)
      alert('Failed to place order')
    } finally {
      setPlacing(false)
    }
  }

  if (items.length === 0) {
    return (
      <div className="page-container">
        <div className="page-content">
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
          >
            <div className="text-6xl mb-4">🛒</div>
            <h2 className="text-2xl font-bold text-gray-900 mb-2">Your cart is empty</h2>
            <p className="text-gray-500 mb-6">Add some products to checkout</p>
            <Button onClick={() => window.location.href = '/inventory/'}>
              Browse Inventory
            </Button>
          </motion.div>
        </div>
      </div>
    )
  }

  return (
    <div className="page-container">
      <div className="page-content">
        {/* Header */}
        <motion.div
          className="mb-8 text-center"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-4xl font-bold mb-2">
            <GradientText animationSpeed={4}>
              Checkout
            </GradientText>
          </h1>
          <p className="text-gray-500">Review and place your order</p>
        </motion.div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Order Items */}
          <motion.div
            className="lg:col-span-2"
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.1 }}
          >
            {/* In-Stock Order Items */}
            {inStockItems.length > 0 && (
              <div className="bg-white overflow-hidden p-8">
                <h2 className="text-xl font-semibold mb-6">Order Items</h2>
                <div>
                  {inStockItems.map((item, index) => (
                    <motion.div
                      key={item.key}
                      style={{ paddingTop: '20px', paddingBottom: '20px', borderBottom: '1px solid #f3f4f6' }}
                      className="flex items-center justify-between last:border-0"
                      initial={{ opacity: 0, x: -20 }}
                      animate={{ opacity: 1, x: 0 }}
                      transition={{ delay: index * 0.05 }}
                    >
                      <div className="flex-1">
                        <p className="font-semibold text-gray-900">Part Number: {item.sku}</p>
                        <p className="text-sm text-gray-500">{item.name}</p>
                        <span className={`inline-flex px-3! py-1! text-xs font-medium rounded-full mt-1 ${getOrderTypeBadgeClass(item.orderType)}`}>
                          {item.orderTypeLabel || 'Stock Order'}
                        </span>
                      </div>
                      <div className="text-right">
                        <p className="text-gray-600">{item.quantity} x ${item.price.toFixed(2)}</p>
                        <p className="font-semibold text-gray-900">${item.subtotal.toFixed(2)}</p>
                      </div>
                    </motion.div>
                  ))}
                </div>
              </div>
            )}

            {/* Back Order Items */}
            {backorderItems.length > 0 && (
              <div className="bg-white overflow-hidden p-8" style={{ marginTop: inStockItems.length > 0 ? '24px' : '0' }}>
                <h2 className="text-xl font-semibold mb-6" style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
                  Back Order Items
                  <span className="inline-flex text-xs font-medium bg-orange-100 text-orange-700" style={{ padding: '4px 12px', borderRadius: '9999px' }}>
                    Not Charged
                  </span>
                </h2>
                <div>
                  {backorderItems.map((item, index) => (
                    <motion.div
                      key={item.key}
                      style={{ paddingTop: '20px', paddingBottom: '20px', borderBottom: '1px solid #f3f4f6' }}
                      className="flex items-center justify-between last:border-0"
                      initial={{ opacity: 0, x: -20 }}
                      animate={{ opacity: 1, x: 0 }}
                      transition={{ delay: (inStockItems.length + index) * 0.05 }}
                    >
                      <div className="flex-1">
                        <p className="font-semibold text-gray-900">Part Number: {item.sku}</p>
                        <p className="text-sm text-gray-500">{item.name}</p>
                        <span className={`inline-flex px-3! py-1! text-xs font-medium rounded-full mt-1 ${getOrderTypeBadgeClass(item.orderType)}`}>
                          {item.orderTypeLabel || 'Stock Order'}
                        </span>
                      </div>
                      <div className="text-right">
                        <p className="text-gray-400 line-through text-sm">{item.quantity} x ${item.backorderOriginalPrice.toFixed(2)}</p>
                        <p className="font-semibold text-orange-600">$0.00</p>
                      </div>
                    </motion.div>
                  ))}
                </div>
              </div>
            )}

            {/* Order Notes */}
            <motion.div
              className="mt-6 bg-white overflow-hidden p-6"
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 }}
            >
              <h2 className="text-xl font-semibold mb-4">Order Notes (Optional)</h2>
              <textarea
                value={orderNotes}
                onChange={(e) => setOrderNotes(e.target.value)}
                placeholder="Add any special instructions for your order..."
                className="w-full h-24 px-4 py-3 text-sm border border-gray-200 rounded-xl bg-white focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent resize-none"
              />
            </motion.div>
          </motion.div>

          {/* Order Summary */}
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.3 }}
          >
            <div className="p-6 sticky top-32">
              <h2 className="text-xl font-semibold mb-4">Order Summary</h2>

              {/* PO Number Display */}
              <div className="mb-4 p-4 bg-gray-50 rounded-lg">
                <div className="flex justify-between items-center">
                  <span className="text-sm font-medium text-gray-700">PO Number</span>
                  <span className="text-sm font-semibold text-gray-900">{poNumber || '-'}</span>
                </div>
              </div>

              <div className="space-y-3">
                <div className="flex justify-between text-gray-600">
                  <span>Subtotal ({inStockItems.length} items, excl. GST)</span>
                  <span>${total.toFixed(2)}</span>
                </div>
                {backorderItems.length > 0 && (
                  <div className="flex justify-between text-orange-600">
                    <span>Back Orders ({backorderItems.length} items)</span>
                    <span>$0.00</span>
                  </div>
                )}
                <div className="flex justify-between text-gray-600">
                  <span>GST (10%)</span>
                  <span>${(total * 0.1).toFixed(2)}</span>
                </div>
              </div>

              <div style={{ borderTop: '1px solid #e5e7eb', marginTop: '24px', paddingTop: '24px' }}>
                <div className="flex justify-between items-center">
                  <span className="text-lg font-semibold">Total (incl. GST)</span>
                  <span className="text-2xl font-bold">
                    <GradientText animationSpeed={4}>
                      ${(total * 1.1).toFixed(2)}
                    </GradientText>
                  </span>
                </div>
              </div>

              <div style={{ marginTop: '32px', display: 'flex', flexDirection: 'column', gap: '12px' }}>
                <Button
                  className="w-full h-12 text-base"
                  onClick={handlePlaceOrder}
                  disabled={placing}
                >
                  {placing ? 'Placing Order...' : 'Place Order'}
                </Button>

                <Button
                  className="w-full h-12 text-base"
                  onClick={() => window.location.href = config.cartUrl}
                >
                  Back to Cart
                </Button>
              </div>
            </div>
          </motion.div>
        </div>
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('dealer-checkout-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <CheckoutPage />
    </StrictMode>
  )
}

export default CheckoutPage
