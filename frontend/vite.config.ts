import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: {
        login: path.resolve(__dirname, 'src/pages/login.tsx'),
        inventory: path.resolve(__dirname, 'src/pages/inventory.tsx'),
        cart: path.resolve(__dirname, 'src/pages/cart.tsx'),
        orders: path.resolve(__dirname, 'src/pages/orders.tsx'),
        checkout: path.resolve(__dirname, 'src/pages/checkout.tsx'),
        account: path.resolve(__dirname, 'src/pages/account.tsx'),
        'warehouse-orders': path.resolve(__dirname, 'src/pages/warehouse-orders.tsx'),
        'warehouse-order-detail': path.resolve(__dirname, 'src/pages/warehouse-order-detail.tsx'),
        'zeekr-orders': path.resolve(__dirname, 'src/pages/zeekr-orders.tsx'),
        'zeekr-inventory': path.resolve(__dirname, 'src/pages/zeekr-inventory.tsx'),
        'zeekr-dealers': path.resolve(__dirname, 'src/pages/zeekr-dealers.tsx'),
        'zeekr-analytics': path.resolve(__dirname, 'src/pages/zeekr-analytics.tsx'),
      },
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/chunks/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'css/style.css'
          }
          return 'assets/[name]-[hash][extname]'
        },
      },
    },
  },
})
