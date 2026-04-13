import { StrictMode, useState } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
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
    dealerCart: {
      items: CartItem[]
      total: number
      checkoutUrl: string
      updateCartUrl: string
      nonce: string
      ajaxUrl: string
      cartActionNonce: string
    }
  }
}

function CartPage() {
  const config = window.dealerCart || {
    items: [],
    total: 0,
    checkoutUrl: '/checkout/',
    updateCartUrl: '',
    nonce: '',
    ajaxUrl: '',
    cartActionNonce: ''
  }

  const [items, setItems] = useState(config.items)
  const [updating, setUpdating] = useState<string | null>(null)
  const [removing, setRemoving] = useState<string | null>(null)
  const [poNumber, setPoNumber] = useState(() => {
    return localStorage.getItem('dealer_po_number') || ''
  })
  const [poError, setPoError] = useState('')

  const total = items.reduce((sum, item) => sum + item.subtotal, 0)

  const handleQuantityChange = async (key: string, newQuantity: number) => {
    if (newQuantity < 1) return

    // Update local state immediately
    setItems(prev =>
      prev.map(item =>
        item.key === key
          ? { ...item, quantity: newQuantity, subtotal: item.price * newQuantity }
          : item
      )
    )

    // Update server
    setUpdating(key)
    try {
      const formData = new FormData()
      formData.append('action', 'dealer_update_cart_item')
      formData.append('nonce', config.cartActionNonce)
      formData.append('cart_item_key', key)
      formData.append('quantity', String(newQuantity))

      await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })
    } catch (error) {
      console.error('Failed to update cart:', error)
    } finally {
      setUpdating(null)
    }
  }

  const handleUpdateCart = async () => {
    setUpdating('all')
    window.location.reload()
  }

  const handlePoNumberChange = (value: string) => {
    setPoNumber(value)
    setPoError('')
    localStorage.setItem('dealer_po_number', value)
  }

  const handleProceedToCheckout = () => {
    if (!poNumber.trim()) {
      setPoError('Purchase Order Number is required')
      return
    }
    localStorage.setItem('dealer_po_number', poNumber.trim())
    window.location.href = config.checkoutUrl
  }

  const handleRemoveItem = async (key: string) => {
    setRemoving(key)
    try {
      const formData = new FormData()
      formData.append('action', 'dealer_remove_from_cart')
      formData.append('nonce', config.cartActionNonce)
      formData.append('cart_item_key', key)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setItems(prev => prev.filter(item => item.key !== key))
      } else {
        console.error('Failed to remove item:', result.data?.message)
      }
    } catch (error) {
      console.error('Failed to remove item:', error)
    } finally {
      setRemoving(null)
    }
  }

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
              Shopping Cart
            </GradientText>
          </h1>
          <p className="text-gray-500">Review your order before checkout</p>
        </motion.div>

        {items.length > 0 ? (
          <>
            {/* Cart Table */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.1 }}
              className="bg-white overflow-hidden"
            >
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Part Number</TableHead>
                    <TableHead>Product</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead className="text-right">Price</TableHead>
                    <TableHead className="text-center">Quantity</TableHead>
                    <TableHead className="text-right">Subtotal</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <AnimatePresence>
                    {items.map((item, index) => (
                      <motion.tr
                        key={item.key}
                        initial={{ opacity: 0, x: -20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: 20 }}
                        transition={{ delay: index * 0.05 }}
                        className="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                      >
                        <TableCell className="font-mono text-gray-600">{item.sku}</TableCell>
                        <TableCell className="font-medium text-gray-900">
                          <div className="flex items-center gap-2">
                            <span>{item.name}</span>
                            {item.isBackorder && (
                              <span className="inline-flex text-xs font-medium bg-orange-100 text-orange-700" style={{ padding: '2px 8px', borderRadius: '9999px' }}>
                                Back Order
                              </span>
                            )}
                          </div>
                        </TableCell>
                        <TableCell>
                          <span className={`inline-flex px-3! py-1! text-xs font-medium rounded-full ${getOrderTypeBadgeClass(item.orderType)}`}>
                            {item.orderTypeLabel || 'Stock Order'}
                          </span>
                        </TableCell>
                        <TableCell className="text-right text-gray-700">
                          {item.isBackorder ? (
                            <span className="text-orange-600 font-medium">$0 (Back Order)</span>
                          ) : (
                            `$${item.price.toFixed(2)}`
                          )}
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center justify-center">
                            <Input
                              type="number"
                              min={1}
                              value={item.quantity}
                              onChange={(e) => handleQuantityChange(item.key, parseInt(e.target.value) || 1)}
                              className="w-20 h-8 text-center"
                              disabled={item.isBackorder}
                            />
                          </div>
                        </TableCell>
                        <TableCell className="text-right font-semibold text-gray-900">
                          {item.isBackorder ? (
                            <span className="text-orange-600">$0.00</span>
                          ) : (
                            `$${item.subtotal.toFixed(2)}`
                          )}
                        </TableCell>
                        <TableCell>
                          <Button
                            size="sm"
                            onClick={() => handleRemoveItem(item.key)}
                            disabled={removing === item.key}
                          >
                            {removing === item.key ? '...' : 'Remove'}
                          </Button>
                        </TableCell>
                      </motion.tr>
                    ))}
                  </AnimatePresence>
                </TableBody>
              </Table>
            </motion.div>

            {/* Cart Summary */}
            <motion.div
              className="mt-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-6"
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 }}
            >
              <div className="flex flex-col sm:flex-row items-start sm:items-center gap-4">
                <Button
                  onClick={handleUpdateCart}
                  disabled={updating === 'all'}
                >
                  {updating === 'all' ? 'Updating...' : 'Update Cart'}
                </Button>

                <div className="flex flex-col">
                  <div className="flex items-center gap-2">
                    <label htmlFor="po-number" className="text-sm font-medium text-gray-700 whitespace-nowrap">
                      PO Number <span className="text-red-500">*</span>
                    </label>
                    <Input
                      id="po-number"
                      type="text"
                      value={poNumber}
                      onChange={(e) => handlePoNumberChange(e.target.value)}
                      placeholder="Enter PO Number"
                      className={`w-48 h-10 ${poError ? 'border-red-500 focus:ring-red-500' : ''}`}
                    />
                  </div>
                  {poError && (
                    <p className="text-red-500 text-xs mt-1">{poError}</p>
                  )}
                </div>
              </div>

              <div className="rounded-xl p-6 min-w-75 space-y-4">
                <div className="flex justify-between items-center">
                  <span className="text-gray-500">Subtotal (excl. GST)</span>
                  <span className="text-lg font-semibold text-gray-700">
                    ${total.toFixed(2)}
                  </span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-gray-500">GST (10%)</span>
                  <span className="text-lg font-semibold text-gray-700">
                    ${(total * 0.1).toFixed(2)}
                  </span>
                </div>
                <div className="flex justify-between items-center pt-2 border-t border-gray-200">
                  <span className="text-gray-700 font-medium">Total (incl. GST)</span>
                  <span className="text-3xl font-bold">
                    <GradientText animationSpeed={4}>
                      ${(total * 1.1).toFixed(2)}
                    </GradientText>
                  </span>
                </div>
                <Button
                  className="w-full h-12 text-base mt-2"
                  onClick={handleProceedToCheckout}
                >
                  Proceed to Checkout
                </Button>
              </div>
            </motion.div>
          </>
        ) : (
          /* Empty Cart */
          <motion.div
            className="text-center py-12"
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
          >
            <div className="text-4xl mb-3">🛒</div>
            <h2 className="text-xl font-bold text-gray-900 mb-2">Your cart is empty</h2>
            <p className="text-gray-500 text-sm mb-4">Add some products to get started</p>
            <Button onClick={() => window.location.href = '/inventory/'}>
              Browse Inventory
            </Button>
          </motion.div>
        )}
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('dealer-cart-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <CartPage />
    </StrictMode>
  )
}

export default CartPage
