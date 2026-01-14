import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { motion } from 'framer-motion'
import LightRays from '@/components/backgrounds/LightRays'
import { Button } from '@/components/ui/Button'
import '@/index.css'

declare global {
  interface Window {
    dealerLogin: {
      loginUrl: string
      nonce: string
      redirect: string
    }
  }
}

function LoginPage() {
  const config = window.dealerLogin || {
    loginUrl: '/my-account/',
    nonce: '',
    redirect: '/'
  }

  return (
    <div className="min-h-screen relative flex items-center justify-center bg-black">
      {/* Animated Background */}
      <div className="fixed inset-0">
        <LightRays
          raysOrigin="top-center"
          raysColor="#374151"
          raysSpeed={0.5}
          lightSpread={1.2}
          rayLength={2.0}
          fadeDistance={1.5}
          saturation={0.3}
          followMouse={true}
          mouseInfluence={0.08}
        />
      </div>

      {/* Login Card */}
      <motion.div
        className="relative z-10 w-full max-w-sm mx-6"
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
      >
        <div className="bg-white/10 backdrop-blur-2xl rounded-3xl p-8 border border-white/10">
          {/* Logo / Title */}
          <motion.div
            className="text-center mb-8"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 0.2 }}
          >
            <img
              src="/wp-content/plugins/dealer-system/dist/ZEEKR_white.png"
              alt="ZEEKR"
              className="h-8 mx-auto mb-3"
            />
            <p className="text-gray-400 text-sm">Dealer Portal</p>
          </motion.div>

          {/* Login Form */}
          <form
            method="post"
            action={config.loginUrl}
            className="space-y-4"
          >
            <input type="hidden" name="woocommerce-login-nonce" value={config.nonce} />
            <input type="hidden" name="_wp_http_referer" value="/my-account/" />
            <input type="hidden" name="redirect" value={config.redirect} />
            <input type="hidden" name="login" value="1" />

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.3 }}
            >
              <input
                type="text"
                name="username"
                placeholder="Username or Email"
                required
                autoComplete="username"
                className="w-full h-11 rounded-xl bg-white/10 border border-white/10 px-4 text-sm text-white placeholder:text-gray-500 focus:bg-white/15 focus:border-white/20 focus:outline-none transition-all"
              />
            </motion.div>

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.4 }}
            >
              <input
                type="password"
                name="password"
                placeholder="Password"
                required
                autoComplete="current-password"
                className="w-full h-11 rounded-xl bg-white/10 border border-white/10 px-4 text-sm text-white placeholder:text-gray-500 focus:bg-white/15 focus:border-white/20 focus:outline-none transition-all"
              />
            </motion.div>

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.5 }}
              className="pt-4"
            >
              <Button
                type="submit"
                className="w-full h-11 text-sm font-medium bg-white text-black hover:bg-gray-100"
              >
                Sign In
              </Button>
            </motion.div>
          </form>

          {/* Footer */}
          <motion.p
            className="mt-8 text-center text-gray-500 text-xs"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 0.6 }}
          >
            Dealer Stock Management
          </motion.p>
        </div>
      </motion.div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('dealer-login-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <LoginPage />
    </StrictMode>
  )
}

export default LoginPage
