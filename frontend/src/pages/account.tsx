import { StrictMode, useState, useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/Alert'
import '@/index.css'

interface AccountData {
  // Basic Info
  email: string
  dealer_group: string
  dealer_company_name: string
  business_name: string

  // Address & Hours
  delivery_address_full: string
  suburb: string
  state: string
  post_code: string
  operating_hours_weekday: string
  operating_hours_saturday: string

  // Accounts Payable
  accounts_payable: string
  accounts_payable_email: string
  accounts_payable_mobile: string
  accounts_payable_phone: string

  // Parts Manager
  parts_manager: string
  parts_manager_email: string
  parts_manager_mobile: string
  parts_manager_phone: string

  // Parts Interpreter (Front Counter)
  parts_interpreter_front: string
  parts_interpreter_front_email: string
  parts_interpreter_front_mobile: string
  parts_interpreter_front_phone: string

  // Parts Interpreter (Back Counter)
  parts_interpreter_back: string
  parts_interpreter_back_email: string
  parts_interpreter_back_mobile: string
  parts_interpreter_back_phone: string

  // Parts Group
  parts_group: string
  parts_group_email: string
  parts_group_mobile: string
  parts_group_phone: string
}

// Input field component - defined outside to prevent re-creation on each render
const AccountInputField = ({
  label,
  field,
  type = 'text',
  data,
  onChange
}: {
  label: string
  field: keyof AccountData
  type?: string
  data: AccountData
  onChange: (field: keyof AccountData, value: string) => void
}) => (
  <div>
    <label className="block text-sm font-medium text-gray-700 mb-1">{label}</label>
    <Input
      type={type}
      value={data[field]}
      onChange={(e) => onChange(field, e.target.value)}
    />
  </div>
)

// Contact card component - defined outside to prevent re-creation on each render
const AccountContactCard = ({
  title,
  nameField,
  emailField,
  mobileField,
  phoneField,
  data,
  onChange
}: {
  title: string
  nameField: keyof AccountData
  emailField: keyof AccountData
  mobileField: keyof AccountData
  phoneField: keyof AccountData
  data: AccountData
  onChange: (field: keyof AccountData, value: string) => void
}) => (
  <div className="space-y-3">
    <h3 className="text-base font-semibold text-gray-900 border-b pb-2">{title}</h3>
    <AccountInputField label="Name" field={nameField} data={data} onChange={onChange} />
    <AccountInputField label="Email" field={emailField} type="email" data={data} onChange={onChange} />
    <AccountInputField label="Mobile" field={mobileField} type="tel" data={data} onChange={onChange} />
    <AccountInputField label="Phone" field={phoneField} type="tel" data={data} onChange={onChange} />
  </div>
)

declare global {
  interface Window {
    dealerAccount: {
      ajaxUrl: string
      nonce: string
      updateNonce: string
    }
  }
}

function AccountPage() {
  const config = window.dealerAccount || {
    ajaxUrl: '',
    nonce: '',
    updateNonce: '',
  }

  const [data, setData] = useState<AccountData>({
    email: '',
    dealer_group: '',
    dealer_company_name: '',
    business_name: '',
    delivery_address_full: '',
    suburb: '',
    state: '',
    post_code: '',
    operating_hours_weekday: '',
    operating_hours_saturday: '',
    accounts_payable: '',
    accounts_payable_email: '',
    accounts_payable_mobile: '',
    accounts_payable_phone: '',
    parts_manager: '',
    parts_manager_email: '',
    parts_manager_mobile: '',
    parts_manager_phone: '',
    parts_interpreter_front: '',
    parts_interpreter_front_email: '',
    parts_interpreter_front_mobile: '',
    parts_interpreter_front_phone: '',
    parts_interpreter_back: '',
    parts_interpreter_back_email: '',
    parts_interpreter_back_mobile: '',
    parts_interpreter_back_phone: '',
    parts_group: '',
    parts_group_email: '',
    parts_group_mobile: '',
    parts_group_phone: '',
  })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [alert, setAlert] = useState<{ show: boolean; message: string; error?: boolean } | null>(null)

  const fetchAccountData = async () => {
    setLoading(true)
    try {
      const formData = new FormData()
      formData.append('action', 'dealer_get_account')
      formData.append('nonce', config.nonce)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setData(result.data)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to load account data', error: true })
      }
    } catch (error) {
      console.error('Failed to fetch account data:', error)
      setAlert({ show: true, message: 'Network error', error: true })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchAccountData()
  }, [])

  const handleChange = (field: keyof AccountData, value: string) => {
    setData(prev => ({ ...prev, [field]: value }))
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)

    try {
      const formData = new FormData()
      formData.append('action', 'dealer_update_account')
      formData.append('nonce', config.updateNonce)
      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value)
      })

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setAlert({ show: true, message: 'Account updated successfully' })
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to update', error: true })
        setTimeout(() => setAlert(null), 4000)
      }
    } catch (error) {
      setAlert({ show: true, message: 'Network error', error: true })
      setTimeout(() => setAlert(null), 4000)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="page-container">
      <div className="page-content" style={{ paddingTop: '80px', width: '100%', maxWidth: '1200px', margin: '0 auto', padding: '80px 16px 40px' }}>
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
                  <AlertTitle>{alert.error ? 'Error' : 'Success'}</AlertTitle>
                  <AlertDescription>{alert.message}</AlertDescription>
                </div>
              </Alert>
            </motion.div>
          )}
        </AnimatePresence>

        {/* Header */}
        <motion.div
          className="mb-6 text-center"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-3xl font-bold mb-1">
            <GradientText animationSpeed={4}>
              My Account
            </GradientText>
          </h1>
          <p className="text-gray-500 text-sm">Manage your dealer information</p>
        </motion.div>

        {/* Loading */}
        {loading ? (
          <motion.div
            className="text-center py-12"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
            <p className="text-gray-500">Loading account data...</p>
          </motion.div>
        ) : (
          <motion.form
            onSubmit={handleSubmit}
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.1 }}
            className="space-y-6"
          >
            {/* Business Information - 3 columns */}
            <div>
              <h2 className="text-lg font-semibold mb-4">Business Information</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <AccountInputField label="Email" field="email" type="email" data={data} onChange={handleChange} />
                <AccountInputField label="Dealer Group" field="dealer_group" data={data} onChange={handleChange} />
                <AccountInputField label="Dealer Company Name" field="dealer_company_name" data={data} onChange={handleChange} />
                <AccountInputField label="Business Name" field="business_name" data={data} onChange={handleChange} />
                <AccountInputField label="Delivery Address" field="delivery_address_full" data={data} onChange={handleChange} />
                <div className="grid grid-cols-3 gap-2">
                  <AccountInputField label="Suburb" field="suburb" data={data} onChange={handleChange} />
                  <AccountInputField label="State" field="state" data={data} onChange={handleChange} />
                  <AccountInputField label="Post Code" field="post_code" data={data} onChange={handleChange} />
                </div>
              </div>
            </div>

            {/* Operating Hours */}
            <div>
              <h2 className="text-lg font-semibold mb-4">Operating Hours</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <AccountInputField label="Monday - Friday" field="operating_hours_weekday" data={data} onChange={handleChange} />
                <AccountInputField label="Saturday" field="operating_hours_saturday" data={data} onChange={handleChange} />
              </div>
            </div>

            {/* Contacts - 2x2 grid on desktop */}
            <div>
              <h2 className="text-lg font-semibold mb-4">Contacts</h2>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <AccountContactCard
                  title="Accounts Payable"
                  nameField="accounts_payable"
                  emailField="accounts_payable_email"
                  mobileField="accounts_payable_mobile"
                  phoneField="accounts_payable_phone"
                  data={data}
                  onChange={handleChange}
                />
                <AccountContactCard
                  title="Parts Manager"
                  nameField="parts_manager"
                  emailField="parts_manager_email"
                  mobileField="parts_manager_mobile"
                  phoneField="parts_manager_phone"
                  data={data}
                  onChange={handleChange}
                />
                <AccountContactCard
                  title="Parts Interpreter (Front Counter)"
                  nameField="parts_interpreter_front"
                  emailField="parts_interpreter_front_email"
                  mobileField="parts_interpreter_front_mobile"
                  phoneField="parts_interpreter_front_phone"
                  data={data}
                  onChange={handleChange}
                />
                <AccountContactCard
                  title="Parts Interpreter (Back Counter)"
                  nameField="parts_interpreter_back"
                  emailField="parts_interpreter_back_email"
                  mobileField="parts_interpreter_back_mobile"
                  phoneField="parts_interpreter_back_phone"
                  data={data}
                  onChange={handleChange}
                />
                <AccountContactCard
                  title="Parts Group"
                  nameField="parts_group"
                  emailField="parts_group_email"
                  mobileField="parts_group_mobile"
                  phoneField="parts_group_phone"
                  data={data}
                  onChange={handleChange}
                />
              </div>
            </div>

            {/* Password Link */}
            <div className="flex items-center justify-between pt-4 border-t">
              <div>
                <span className="text-sm text-gray-600">Need to change your password? </span>
                <a href="/my-account/lost-password/" className="text-sm text-blue-600 hover:text-blue-800 font-medium">
                  Reset Password →
                </a>
              </div>
              <Button type="submit" disabled={saving}>
                {saving ? 'Saving...' : 'Save Changes'}
              </Button>
            </div>
          </motion.form>
        )}
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('dealer-account-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <AccountPage />
    </StrictMode>
  )
}

export default AccountPage
