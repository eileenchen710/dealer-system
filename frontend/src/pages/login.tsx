import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { motion } from 'framer-motion'
import LightRays from '@/components/backgrounds/LightRays'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
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
    <div className="min-h-screen relative flex items-center justify-center bg-white">
      {/* Animated Background */}
      <div className="fixed inset-0">
        <LightRays
          raysOrigin="top-center"
          raysColor="#d1d5db"
          raysSpeed={0.6}
          lightSpread={1.5}
          rayLength={2.5}
          fadeDistance={1.5}
          saturation={0.4}
          followMouse={true}
          mouseInfluence={0.1}
        />
      </div>

      {/* Login Card */}
      <motion.div
        className="relative z-10 w-full max-w-sm mx-6"
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
      >
        <div className="bg-white/70 backdrop-blur-2xl rounded-3xl p-8 shadow-[0_8px_32px_rgba(0,0,0,0.08)]">
          {/* Logo / Title */}
          <motion.div
            className="text-center mb-8"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ delay: 0.2 }}
          >
            <h1 className="text-2xl font-semibold mb-1">
              <GradientText animationSpeed={4}>
                ZEEKR Dealer
              </GradientText>
            </h1>
            <p className="text-gray-400 text-sm">Sign in to continue</p>
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
              <Input
                type="text"
                name="username"
                placeholder="Username or Email"
                required
                autoComplete="username"
              />
            </motion.div>

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.4 }}
            >
              <Input
                type="password"
                name="password"
                placeholder="Password"
                required
                autoComplete="current-password"
              />
            </motion.div>

            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.5 }}
              className="pt-2"
            >
              <Button
                type="submit"
                className="w-full h-11 text-sm font-medium"
              >
                Sign In
              </Button>
            </motion.div>
          </form>

          {/* Footer */}
          <motion.p
            className="mt-8 text-center text-gray-300 text-xs"
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
