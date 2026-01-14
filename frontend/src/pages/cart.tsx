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
}

declare global {
  interface Window {
    dealerCart: {
      items: CartItem[]
      total: number
      checkoutUrl: string
      updateCartUrl: string
      nonce: string
    }
  }
}

function CartPage() {
  const config = window.dealerCart || {
    items: [],
    total: 0,
    checkoutUrl: '/checkout/',
    updateCartUrl: '',
    nonce: ''
  }

  const [items, setItems] = useState(config.items)
  const [updating, setUpdating] = useState<string | null>(null)
  const [removing, setRemoving] = useState<string | null>(null)

  const total = items.reduce((sum, item) => sum + item.subtotal, 0)

  const handleQuantityChange = (key: string, newQuantity: number) => {
    if (newQuantity < 1) return

    setItems(prev =>
      prev.map(item =>
        item.key === key
          ? { ...item, quantity: newQuantity, subtotal: item.price * newQuantity }
          : item
      )
    )
  }

  const handleUpdateCart = async () => {
    setUpdating('all')
    try {
      window.location.reload()
    } catch (error) {
      console.error('Failed to update cart:', error)
    } finally {
      setUpdating(null)
    }
  }

  const handleRemoveItem = async (key: string) => {
    setRemoving(key)
    try {
      setItems(prev => prev.filter(item => item.key !== key))
    } catch (error) {
      console.error('Failed to remove item:', error)
    } finally {
      setRemoving(null)
    }
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
              className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm"
            >
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Product</TableHead>
                    <TableHead>SKU</TableHead>
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
                        <TableCell className="font-medium text-gray-900">{item.name}</TableCell>
                        <TableCell className="font-mono text-gray-600">{item.sku}</TableCell>
                        <TableCell className="text-right text-gray-700">${item.price.toFixed(2)}</TableCell>
                        <TableCell>
                          <div className="flex items-center justify-center">
                            <Input
                              type="number"
                              min={1}
                              value={item.quantity}
                              onChange={(e) => handleQuantityChange(item.key, parseInt(e.target.value) || 1)}
                              className="w-20 h-8 text-center"
                            />
                          </div>
                        </TableCell>
                        <TableCell className="text-right font-semibold text-gray-900">
                          ${item.subtotal.toFixed(2)}
                        </TableCell>
                        <TableCell>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => handleRemoveItem(item.key)}
                            disabled={removing === item.key}
                            className="text-red-600 hover:text-red-700 hover:bg-red-50"
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
              <Button
                variant="secondary"
                onClick={handleUpdateCart}
                disabled={updating === 'all'}
              >
                {updating === 'all' ? 'Updating...' : 'Update Cart'}
              </Button>

              <div className="bg-gray-50 border border-gray-200 rounded-xl p-6 min-w-[300px]">
                <div className="flex justify-between items-center mb-4">
                  <span className="text-gray-500">Total</span>
                  <span className="text-3xl font-bold">
                    <GradientText animationSpeed={4}>
                      ${total.toFixed(2)}
                    </GradientText>
                  </span>
                </div>
                <Button
                  className="w-full h-12 text-base"
                  onClick={() => window.location.href = config.checkoutUrl}
                >
                  Proceed to Checkout
                </Button>
              </div>
            </motion.div>
          </>
        ) : (
          /* Empty Cart */
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0, scale: 0.9 }}
            animate={{ opacity: 1, scale: 1 }}
          >
            <div className="text-6xl mb-4">🛒</div>
            <h2 className="text-2xl font-bold text-gray-900 mb-2">Your cart is empty</h2>
            <p className="text-gray-500 mb-6">Add some products to get started</p>
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
const container = document.getElementById('dealer-cart-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <CartPage />
    </StrictMode>
  )
}

export default CartPage
