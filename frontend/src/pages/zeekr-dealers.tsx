import { StrictMode, useState, useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { motion, AnimatePresence } from 'framer-motion'
import GradientText from '@/components/ui/GradientText'
import { Button } from '@/components/ui/Button'
import { Input } from '@/components/ui/Input'
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@/components/ui/Table'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/Alert'
import { Dialog, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/Dialog'
import '@/index.css'

interface Dealer {
  id: number
  username: string
  email: string
  display_name: string
  company_name: string
  abn: string
  phone: string
  fund_balance: number
  registered_date: string
}

interface DealerFormData {
  // Account
  username: string
  email: string
  password: string

  // Business Info
  dealer_group: string
  dealer_company_name: string
  business_name: string
  display_name: string
  abn: string
  phone: string
  fund_balance: number

  // Address
  delivery_address_full: string
  suburb: string
  state: string
  post_code: string

  // Operating Hours
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

const emptyFormData: DealerFormData = {
  username: '',
  email: '',
  password: '',
  dealer_group: '',
  dealer_company_name: '',
  business_name: '',
  display_name: '',
  abn: '',
  phone: '',
  fund_balance: 0,
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
}

// Input field component - defined outside to prevent re-creation on each render
const DealerInputField = ({
  label,
  field,
  type = 'text',
  disabled = false,
  formData,
  onChange
}: {
  label: string
  field: keyof DealerFormData
  type?: string
  disabled?: boolean
  formData: DealerFormData
  onChange: (field: keyof DealerFormData, value: string | number) => void
}) => (
  <div>
    <label className="block text-sm font-medium text-gray-700 mb-1">{label}</label>
    <Input
      type={type}
      value={formData[field] as string}
      onChange={(e) => onChange(field, type === 'number' ? parseFloat(e.target.value) || 0 : e.target.value)}
      disabled={disabled}
    />
  </div>
)

// Contact card component - defined outside to prevent re-creation on each render
const DealerContactCard = ({
  title,
  nameField,
  emailField,
  mobileField,
  phoneField,
  formData,
  onChange
}: {
  title: string
  nameField: keyof DealerFormData
  emailField: keyof DealerFormData
  mobileField: keyof DealerFormData
  phoneField: keyof DealerFormData
  formData: DealerFormData
  onChange: (field: keyof DealerFormData, value: string | number) => void
}) => (
  <div className="space-y-3">
    <h3 className="text-sm font-semibold text-gray-900 border-b pb-2">{title}</h3>
    <div className="grid grid-cols-2 gap-2">
      <DealerInputField label="Name" field={nameField} formData={formData} onChange={onChange} />
      <DealerInputField label="Email" field={emailField} type="email" formData={formData} onChange={onChange} />
      <DealerInputField label="Mobile" field={mobileField} type="tel" formData={formData} onChange={onChange} />
      <DealerInputField label="Phone" field={phoneField} type="tel" formData={formData} onChange={onChange} />
    </div>
  </div>
)

declare global {
  interface Window {
    zeekrDealers: {
      ajaxUrl: string
      nonce: string
      createNonce: string
      updateNonce: string
      deleteNonce: string
    }
  }
}

function ZeekrDealersPage() {
  const config = window.zeekrDealers || {
    ajaxUrl: '',
    nonce: '',
    createNonce: '',
    updateNonce: '',
    deleteNonce: '',
  }

  const [dealers, setDealers] = useState<Dealer[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [alert, setAlert] = useState<{ show: boolean; message: string; error?: boolean } | null>(null)

  // Create/Edit dialog state
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingDealer, setEditingDealer] = useState<Dealer | null>(null)
  const [formData, setFormData] = useState<DealerFormData>(emptyFormData)
  const [saving, setSaving] = useState(false)
  const [loadingDealer, setLoadingDealer] = useState(false)

  // Delete confirmation dialog
  const [deleteDialog, setDeleteDialog] = useState<{ open: boolean; dealer: Dealer | null }>({
    open: false,
    dealer: null,
  })
  const [deleting, setDeleting] = useState(false)

  // Fund adjustment dialog
  const [fundDialog, setFundDialog] = useState<{ open: boolean; dealer: Dealer | null }>({
    open: false,
    dealer: null,
  })
  const [fundAmount, setFundAmount] = useState('')
  const [fundAction, setFundAction] = useState<'add' | 'subtract'>('add')
  const [adjustingFund, setAdjustingFund] = useState(false)

  const fetchDealers = async (searchTerm: string = '') => {
    setLoading(true)
    try {
      const formData = new FormData()
      formData.append('action', 'zeekr_get_dealers')
      formData.append('nonce', config.nonce)
      formData.append('search', searchTerm)

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: formData,
      })

      const result = await response.json()

      if (result.success) {
        setDealers(result.data.dealers)
      }
    } catch (error) {
      console.error('Failed to fetch dealers:', error)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchDealers()
  }, [])

  const handleSearchChange = (value: string) => {
    setSearch(value)
    // Debounce search
    const timeout = setTimeout(() => {
      fetchDealers(value)
    }, 500)
    return () => clearTimeout(timeout)
  }

  const openCreateDialog = () => {
    setEditingDealer(null)
    setFormData(emptyFormData)
    setDialogOpen(true)
  }

  const openEditDialog = async (dealer: Dealer) => {
    setEditingDealer(dealer)
    setDialogOpen(true)
    setLoadingDealer(true)

    // Fetch full dealer data
    try {
      const submitData = new FormData()
      submitData.append('action', 'zeekr_get_dealer_detail')
      submitData.append('nonce', config.nonce)
      submitData.append('dealer_id', String(dealer.id))

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: submitData,
      })

      const result = await response.json()

      if (result.success) {
        setFormData({
          ...emptyFormData,
          ...result.data,
          password: '', // Never show password
        })
      } else {
        // Fallback to basic data
        setFormData({
          ...emptyFormData,
          username: dealer.username,
          email: dealer.email,
          display_name: dealer.display_name,
          dealer_company_name: dealer.company_name,
          abn: dealer.abn,
          phone: dealer.phone,
          fund_balance: dealer.fund_balance,
        })
      }
    } catch (error) {
      console.error('Failed to fetch dealer details:', error)
      // Fallback to basic data
      setFormData({
        ...emptyFormData,
        username: dealer.username,
        email: dealer.email,
        display_name: dealer.display_name,
        dealer_company_name: dealer.company_name,
        abn: dealer.abn,
        phone: dealer.phone,
        fund_balance: dealer.fund_balance,
      })
    } finally {
      setLoadingDealer(false)
    }
  }

  const handleSave = async () => {
    setSaving(true)
    try {
      const submitData = new FormData()
      submitData.append('action', editingDealer ? 'zeekr_update_dealer' : 'zeekr_create_dealer')
      submitData.append('nonce', editingDealer ? config.updateNonce : config.createNonce)

      if (editingDealer) {
        submitData.append('dealer_id', String(editingDealer.id))
      }

      // Append all form data
      Object.entries(formData).forEach(([key, value]) => {
        if (key === 'password' && !value) return // Skip empty password
        submitData.append(key, String(value))
      })

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: submitData,
      })

      const result = await response.json()

      if (result.success) {
        setAlert({ show: true, message: editingDealer ? 'Dealer updated successfully' : 'Dealer created successfully' })
        setDialogOpen(false)
        fetchDealers(search)
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to save dealer', error: true })
        setTimeout(() => setAlert(null), 4000)
      }
    } catch (error) {
      setAlert({ show: true, message: 'Network error', error: true })
      setTimeout(() => setAlert(null), 4000)
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteDialog.dealer) return

    setDeleting(true)
    try {
      const submitData = new FormData()
      submitData.append('action', 'zeekr_delete_dealer')
      submitData.append('nonce', config.deleteNonce)
      submitData.append('dealer_id', String(deleteDialog.dealer.id))

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: submitData,
      })

      const result = await response.json()

      if (result.success) {
        setAlert({ show: true, message: 'Dealer deleted successfully' })
        setDeleteDialog({ open: false, dealer: null })
        fetchDealers(search)
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to delete dealer', error: true })
        setTimeout(() => setAlert(null), 4000)
      }
    } catch (error) {
      setAlert({ show: true, message: 'Network error', error: true })
      setTimeout(() => setAlert(null), 4000)
    } finally {
      setDeleting(false)
    }
  }

  const openFundDialog = (dealer: Dealer) => {
    setFundDialog({ open: true, dealer })
    setFundAmount('')
    setFundAction('add')
  }

  const handleAdjustFund = async () => {
    if (!fundDialog.dealer || !fundAmount) return

    setAdjustingFund(true)
    try {
      const amount = parseFloat(fundAmount)
      if (isNaN(amount) || amount <= 0) {
        setAlert({ show: true, message: 'Please enter a valid amount', error: true })
        setTimeout(() => setAlert(null), 3000)
        return
      }

      const newBalance = fundAction === 'add'
        ? fundDialog.dealer.fund_balance + amount
        : fundDialog.dealer.fund_balance - amount

      const submitData = new FormData()
      submitData.append('action', 'zeekr_update_dealer')
      submitData.append('nonce', config.updateNonce)
      submitData.append('dealer_id', String(fundDialog.dealer.id))
      submitData.append('fund_balance', String(newBalance))

      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        body: submitData,
      })

      const result = await response.json()

      if (result.success) {
        setAlert({ show: true, message: `Dealer balance ${fundAction === 'add' ? 'increased' : 'decreased'} by $${amount.toFixed(2)}` })
        setFundDialog({ open: false, dealer: null })
        fetchDealers(search)
        setTimeout(() => setAlert(null), 3000)
      } else {
        setAlert({ show: true, message: result.data?.message || 'Failed to adjust dealer balance', error: true })
        setTimeout(() => setAlert(null), 4000)
      }
    } catch (error) {
      setAlert({ show: true, message: 'Network error', error: true })
      setTimeout(() => setAlert(null), 4000)
    } finally {
      setAdjustingFund(false)
    }
  }

  const handleChange = (field: keyof DealerFormData, value: string | number) => {
    setFormData(prev => ({ ...prev, [field]: value }))
  }

  // Copy functionality
  const [copyToast, setCopyToast] = useState<string | null>(null)
  const [copiedId, setCopiedId] = useState<string | null>(null)

  const handleCopy = (text: string, id: string) => {
    if (!text || text === '-') return
    navigator.clipboard.writeText(text).then(() => {
      setCopiedId(id)
      setCopyToast(text.length > 30 ? text.substring(0, 30) + '...' : text)
      setTimeout(() => {
        setCopiedId(null)
        setCopyToast(null)
      }, 2000)
    })
  }

  return (
    <div className="page-container">
      <div style={{ width: '100%', maxWidth: '95vw', margin: '0 auto', paddingTop: '120px', paddingBottom: '80px', paddingLeft: '16px', paddingRight: '16px' }}>
        {/* Copy Toast */}
        {copyToast && (
          <div className="copy-toast">
            Copied: {copyToast}
          </div>
        )}

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

        {/* Create/Edit Dialog - Full width */}
        <Dialog
          open={dialogOpen}
          onOpenChange={(open) => setDialogOpen(open)}
          maxWidth="max-w-4xl"
        >
          <DialogHeader>
            <DialogTitle>{editingDealer ? 'Edit Dealer' : 'Create New Dealer'}</DialogTitle>
            <DialogDescription>
              {editingDealer ? 'Update dealer information' : 'Fill in the details to create a new dealer account'}
            </DialogDescription>
          </DialogHeader>

          {loadingDealer ? (
            <div className="text-center py-8">
              <div className="inline-block w-6 h-6 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-2"></div>
              <p className="text-gray-500 text-sm">Loading dealer data...</p>
            </div>
          ) : (
            <div style={{ maxHeight: '60vh', overflowY: 'auto', padding: '4px' }}>
              {/* Account Information */}
              <div style={{ marginBottom: '20px' }}>
                <h2 className="text-base font-semibold mb-3 text-gray-900">Account Information</h2>
                <div className="grid grid-cols-3 gap-3">
                  <DealerInputField label="Username" field="username" disabled={!!editingDealer} formData={formData} onChange={handleChange} />
                  <DealerInputField label="Email" field="email" type="email" formData={formData} onChange={handleChange} />
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      {editingDealer ? 'New Password (optional)' : 'Password'}
                    </label>
                    <Input
                      type="password"
                      value={formData.password}
                      onChange={(e) => handleChange('password', e.target.value)}
                      placeholder={editingDealer ? 'Leave empty to keep current' : ''}
                    />
                  </div>
                </div>
              </div>

              {/* Business Information */}
              <div style={{ marginBottom: '20px' }}>
                <h2 className="text-base font-semibold mb-3 text-gray-900">Business Information</h2>
                <div className="grid grid-cols-3 gap-3">
                  <DealerInputField label="Display Name" field="display_name" formData={formData} onChange={handleChange} />
                  <DealerInputField label="Dealer Group" field="dealer_group" formData={formData} onChange={handleChange} />
                  <DealerInputField label="Dealer Company Name" field="dealer_company_name" formData={formData} onChange={handleChange} />
                  <DealerInputField label="Business Name" field="business_name" formData={formData} onChange={handleChange} />
                  <DealerInputField label="ABN" field="abn" formData={formData} onChange={handleChange} />
                  <DealerInputField label="Phone" field="phone" type="tel" formData={formData} onChange={handleChange} />
                  <DealerInputField label="Dealer Balance" field="fund_balance" type="number" formData={formData} onChange={handleChange} />
                </div>
              </div>

              {/* Address */}
              <div style={{ marginBottom: '20px' }}>
                <h2 className="text-base font-semibold mb-3 text-gray-900">Address</h2>
                <div className="grid grid-cols-4 gap-3">
                  <div className="col-span-2">
                    <DealerInputField label="Delivery Address" field="delivery_address_full" formData={formData} onChange={handleChange} />
                  </div>
                  <DealerInputField label="Suburb" field="suburb" formData={formData} onChange={handleChange} />
                  <div className="grid grid-cols-2 gap-2">
                    <DealerInputField label="State" field="state" formData={formData} onChange={handleChange} />
                    <DealerInputField label="Post Code" field="post_code" formData={formData} onChange={handleChange} />
                  </div>
                </div>
              </div>

              {/* Operating Hours */}
              <div style={{ marginBottom: '20px' }}>
                <h2 className="text-base font-semibold mb-3 text-gray-900">Operating Hours</h2>
                <div className="grid grid-cols-2 gap-3">
                  <DealerInputField label="Monday - Friday" field="operating_hours_weekday" formData={formData} onChange={handleChange} />
                  <DealerInputField label="Saturday" field="operating_hours_saturday" formData={formData} onChange={handleChange} />
                </div>
              </div>

              {/* Contacts */}
              <div>
                <h2 className="text-base font-semibold mb-3 text-gray-900">Contacts</h2>
                <div className="grid grid-cols-2 gap-4">
                  <DealerContactCard
                    title="Accounts Payable"
                    nameField="accounts_payable"
                    emailField="accounts_payable_email"
                    mobileField="accounts_payable_mobile"
                    phoneField="accounts_payable_phone"
                    formData={formData}
                    onChange={handleChange}
                  />
                  <DealerContactCard
                    title="Parts Manager"
                    nameField="parts_manager"
                    emailField="parts_manager_email"
                    mobileField="parts_manager_mobile"
                    phoneField="parts_manager_phone"
                    formData={formData}
                    onChange={handleChange}
                  />
                  <DealerContactCard
                    title="Parts Interpreter (Front)"
                    nameField="parts_interpreter_front"
                    emailField="parts_interpreter_front_email"
                    mobileField="parts_interpreter_front_mobile"
                    phoneField="parts_interpreter_front_phone"
                    formData={formData}
                    onChange={handleChange}
                  />
                  <DealerContactCard
                    title="Parts Interpreter (Back)"
                    nameField="parts_interpreter_back"
                    emailField="parts_interpreter_back_email"
                    mobileField="parts_interpreter_back_mobile"
                    phoneField="parts_interpreter_back_phone"
                    formData={formData}
                    onChange={handleChange}
                  />
                  <DealerContactCard
                    title="Parts Group"
                    nameField="parts_group"
                    emailField="parts_group_email"
                    mobileField="parts_group_mobile"
                    phoneField="parts_group_phone"
                    formData={formData}
                    onChange={handleChange}
                  />
                </div>
              </div>
            </div>
          )}

          <DialogFooter>
            <Button
              onClick={() => setDialogOpen(false)}
              style={{ background: '#f3f4f6', color: '#374151' }}
            >
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={saving || loadingDealer}>
              {saving ? 'Saving...' : (editingDealer ? 'Update' : 'Create')}
            </Button>
          </DialogFooter>
        </Dialog>

        {/* Delete Confirmation Dialog */}
        <Dialog
          open={deleteDialog.open}
          onOpenChange={(open) => setDeleteDialog(prev => ({ ...prev, open }))}
        >
          <DialogHeader>
            <DialogTitle>Delete Dealer</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete dealer <strong>{deleteDialog.dealer?.display_name || deleteDialog.dealer?.username}</strong>? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              onClick={() => setDeleteDialog({ open: false, dealer: null })}
              style={{ background: '#f3f4f6', color: '#374151' }}
            >
              Cancel
            </Button>
            <Button
              onClick={handleDelete}
              disabled={deleting}
              style={{ background: '#dc2626', color: 'white' }}
            >
              {deleting ? 'Deleting...' : 'Delete'}
            </Button>
          </DialogFooter>
        </Dialog>

        {/* Dealer Balance Adjustment Dialog */}
        <Dialog
          open={fundDialog.open}
          onOpenChange={(open) => setFundDialog(prev => ({ ...prev, open }))}
        >
          <DialogHeader>
            <DialogTitle>Adjust Dealer Balance</DialogTitle>
            <DialogDescription>
              Current balance for <strong>{fundDialog.dealer?.display_name || fundDialog.dealer?.username}</strong>: <strong>${fundDialog.dealer?.fund_balance.toFixed(2)}</strong>
            </DialogDescription>
          </DialogHeader>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '16px', marginTop: '16px' }}>
            <div style={{ display: 'flex', gap: '8px' }}>
              <Button
                onClick={() => setFundAction('add')}
                style={{
                  flex: 1,
                  background: fundAction === 'add' ? '#16a34a' : '#f3f4f6',
                  color: fundAction === 'add' ? 'white' : '#374151',
                }}
              >
                + Add
              </Button>
              <Button
                onClick={() => setFundAction('subtract')}
                style={{
                  flex: 1,
                  background: fundAction === 'subtract' ? '#dc2626' : '#f3f4f6',
                  color: fundAction === 'subtract' ? 'white' : '#374151',
                }}
              >
                - Subtract
              </Button>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Amount</label>
              <Input
                type="number"
                step="0.01"
                min="0"
                value={fundAmount}
                onChange={(e) => setFundAmount(e.target.value)}
                placeholder="Enter amount"
              />
            </div>
            {fundAmount && !isNaN(parseFloat(fundAmount)) && parseFloat(fundAmount) > 0 && (
              <p className="text-sm text-gray-500">
                New balance will be: <strong>${(
                  fundAction === 'add'
                    ? (fundDialog.dealer?.fund_balance || 0) + parseFloat(fundAmount)
                    : (fundDialog.dealer?.fund_balance || 0) - parseFloat(fundAmount)
                ).toFixed(2)}</strong>
              </p>
            )}
          </div>
          <div style={{ marginTop: '24px' }}>
            <DialogFooter>
              <Button
                onClick={() => setFundDialog({ open: false, dealer: null })}
                style={{ background: '#f3f4f6', color: '#374151' }}
              >
                Cancel
              </Button>
              <Button
                onClick={handleAdjustFund}
                disabled={adjustingFund || !fundAmount || isNaN(parseFloat(fundAmount)) || parseFloat(fundAmount) <= 0}
              >
                {adjustingFund ? 'Updating...' : 'Update Balance'}
              </Button>
            </DialogFooter>
          </div>
        </Dialog>

        {/* Header */}
        <motion.div
          className="mb-8 text-center"
          initial={{ opacity: 0, y: -20 }}
          animate={{ opacity: 1, y: 0 }}
        >
          <h1 className="text-4xl font-bold mb-2">
            <GradientText animationSpeed={4}>
              Dealers Management
            </GradientText>
          </h1>
          <p className="text-gray-500">Manage dealer accounts and balances</p>
        </motion.div>

        {/* Filters */}
        <motion.div
          className="flex gap-4 justify-center items-center"
          style={{ marginBottom: '24px' }}
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.1 }}
        >
          <Input
            type="text"
            placeholder="Search by name, email, company..."
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
            className="max-w-xs w-full !rounded-full"
          />
          <Button onClick={() => fetchDealers(search)}>
            Refresh
          </Button>
          <Button onClick={openCreateDialog}>
            + Add Dealer
          </Button>
        </motion.div>

        {/* Loading */}
        {loading ? (
          <motion.div
            className="text-center py-16"
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
          >
            <div className="inline-block w-8 h-8 border-2 border-gray-300 border-t-gray-900 rounded-full animate-spin mb-4"></div>
            <p className="text-gray-500">Loading dealers...</p>
          </motion.div>
        ) : (
          <>
            {/* Dealers Table */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 }}
              className="bg-white overflow-hidden"
            >
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Username</TableHead>
                    <TableHead>Display Name</TableHead>
                    <TableHead>Company</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Phone</TableHead>
                    <TableHead>ABN</TableHead>
                    <TableHead className="text-right">Dealer Balance</TableHead>
                    <TableHead className="text-center">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <AnimatePresence>
                    {dealers.map((dealer, index) => (
                      <motion.tr
                        key={dealer.id}
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0, y: -10 }}
                        transition={{ delay: index * 0.02 }}
                        className="border-b border-gray-100 hover:bg-gray-50 transition-colors"
                      >
                        <TableCell className="font-medium">{dealer.username}</TableCell>
                        <TableCell>{dealer.display_name || '-'}</TableCell>
                        <TableCell>
                          {dealer.company_name ? (
                            <span
                              onClick={() => handleCopy(dealer.company_name, `company-${dealer.id}`)}
                              className={`copyable-cell ${copiedId === `company-${dealer.id}` ? 'copied' : ''}`}
                              title="Click to copy"
                            >
                              {dealer.company_name}
                            </span>
                          ) : '-'}
                        </TableCell>
                        <TableCell className="text-gray-600">
                          <span
                            onClick={() => handleCopy(dealer.email, `email-${dealer.id}`)}
                            className={`copyable-cell ${copiedId === `email-${dealer.id}` ? 'copied' : ''}`}
                            title="Click to copy"
                          >
                            {dealer.email}
                          </span>
                        </TableCell>
                        <TableCell className="text-gray-600">
                          {dealer.phone ? (
                            <span
                              onClick={() => handleCopy(dealer.phone, `phone-${dealer.id}`)}
                              className={`copyable-cell ${copiedId === `phone-${dealer.id}` ? 'copied' : ''}`}
                              title="Click to copy"
                            >
                              {dealer.phone}
                            </span>
                          ) : '-'}
                        </TableCell>
                        <TableCell className="text-gray-600 font-mono text-sm">
                          {dealer.abn ? (
                            <span
                              onClick={() => handleCopy(dealer.abn, `abn-${dealer.id}`)}
                              className={`copyable-cell ${copiedId === `abn-${dealer.id}` ? 'copied' : ''}`}
                              title="Click to copy"
                            >
                              {dealer.abn}
                            </span>
                          ) : '-'}
                        </TableCell>
                        <TableCell className="text-right">
                          <span
                            className={`font-medium ${dealer.fund_balance >= 0 ? 'text-green-600' : 'text-red-600'}`}
                          >
                            ${dealer.fund_balance.toFixed(2)}
                          </span>
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center justify-center gap-2">
                            <Button size="sm" onClick={() => openFundDialog(dealer)}>
                              Balance
                            </Button>
                            <Button size="sm" onClick={() => openEditDialog(dealer)}>
                              Edit
                            </Button>
                            <Button
                              size="sm"
                              onClick={() => setDeleteDialog({ open: true, dealer })}
                              style={{ background: '#dc2626', color: 'white' }}
                            >
                              Delete
                            </Button>
                          </div>
                        </TableCell>
                      </motion.tr>
                    ))}
                  </AnimatePresence>
                </TableBody>
              </Table>
            </motion.div>

            {/* Empty State */}
            {dealers.length === 0 && (
              <motion.div
                className="text-center py-12"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
              >
                <p className="text-gray-500">No dealers found</p>
              </motion.div>
            )}

            {/* Stats */}
            <motion.div
              className="mt-6 text-center"
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
            >
              <p className="text-sm text-gray-400">
                Showing {dealers.length} dealer{dealers.length !== 1 ? 's' : ''}
              </p>
            </motion.div>
          </>
        )}
      </div>
    </div>
  )
}

// Mount the app
const container = document.getElementById('zeekr-dealers-root')
if (container) {
  createRoot(container).render(
    <StrictMode>
      <ZeekrDealersPage />
    </StrictMode>
  )
}

export default ZeekrDealersPage
