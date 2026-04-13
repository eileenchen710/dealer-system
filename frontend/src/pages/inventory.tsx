import { StrictMode, useState, useRef } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/Alert'
import { Dialog, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/Dialog'
import '@/index.css'

interface Product {
  id: number
  sku: string
  name: string
  stock: number
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
      searchNonce: string
      isWarehouseManager: boolean
    }
  }
}

function InventoryPage() {
  const config = window.dealerInventory || {
    products: [],
    cartUrl: '/cart/',
    nonce: '',
    ajaxUrl: '',
    addToCartNonce: '',
    searchNonce: '',
    isWarehouseManager: false
  }

  const isWarehouseManager = config.isWarehouseManager

  const [products, setProducts] = useState<Product[]>([])
  const [search, setSearch] = useState('')
  const [quantities, setQuantities] = useState<Record<number, number>>({})
  const [orderTypes, setOrderTypes] = useState<Record<number, OrderType>>({})
  const [addingToCart, setAddingToCart] = useState<number | null>(null)
  const [addedToCart, setAddedToCart] = useState<Set<number>>(new Set())
  const [loading, setLoading] = useState(false)
  const [total, setTotal] = useState(0)
  const [isSearching, setIsSearching] = useState(false)
  const [hasSearched, setHasSearched] = useState(false)
  const [alert, setAlert] = useState<{ show: boolean; product: string; quantity: number; error?: boolean; message?: string } | null>(null)
  const [backorderDialog, setBackorderDialog] = useState<{ open: boolean; product: Product | null; quantity: number; reason: 'out_of_stock' | 'exceeds_stock' }>({
    open: false,
    product: null,
    quantity: 0,
    reason: 'out_of_stock'
  })
  const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const abortControllerRef = useRef<AbortController | null>(null)
  const searchRequestIdRef = useRef(0)

  // Fetch products from server
  const fetchProducts = async (searchTerm: string) => {
    // Cancel previous request
    if (abortControllerRef.current) {
      abortControllerRef.current.abort()
    }

    // Create new abort controller
    const abortController = new AbortController()
    abortControllerRef.current = abortController

    // Track this request
    const requestId = ++searchRequestIdRef.current
    setLoading(true)

    try {
      const formData = new FormData()
      formData.append('action', 'dealer_search_products')
      formData.append('nonce', config.searchNonce)
      formData.append('search', searchTerm)
      formData.append('page', '1')

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
        signal: abortController.signal,
      })

      const result = await response.json()

      // Only update if this is still the latest request
      if (requestId !== searchRequestIdRef.current) {
        return
      }

      if (result.success) {
        setProducts(result.data.products)
        setTotal(result.data.total)
      }
    } catch (error) {
      // Ignore abort errors
      if (error instanceof Error && error.name === 'AbortError') {
        return
      }
      console.error('Failed to fetch products:', error)
    } finally {
      // Only update loading state if this is still the latest request
      if (requestId === searchRequestIdRef.current) {
        setLoading(false)
        setIsSearching(false)
      }
    }
  }

  // Handle search with debounce
  const handleSearchChange = (value: string) => {
    setSearch(value)

    // Clear previous timeout
    if (searchTimeoutRef.current) {
      clearTimeout(searchTimeoutRef.current)
    }

    // Only search if there's a search term
    if (value.trim()) {
      setIsSearching(true)
      // Debounce search - 500ms for better UX
      searchTimeoutRef.current = setTimeout(() => {
        setHasSearched(true)
        fetchProducts(value)
      }, 500)
    } else {
      // Clear results if search is empty
      setProducts([])
      setHasSearched(false)
      setIsSearching(false)
      setTotal(0)
    }
  }

  const handleQuantityChange = (productId: number, value: number) => {
    setQuantities(prev => ({ ...prev, [productId]: value }))
  }

  const handleOrderTypeChange = (productId: number, value: OrderType) => {
    setOrderTypes(prev => ({ ...prev, [productId]: value }))
  }

  // Actually add to cart (called directly or after backorder confirmation)
  const addToCartRequest = async (product: Product, quantity: number, isBackorder: boolean = false, originalPrice?: number) => {
    const orderType = orderTypes[product.id] || 'stock_order'

    try {
      const formData = new FormData()
      formData.append('action', 'dealer_add_to_cart')
      formData.append('nonce', config.addToCartNonce)
      formData.append('product_id', String(product.id))
      formData.append('quantity', String(quantity))
      formData.append('order_type', orderType)

      if (isBackorder) {
        formData.append('is_backorder', '1')
        formData.append('backorder_original_price', String(originalPrice || product.prices[orderType]))
      }

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        return { success: true }
      } else {
        console.error('Failed to add to cart:', result.data?.message)
        return { success: false, message: result.data?.message || 'Unknown error' }
      }
    } catch (error) {
      console.error('Failed to add to cart:', error)
      return { success: false, message: 'Network error' }
    }
  }

  // Add to cart with auto-split for backorder
  const handleAddToCartWithSplit = async (product: Product, quantity: number) => {
    const orderType = orderTypes[product.id] || 'stock_order'
    const originalPrice = product.prices[orderType]
    setAddingToCart(product.id)

    try {
      // Ensure inStockQty is never negative (when stock is negative)
      const inStockQty = Math.max(0, Math.min(quantity, product.stock))
      const backorderQty = quantity - inStockQty

      let success = true
      let errorMessage = ''

      // Add in-stock portion (if any)
      if (inStockQty > 0) {
        const result = await addToCartRequest(product, inStockQty, false)
        if (!result.success) {
          success = false
          errorMessage = result.message || 'Failed to add in-stock items'
        }
      }

      // Add backorder portion (if any)
      if (success && backorderQty > 0) {
        const result = await addToCartRequest(product, backorderQty, true, originalPrice)
        if (!result.success) {
          success = false
          errorMessage = result.message || 'Failed to add backorder items'
        }
      }

      if (success) {
        setAddedToCart(prev => new Set(prev).add(product.id))
        const message = backorderQty > 0
          ? `${inStockQty > 0 ? `${inStockQty} in stock + ` : ''}${backorderQty} back order`
          : `${quantity}`
        setAlert({ show: true, product: product.name, quantity, message })
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, product: product.name, quantity, error: true, message: errorMessage })
        setTimeout(() => setAlert(null), 4000)
      }
    } finally {
      setAddingToCart(null)
    }
  }

  // Handle add to cart button click - check stock first
  const handleAddToCart = (product: Product) => {
    const quantity = quantities[product.id] || 1

    // Check if out of stock or quantity exceeds stock - show confirmation dialog
    if (product.stock <= 0) {
      setBackorderDialog({
        open: true,
        product,
        quantity,
        reason: 'out_of_stock'
      })
      return
    }

    if (quantity > product.stock) {
      setBackorderDialog({
        open: true,
        product,
        quantity,
        reason: 'exceeds_stock'
      })
      return
    }

    // Stock is sufficient, add directly
    handleAddToCartWithSplit(product, quantity)
  }

  // Confirm backorder - uses auto-split logic
  const handleConfirmBackorder = () => {
    if (backorderDialog.product) {
      handleAddToCartWithSplit(backorderDialog.product, backorderDialog.quantity)
    }
    setBackorderDialog({ open: false, product: null, quantity: 0, reason: 'out_of_stock' })
  }

  const showCenteredSearch = !hasSearched && !isSearching && products.length === 0

  return (
    <div className="page-container">
      <div className="page-content" style={{ paddingTop: '120px' }}>
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
                  <AlertTitle>{alert.error ? 'Error' : 'Added to Cart'}</AlertTitle>
                  <AlertDescription>
                    {alert.error ? alert.message : (alert.message || `${alert.quantity}x ${alert.product}`)}
                  </AlertDescription>
                </div>
              </Alert>
            </motion.div>
          )}
        </AnimatePresence>

        {/* Backorder Confirmation Dialog */}
        <Dialog
          open={backorderDialog.open}
          onOpenChange={(open) => setBackorderDialog(prev => ({ ...prev, open }))}
        >
          <DialogHeader>
            <DialogTitle>Confirm Backorder</DialogTitle>
            <DialogDescription>
              {backorderDialog.reason === 'out_of_stock' ? (
                <>
                  <strong>{backorderDialog.product?.name}</strong> is currently out of stock.
                  <br />
                  This will be placed as a <strong>backorder</strong> and fulfilled when stock becomes available.
                </>
              ) : (
                <>
                  You are ordering <strong>{backorderDialog.quantity}</strong> units of <strong>{backorderDialog.product?.name}</strong>,
                  but only <strong>{backorderDialog.product?.stock}</strong> units are in stock.
                  <br />
                  The remaining <strong>{backorderDialog.quantity - (backorderDialog.product?.stock || 0)}</strong> units will be placed as a <strong>backorder</strong>.
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              onClick={() => setBackorderDialog({ open: false, product: null, quantity: 0, reason: 'out_of_stock' })}
              style={{ background: '#f3f4f6', color: '#374151' }}
            >
              Cancel
            </Button>
            <Button onClick={handleConfirmBackorder}>
              Confirm Backorder
            </Button>
          </DialogFooter>
        </Dialog>

        {/* Search Section - Centered when no results */}
        <div className={showCenteredSearch ? "fixed inset-0 flex items-center justify-center" : ""}>
          <div className={showCenteredSearch ? "w-full max-w-md px-4" : ""}>
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
              className="!mb-4 flex justify-center"
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.1 }}
            >
              <Input
                type="text"
                placeholder="Search by Part number or product name..."
                value={search}
                onChange={(e) => handleSearchChange(e.target.value)}
                className="max-w-md w-full !rounded-full"
              />
            </motion.div>

            {/* Initial State Hint */}
            {showCenteredSearch && (
              <motion.div
                className="text-center mt-6"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <p className="text-gray-400 text-base flex items-center justify-center gap-2">
                  <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                  </svg>
                  Enter Part number or product name to search
                </p>
              </motion.div>
            )}
          </div>
        </div>

        {/* Loading State */}
        {(loading || isSearching) && (
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
            <p className="text-gray-500">Searching...</p>
          </motion.div>
        )}

        {/* Search Results */}
        {hasSearched && !loading && !isSearching && (
          <>
            {products.length > 0 ? (
              <>
                {/* Products Table */}
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
                        <TableHead>Description</TableHead>
                        <TableHead className="text-right">Qty</TableHead>
                        {!isWarehouseManager && <TableHead className="text-right">Stock Price<br/><span className="text-xs font-normal text-gray-400">(Excl. GST)</span></TableHead>}
                        {!isWarehouseManager && <TableHead className="text-right">Daily Price<br/><span className="text-xs font-normal text-gray-400">(Excl. GST)</span></TableHead>}
                        {!isWarehouseManager && <TableHead className="text-right">VOR Price<br/><span className="text-xs font-normal text-gray-400">(Excl. GST)</span></TableHead>}
                        {!isWarehouseManager && <TableHead>Type</TableHead>}
                        {!isWarehouseManager && <TableHead className="text-right">Order</TableHead>}
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      <AnimatePresence>
                        {products.map((product, index) => {
                          const selectedType = orderTypes[product.id] || 'stock_order'
                          const isAdded = addedToCart.has(product.id)
                          const isOutOfStock = product.stock <= 0
                          return (
                            <motion.tr
                              key={product.id}
                              initial={{ opacity: 0, y: 10 }}
                              animate={{ opacity: 1, y: 0 }}
                              exit={{ opacity: 0, y: -10 }}
                              transition={{ delay: index * 0.02 }}
                              className={`border-b border-gray-100 transition-colors ${isAdded ? 'bg-green-50' : 'hover:bg-gray-50'}`}
                            >
                              <TableCell className="font-mono text-gray-600">
                                {product.sku || '-'}
                              </TableCell>
                              <TableCell className="font-medium text-gray-900">{product.name}</TableCell>
                              <TableCell className="text-right text-gray-600">
                                {product.stock}
                              </TableCell>
                              {!isWarehouseManager && (
                                <TableCell className="text-right text-gray-600">
                                  ${product.prices.stock_order.toFixed(2)}
                                </TableCell>
                              )}
                              {!isWarehouseManager && (
                                <TableCell className="text-right text-gray-600">
                                  ${product.prices.daily_order.toFixed(2)}
                                </TableCell>
                              )}
                              {!isWarehouseManager && (
                                <TableCell className="text-right text-gray-600">
                                  ${product.prices.vor_order.toFixed(2)}
                                </TableCell>
                              )}
                              {!isWarehouseManager && (
                                <TableCell>
                                  <select
                                    value={selectedType}
                                    onChange={(e) => handleOrderTypeChange(product.id, e.target.value as OrderType)}
                                    className="h-10 px-3 py-2 text-sm border border-gray-200 rounded-md bg-white focus:outline-none focus:ring-2 focus:ring-black focus:border-transparent"
                                  >
                                    {Object.entries(ORDER_TYPE_LABELS).map(([value, label]) => (
                                      <option key={value} value={value}>
                                        {label}
                                      </option>
                                    ))}
                                  </select>
                                </TableCell>
                              )}
                              {!isWarehouseManager && (
                                <TableCell className="text-right">
                                  <div className="flex items-center justify-end gap-2">
                                    <Input
                                      type="number"
                                      min={1}
                                      value={quantities[product.id] || 1}
                                      onChange={(e) => handleQuantityChange(product.id, parseInt(e.target.value) || 1)}
                                      className="w-20 h-8 text-center"
                                    />
                                    <Button
                                      size="sm"
                                      onClick={() => handleAddToCart(product)}
                                      disabled={addingToCart === product.id}
                                      style={{ width: '90px' }}
                                    >
                                      {addingToCart === product.id ? '...' : (isOutOfStock ? 'Back order' : 'Add')}
                                    </Button>
                                  </div>
                                </TableCell>
                              )}
                            </motion.tr>
                          )
                        })}
                      </AnimatePresence>
                    </TableBody>
                  </Table>
                </motion.div>

                {/* Results Count */}
                <motion.div
                  className="mt-6 text-center"
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                >
                  <p className="text-sm text-gray-400">
                    Found {total} product{total !== 1 ? 's' : ''}
                  </p>
                </motion.div>
              </>
            ) : (
              /* No Results */
              <motion.div
                className="text-center py-12"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <p className="text-gray-500">
                  No products found matching "{search}"
                </p>
              </motion.div>
            )}
          </>
        )}
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
