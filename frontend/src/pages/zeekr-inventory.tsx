import { StrictMode, useState, useRef } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
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

declare global {
  interface Window {
    zeekrInventory: {
      ajaxUrl: string
      nonce: string
    }
  }
}

function ZeekrInventoryPage() {
  const config = window.zeekrInventory || {
    ajaxUrl: '',
    nonce: '',
  }

  const [products, setProducts] = useState<Product[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(false)
  const [total, setTotal] = useState(0)
  const [isSearching, setIsSearching] = useState(false)
  const [hasSearched, setHasSearched] = useState(false)
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
      formData.append('action', 'zeekr_get_inventory')
      formData.append('nonce', config.nonce)
      formData.append('search', searchTerm)

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

  const showCenteredSearch = !hasSearched && !isSearching && products.length === 0

  return (
    <div className="page-container">
      <div className="page-content" style={{ paddingTop: '120px' }}>
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
              <p className="text-gray-500">View product inventory (Read-only)</p>
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
                        <TableHead className="text-right">Stock Price<br/><span className="text-xs font-normal text-gray-400">(Excl. GST)</span></TableHead>
                        <TableHead className="text-right">Daily Price<br/><span className="text-xs font-normal text-gray-400">(Excl. GST)</span></TableHead>
                        <TableHead className="text-right">VOR Price<br/><span className="text-xs font-normal text-gray-400">(Excl. GST)</span></TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      <AnimatePresence>
                        {products.map((product, index) => (
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
                            <TableCell className="text-right text-gray-600">
                              {product.stock}
                            </TableCell>
                            <TableCell className="text-right text-gray-600">
                              ${product.prices.stock_order.toFixed(2)}
                            </TableCell>
                            <TableCell className="text-right text-gray-600">
                              ${product.prices.daily_order.toFixed(2)}
                            </TableCell>
                            <TableCell className="text-right text-gray-600">
                              ${product.prices.vor_order.toFixed(2)}
                            </TableCell>
                          </motion.tr>
                        ))}
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
const container = document.getElementById('zeekr-inventory-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <ZeekrInventoryPage />
    </StrictMode>
  )
}

export default ZeekrInventoryPage
