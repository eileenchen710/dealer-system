import { StrictMode, useState, useMemo } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
import '@/index.css'

interface Product {
  id: number
  sku: string
  name: string
  stock: number
  category: string
  prices: {
    stock_order: number
    daily_order: number
    vor_order: number
  }
}

type OrderType = 'stock_order' | 'daily_order' | 'vor_order'

const ORDER_TYPE_LABELS: Record<OrderType, string> = {
  stock_order: 'Stock Order',
  daily_order: 'Daily Order',
  vor_order: 'VOR Order',
}

declare global {
  interface Window {
    dealerInventory: {
      products: Product[]
      cartUrl: string
      nonce: string
      ajaxUrl: string
      addToCartNonce: string
    }
  }
}

function InventoryPage() {
  const config = window.dealerInventory || {
    products: [],
    cartUrl: '/cart/',
    nonce: '',
    ajaxUrl: '',
    addToCartNonce: ''
  }

  const [search, setSearch] = useState('')
  const [quantities, setQuantities] = useState<Record<number, number>>({})
  const [orderTypes, setOrderTypes] = useState<Record<number, OrderType>>({})
  const [addingToCart, setAddingToCart] = useState<number | null>(null)

  const filteredProducts = useMemo(() => {
    if (!search) return config.products
    const term = search.toLowerCase()
    return config.products.filter(
      p => p.sku.toLowerCase().includes(term) || p.name.toLowerCase().includes(term)
    )
  }, [config.products, search])

  const getStockStatus = (stock: number) => {
    if (stock <= 0) return { text: 'Out of Stock', className: 'text-red-600' }
    if (stock <= 10) return { text: 'Low Stock', className: 'text-amber-600' }
    return { text: 'In Stock', className: 'text-green-600' }
  }

  const handleQuantityChange = (productId: number, value: number) => {
    setQuantities(prev => ({ ...prev, [productId]: value }))
  }

  const handleOrderTypeChange = (productId: number, value: OrderType) => {
    setOrderTypes(prev => ({ ...prev, [productId]: value }))
  }

  const handleAddToCart = async (product: Product) => {
    const quantity = quantities[product.id] || 1
    const orderType = orderTypes[product.id] || 'stock_order'
    setAddingToCart(product.id)

    try {
      const formData = new FormData()
      formData.append('action', 'dealer_add_to_cart')
      formData.append('nonce', config.addToCartNonce)
      formData.append('product_id', String(product.id))
      formData.append('quantity', String(quantity))
      formData.append('order_type', orderType)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        // Reload to update cart count
        window.location.reload()
      } else {
        console.error('Failed to add to cart:', result.data?.message)
        alert('Failed to add to cart: ' + (result.data?.message || 'Unknown error'))
      }
    } catch (error) {
      console.error('Failed to add to cart:', error)
      alert('Failed to add to cart')
    } finally {
      setAddingToCart(null)
    }
  }

  return (
    <div className="min-h-screen bg-white pb-20">
      <div className="w-full box-border">
        {/* Header */}
        <motion.div
          className="mb-8 text-center"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-4xl font-bold mb-2">
            <GradientText animationSpeed={4}>
              Inventory
            </GradientText>
          </h1>
          <p className="text-gray-500">Browse and order products</p>
        </motion.div>

        {/* Search */}
        <motion.div
          className="mb-8 flex justify-center"
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.1 }}
        >
          <Input
            type="text"
            placeholder="Search by SKU or product name..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="max-w-md w-full"
          />
        </motion.div>

        {/* Products Table */}
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.2 }}
          className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm"
        >
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>SKU</TableHead>
                <TableHead>Product</TableHead>
                <TableHead>Category</TableHead>
                <TableHead className="text-right">Stock</TableHead>
                <TableHead>Status</TableHead>
                <TableHead>Type</TableHead>
                <TableHead className="text-right">Order</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <AnimatePresence>
                {filteredProducts.map((product, index) => {
                  const status = getStockStatus(product.stock)
                  const selectedType = orderTypes[product.id] || 'stock_order'
                  return (
                    <motion.tr
                      key={product.id}
                      initial={{ opacity: 0, y: 10 }}
                      animate={{ opacity: 1, y: 0 }}
                      exit={{ opacity: 0, y: -10 }}
                      transition={{ delay: index * 0.02 }}
                      className="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                    >
                      <TableCell className="font-mono text-gray-600">
                        {product.sku || '-'}
                      </TableCell>
                      <TableCell className="font-medium text-gray-900">{product.name}</TableCell>
                      <TableCell className="text-gray-500">{product.category}</TableCell>
                      <TableCell className="text-right text-gray-700">{product.stock ?? 'N/A'}</TableCell>
                      <TableCell>
                        <span className={`font-medium ${status.className}`}>
                          {status.text}
                        </span>
                      </TableCell>
                      <TableCell>
                        <select
                          value={selectedType}
                          onChange={(e) => handleOrderTypeChange(product.id, e.target.value as OrderType)}
                          className="h-8 px-2 text-sm border border-gray-200 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent"
                        >
                          {Object.entries(ORDER_TYPE_LABELS).map(([value, label]) => (
                            <option key={value} value={value}>
                              {label}
                            </option>
                          ))}
                        </select>
                      </TableCell>
                      <TableCell className="text-right">
                        {product.stock > 0 ? (
                          <div className="flex items-center justify-end gap-2">
                            <Input
                              type="number"
                              min={1}
                              max={product.stock}
                              value={quantities[product.id] || 1}
                              onChange={(e) => handleQuantityChange(product.id, parseInt(e.target.value) || 1)}
                              className="w-20 h-8 text-center"
                            />
                            <Button
                              size="sm"
                              onClick={() => handleAddToCart(product)}
                              disabled={addingToCart === product.id}
                            >
                              {addingToCart === product.id ? '...' : 'Add'}
                            </Button>
                          </div>
                        ) : (
                          <span className="text-gray-400">N/A</span>
                        )}
                      </TableCell>
                    </motion.tr>
                  )
                })}
              </AnimatePresence>
            </TableBody>
          </Table>
        </motion.div>

        {/* Empty State */}
        {filteredProducts.length === 0 && (
          <motion.div
            className="text-center py-12"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <p className="text-gray-500">No products found matching "{search}"</p>
          </motion.div>
        )}

        {/* Stats */}
        <motion.div
          className="mt-6 text-sm text-gray-400 text-center"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.3 }}
        >
          Showing {filteredProducts.length} of {config.products.length} products
        </motion.div>
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('dealer-inventory-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <InventoryPage />
    </StrictMode>
  )
}

export default InventoryPage
