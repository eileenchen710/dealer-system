import { StrictMode, useState, useEffect } from 'react'
import { createRoot } from 'react-dom/client'
import { motion } from 'framer-motion'
import jsPDF from 'jspdf'
import autoTable from 'jspdf-autotable'
import * as XLSX from 'xlsx'
import '@/index.css'

interface Dealer {
  id: number
  name: string
}

interface Transaction {
  id: number
  date: string
  type: string
  description: string
  order_id: number
  invoice_number: string
  amount: number
  running_balance: number
}

interface DealerInfo {
  company_name: string
  dealer_name: string
  abn: string
  dealer_code: string
  address: string
  phone: string
  email: string
  current_balance: number
  ap_name: string
  ap_email: string
  ap_phone: string
  pm_name: string
  pm_email: string
  pm_phone: string
}

interface StatementData {
  dealer: DealerInfo
  opening_balance: number
  transactions: Transaction[]
  summary: {
    total_invoices: number
    total_payments: number
    net_period: number
    closing_owing: number
    overdue: number
  }
}

interface TaxInvoiceRow {
  type: string
  invoice_no: string
  item: string
  credit_against: string
  quantity: number
  total_ex_gst: number
  gst: number
  total_inc_gst: number
  customer_name: string
  order_id: number
  date: string
}

interface TaxInvoiceReport {
  rows: TaxInvoiceRow[]
  totals: {
    total_ex_gst: number
    gst: number
    total_inc_gst: number
    quantity: number
  }
  count: number
}

declare global {
  interface Window {
    zeekrStatement: {
      ajaxUrl: string
      nonce: string
      dealers: Dealer[]
      logoUrl: string
    }
  }
}

type QuickRange = 'this_month' | 'last_month' | 'this_quarter' | 'last_quarter' | 'this_year' | 'custom'

function getDateRange(range: QuickRange): { from: string; to: string } {
  const now = new Date()
  const y = now.getFullYear()
  const m = now.getMonth()
  const fmt = (d: Date) => d.toISOString().split('T')[0]

  switch (range) {
    case 'this_month':
      return { from: fmt(new Date(y, m, 1)), to: fmt(now) }
    case 'last_month':
      return { from: fmt(new Date(y, m - 1, 1)), to: fmt(new Date(y, m, 0)) }
    case 'this_quarter': {
      const qStart = Math.floor(m / 3) * 3
      return { from: fmt(new Date(y, qStart, 1)), to: fmt(now) }
    }
    case 'last_quarter': {
      const qStart = Math.floor(m / 3) * 3
      return { from: fmt(new Date(y, qStart - 3, 1)), to: fmt(new Date(y, qStart, 0)) }
    }
    case 'this_year':
      return { from: fmt(new Date(y, 0, 1)), to: fmt(now) }
    default:
      return { from: '', to: '' }
  }
}

function loadImageAsBase64(url: string, maxWidth = 600): Promise<string> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => {
      // Downscale to a sensible cap before encoding. The source logo is huge
      // (3840px wide) but is only printed at ~45mm, so embedding it at full
      // resolution bloats the PDF to ~14MB. Capping width keeps it crisp & tiny.
      const scale = img.naturalWidth > maxWidth ? maxWidth / img.naturalWidth : 1
      const canvas = document.createElement('canvas')
      canvas.width = Math.round(img.naturalWidth * scale)
      canvas.height = Math.round(img.naturalHeight * scale)
      const ctx = canvas.getContext('2d')
      if (!ctx) { reject('no ctx'); return }
      ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
      resolve(canvas.toDataURL('image/png'))
    }
    img.onerror = reject
    img.src = url
  })
}

function ZeekrStatementPage() {
  const config = window.zeekrStatement || { ajaxUrl: '', nonce: '', dealers: [], logoUrl: '' }

  const [dealerId, setDealerId] = useState<number>(-1) // -1 = not selected, 0 = all dealers
  const [quickRange, setQuickRange] = useState<QuickRange>('this_month')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [loading, setLoading] = useState(false)
  const [data, setData] = useState<StatementData | null>(null)
  const [pdfGenerating, setPdfGenerating] = useState(false)

  // Tax invoice report data (for Excel download in Andrew's format)
  const [taxReport, setTaxReport] = useState<TaxInvoiceReport | null>(null)

  // Adjustment dialog state
  const [showAdjDialog, setShowAdjDialog] = useState(false)
  const [adjAmount, setAdjAmount] = useState('')
  const [adjType, setAdjType] = useState('opening_balance')
  const [adjDesc, setAdjDesc] = useState('')
  const [adjSubmitting, setAdjSubmitting] = useState(false)

  useEffect(() => {
    const range = getDateRange('this_month')
    setDateFrom(range.from)
    setDateTo(range.to)
  }, [])

  const handleQuickRange = (range: QuickRange) => {
    setQuickRange(range)
    if (range !== 'custom') {
      const { from, to } = getDateRange(range)
      setDateFrom(from)
      setDateTo(to)
    }
  }

  const fetchStatement = async () => {
    if (dealerId < 0 || !dateFrom || !dateTo) return
    setLoading(true)
    setData(null)
    setTaxReport(null)

    try {
      // Fetch statement data (works for both single dealer and all dealers)
      const formData = new FormData()
      formData.append('action', 'zeekr_get_dealer_statement')
      formData.append('nonce', config.nonce)
      formData.append('dealer_id', dealerId.toString())
      formData.append('date_from', dateFrom)
      formData.append('date_to', dateTo)

      const response = await fetch(config.ajaxUrl, { method: 'POST', body: formData })
      const result = await response.json()

      if (result.success) {
        setData(result.data)
      } else {
        alert(result.data?.message || 'Failed to load statement')
      }

      // Also fetch tax invoice report data (for Excel/CSV/PDF downloads)
      const taxFormData = new FormData()
      taxFormData.append('action', 'zeekr_get_tax_invoice_report')
      taxFormData.append('nonce', config.nonce)
      taxFormData.append('dealer_id', dealerId.toString())
      taxFormData.append('date_from', dateFrom)
      taxFormData.append('date_to', dateTo)

      const taxResponse = await fetch(config.ajaxUrl, { method: 'POST', body: taxFormData })
      const taxResult = await taxResponse.json()
      if (taxResult.success) {
        setTaxReport(taxResult.data)
      }
    } catch {
      alert('Network error')
    } finally {
      setLoading(false)
    }
  }

  const submitAdjustment = async () => {
    if (!dealerId || !adjAmount) return
    const amt = parseFloat(adjAmount)
    if (isNaN(amt) || amt <= 0) { alert('Please enter a valid positive amount'); return }

    const typeLabels: Record<string, string> = {
      opening_balance: 'Opening Balance - Prior Period',
      debit_note: 'Debit Note',
      credit_note: 'Credit Note',
      write_off: 'Write-off',
      payment_received: 'Payment Received',
    }
    const confirmMsg = `Record ${typeLabels[adjType]} of $${amt.toFixed(2)} for this dealer?`
    if (!confirm(confirmMsg)) return

    setAdjSubmitting(true)
    try {
      const formData = new FormData()
      formData.append('action', 'zeekr_record_adjustment')
      formData.append('nonce', config.nonce)
      formData.append('dealer_id', dealerId.toString())
      formData.append('amount', amt.toString())
      formData.append('adj_type', adjType)
      formData.append('description', adjDesc)

      const response = await fetch(config.ajaxUrl, { method: 'POST', body: formData })
      const result = await response.json()

      if (result.success) {
        alert(result.data.message)
        setShowAdjDialog(false)
        setAdjAmount('')
        setAdjDesc('')
        setAdjType('opening_balance')
        fetchStatement() // Refresh
      } else {
        alert(result.data?.message || 'Failed to record adjustment')
      }
    } catch {
      alert('Network error')
    } finally {
      setAdjSubmitting(false)
    }
  }

  const fmtDateAU = (d: string) => {
    if (!d) return ''
    const date = new Date(d)
    const dd = String(date.getDate()).padStart(2, '0')
    const mm = String(date.getMonth() + 1).padStart(2, '0')
    const yyyy = date.getFullYear()
    return `${dd}/${mm}/${yyyy}`
  }

  const fmtMoney = (n: number) => {
    const abs = Math.abs(n)
    const formatted = abs.toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    return n < 0 ? `-${formatted}` : formatted
  }

  // ============================================================
  // PDF Generation — full statement layout with Andrew's table columns
  // ============================================================
  const downloadPDF = async () => {
    if (!data) return
    setPdfGenerating(true)

    try {
      const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
      const pw = doc.internal.pageSize.getWidth()
      const mg = 15
      const rc = pw / 2 + 10
      let y = 15

      // ---------- LOGO (top-right) ----------
      try {
        const logoBase64 = await loadImageAsBase64(config.logoUrl)
        doc.addImage(logoBase64, 'PNG', pw - mg - 45, y, 45, 11.3)
      } catch {
        doc.setFontSize(18)
        doc.setFont('helvetica', 'bold')
        doc.text('ZEEKR', pw - mg, y + 8, { align: 'right' })
      }

      // ---------- Title ----------
      doc.setFontSize(16)
      doc.setFont('helvetica', 'bold')
      doc.text('STATEMENT OF ACCOUNT', mg, y + 8)
      y += 16

      // ---------- Zeekr info (left) ----------
      doc.setFontSize(8)
      doc.setFont('helvetica', 'normal')
      ;['Zeekr Intelligent Technology Australia Pty.', 'Suite 6.03, 11 Khartoum Road,', 'Macquarie Park, NSW 2113', 'ABN: 19 675 714 039'].forEach((l) => {
        doc.text(l, mg, y); y += 3.5
      })

      // ---------- Account info (right) ----------
      let ry = 32
      const drawRow = (label: string, value: string) => {
        doc.setFont('helvetica', 'bold'); doc.setFontSize(8)
        doc.text(label, rc, ry)
        doc.setFont('helvetica', 'normal')
        doc.text(value, pw - mg, ry, { align: 'right' })
        ry += 5
      }
      drawRow('ACCOUNT:', data.dealer.dealer_name || data.dealer.company_name)
      drawRow('STATEMENT DATE:', fmtDateAU(dateTo))
      drawRow('CURRENCY:', 'AUD')
      drawRow('STANDARD TERMS:', '15 DAYS FROM INV. DATE')

      y = Math.max(y, ry) + 4
      doc.setLineWidth(0.3); doc.line(mg, y, pw - mg, y); y += 5

      // ---------- BILL TO / SHIP TO ----------
      doc.setFontSize(8); doc.setFont('helvetica', 'bold')
      doc.text('BILL TO', mg, y); doc.text('SHIP TO', rc, y); y += 4

      doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5)
      const billLines: string[] = []
      if (data.dealer.dealer_code) billLines.push(`D#: ${data.dealer.dealer_code}`)
      if (data.dealer.company_name) billLines.push(data.dealer.company_name)
      if (data.dealer.abn) billLines.push(`ABN: ${data.dealer.abn}`)
      if (data.dealer.dealer_name) billLines.push(data.dealer.dealer_name)
      if (data.dealer.address) billLines.push(data.dealer.address)
      if (data.dealer.ap_name) billLines.push(`Contact Person: ${data.dealer.ap_name}`)
      if (data.dealer.ap_phone) billLines.push(`P: ${data.dealer.ap_phone}`)
      if (data.dealer.ap_email) billLines.push(`E: ${data.dealer.ap_email}`)

      const bY = y
      billLines.forEach((l) => {
        doc.splitTextToSize(l, rc - mg - 10).forEach((sl: string) => { doc.text(sl, mg, y); y += 3.5 })
      })

      let sy = bY
      const shipLines: string[] = []
      if (data.dealer.dealer_code) shipLines.push(`D#: ${data.dealer.dealer_code}`)
      if (data.dealer.dealer_name) shipLines.push(data.dealer.dealer_name)
      if (data.dealer.address) shipLines.push(data.dealer.address)
      if (data.dealer.pm_name) shipLines.push(`Contact Person: ${data.dealer.pm_name}`)
      if (data.dealer.pm_phone) shipLines.push(`P: ${data.dealer.pm_phone}`)
      if (data.dealer.pm_email) shipLines.push(`E: ${data.dealer.pm_email}`)
      shipLines.forEach((l) => {
        doc.splitTextToSize(l, pw - mg - rc - 5).forEach((sl: string) => { doc.text(sl, rc, sy); sy += 3.5 })
      })

      y = Math.max(y, sy) + 4

      // ---------- TABLE (Statement transactions) ----------
      const tableBody: (string | number)[][] = []
      let runBal = data.opening_balance

      // Opening balance row
      tableBody.push([1, '', '', 'Opening Balance', '', '', fmtMoney(runBal)])

      data.transactions.forEach((tx, i) => {
        const stmtAmt = -tx.amount
        runBal += stmtAmt
        tableBody.push([
          i + 2,
          tx.invoice_number || '',
          fmtDateAU(tx.date),
          tx.description,
          fmtMoney(stmtAmt),
          fmtMoney(stmtAmt),
          fmtMoney(runBal),
        ])
      })

      // Pad to fill page but leave room for footer (max 20 rows)
      while (tableBody.length < 20) {
        tableBody.push([tableBody.length + 1, '', '', '', '', '', ''])
      }

      autoTable(doc, {
        startY: y,
        head: [['NO', 'INVOICE NO.', 'INVOICE DATE', 'DESCRIPTION', 'INV. AMT. AUD', 'BALANCE AUD', 'TOTAL AUD']],
        body: tableBody,
        theme: 'grid',
        styles: { fontSize: 7, cellPadding: 1.5, lineColor: [0, 0, 0], lineWidth: 0.2, textColor: [0, 0, 0], valign: 'middle' },
        headStyles: { fillColor: [0, 0, 0], textColor: [255, 255, 255], fontStyle: 'bold', fontSize: 7, halign: 'center' },
        columnStyles: {
          0: { halign: 'center', cellWidth: 10 },
          1: { cellWidth: 30 },
          2: { cellWidth: 22 },
          3: { cellWidth: 'auto' },
          4: { halign: 'right', cellWidth: 25 },
          5: { halign: 'right', cellWidth: 25 },
          6: { halign: 'right', cellWidth: 22 },
        },
        margin: { left: mg, right: mg },
      })

      const finalY = (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 4
      const pageHeight = doc.internal.pageSize.getHeight()
      let footY = finalY

      // If footer won't fit, add a new page
      if (footY + 55 > pageHeight) {
        doc.addPage()
        footY = 20
      }

      // ---------- FOOTER ----------
      // Left: Remarks
      doc.setFontSize(7); doc.setFont('helvetica', 'bold')
      doc.text('Remarks / Payment Instructions:', mg, footY); footY += 3.5
      doc.setFont('helvetica', 'normal'); doc.setFontSize(6)
      ;[
        "Customer shall pay to Zeekr all sums when due in accordance with the payment terms as",
        "stated on Zeekr's invoice. Please contact us within 7 days should there by any",
        "discrepancies. Please email remittance advice and account queries to:",
        "zeekr.parts.au@zeekrlife.com.",
      ].forEach((l) => { doc.text(l, mg, footY); footY += 2.8 })

      footY += 2
      doc.setFont('helvetica', 'bold'); doc.setFontSize(7)
      doc.text('Banking Details:', mg, footY); footY += 3.5
      doc.setFont('helvetica', 'normal'); doc.setFontSize(6)
      ;[
        'Bank Name: HSBC Bank Australia Limited',
        'Account Name: ZEEKR INTELLIGENT TECH AU PL',
        'BSB: 342-011',
        'Account No: 168853001',
      ].forEach((l) => { doc.text(l, mg + 2, footY); footY += 2.8 })

      // Right: Summary
      const summaryStartY = finalY + 55 > pageHeight ? 20 : finalY
      let sY = summaryStartY
      doc.setFont('helvetica', 'bold'); doc.setFontSize(7)
      doc.text('OVERDUE AT STATEMENT DATE:', rc, sY)
      doc.text(fmtMoney(data.summary.overdue), pw - mg, sY, { align: 'right' }); sY += 8

      doc.text('CURRENT AT STATEMENT DATE:', rc, sY)
      doc.text(fmtMoney(data.summary.net_period), pw - mg, sY, { align: 'right' }); sY += 12

      doc.setLineWidth(0.5); doc.line(rc, sY - 2, pw - mg, sY - 2)
      doc.setFontSize(10); doc.setFont('helvetica', 'bold')
      doc.text('TOTAL AUD', rc, sY + 4)
      doc.text(fmtMoney(data.summary.closing_owing), pw - mg, sY + 4, { align: 'right' })

      // Save
      const name = (data.dealer.dealer_name || data.dealer.company_name).replace(/[^a-zA-Z0-9 ]/g, '').replace(/\s+/g, '-')
      doc.save(`STATEMENT_OF_ACCOUNT-${name}-${dateTo.replace(/-/g, '')}.pdf`)
    } catch (err) {
      console.error('PDF generation error:', err)
      alert('Failed to generate PDF')
    } finally {
      setPdfGenerating(false)
    }
  }

  const downloadExcel = () => {
    if (!taxReport) return

    const wb = XLSX.utils.book_new()

    const wsData: (string | number)[][] = [
      [], // Row 1 - empty
      ['', 'Invoice No.', 'Quantity', 'Total  Ex GST', 'GST  (10%)', 'Total Inc GST', 'Customer Name'],
    ]

    taxReport.rows.forEach((row, i) => {
      wsData.push([
        `${i + 1} Tax Invoice`,
        row.invoice_no,
        row.quantity,
        row.total_ex_gst,
        row.gst,
        row.total_inc_gst,
        row.customer_name,
      ])
    })

    // Total row
    wsData.push([
      '', '', 'Total',
      taxReport.totals.total_ex_gst,
      taxReport.totals.gst,
      taxReport.totals.total_inc_gst,
      '',
    ])

    const ws = XLSX.utils.aoa_to_sheet(wsData)

    ws['!cols'] = [
      { wch: 18 },  // A - Type
      { wch: 22 },  // B - Invoice No.
      { wch: 10 },  // C - Quantity
      { wch: 16 },  // D - Total Ex GST
      { wch: 16 },  // E - GST (10%)
      { wch: 16 },  // F - Total Inc GST
      { wch: 35 },  // G - Customer Name
    ]

    XLSX.utils.book_append_sheet(wb, ws, 'Tax Invoice Report')

    const dealerName = dealerId > 0
      ? (data?.dealer.dealer_name || data?.dealer.company_name || 'dealer').replace(/[^a-zA-Z0-9 ]/g, '').replace(/\s+/g, '_')
      : 'All_Dealers'
    XLSX.writeFile(wb, `Tax_Invoice_Report_${dealerName}_${dateFrom}_${dateTo}.xlsx`)
  }

  const thStyle: React.CSSProperties = {
    textAlign: 'left', padding: '10px 12px', fontWeight: 600, color: 'white',
    fontSize: '12px', background: '#111827', whiteSpace: 'nowrap',
  }

  const tdStyle: React.CSSProperties = {
    padding: '8px 12px', fontSize: '13px', color: '#374151', borderBottom: '1px solid #e5e7eb',
  }

  return (
    <div className="page-container">
      <div className="page-content" style={{ paddingTop: '120px' }}>
        <motion.div initial={{ opacity: 0, y: 20 }} animate={{ opacity: 1, y: 0 }}>

          {/* Controls */}
          <div style={{ background: 'white', borderRadius: '12px', padding: '20px', marginBottom: '24px', border: '1px solid #e5e7eb' }}>
            <div style={{ display: 'flex', gap: '16px', flexWrap: 'wrap', alignItems: 'flex-end' }}>
              <div style={{ flex: '1', minWidth: '200px' }}>
                <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: '#6b7280', marginBottom: '4px' }}>Dealer</label>
                <select value={dealerId} onChange={(e) => { setDealerId(Number(e.target.value)); setData(null); setTaxReport(null) }}
                  style={{ width: '100%', padding: '8px 12px', borderRadius: '8px', border: '1px solid #d1d5db', fontSize: '14px', color: '#111827', background: 'white' }}>
                  <option value={-1}>Select Dealer...</option>
                  <option value={0}>--- All Dealers ---</option>
                  {config.dealers.map((d) => (<option key={d.id} value={d.id}>{d.name}</option>))}
                </select>
              </div>
              <div>
                <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: '#6b7280', marginBottom: '4px' }}>From</label>
                <input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setQuickRange('custom') }}
                  style={{ padding: '8px 12px', borderRadius: '8px', border: '1px solid #d1d5db', fontSize: '14px' }} />
              </div>
              <div>
                <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: '#6b7280', marginBottom: '4px' }}>To</label>
                <input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setQuickRange('custom') }}
                  style={{ padding: '8px 12px', borderRadius: '8px', border: '1px solid #d1d5db', fontSize: '14px' }} />
              </div>
              <button onClick={fetchStatement} disabled={dealerId < 0 || !dateFrom || !dateTo || loading}
                style={{ padding: '8px 24px', background: dealerId < 0 ? '#d1d5db' : '#111827', color: 'white', border: 'none', borderRadius: '8px', fontSize: '14px', fontWeight: 600, cursor: dealerId < 0 ? 'not-allowed' : 'pointer', height: '40px' }}>
                {loading ? 'Loading...' : 'Generate'}
              </button>
              {dealerId > 0 && (
              <button onClick={() => { setShowAdjDialog(true) }}
                style={{ padding: '8px 16px', background: 'white', color: '#111827', border: '1px solid #d1d5db', borderRadius: '8px', fontSize: '14px', fontWeight: 500, cursor: 'pointer', height: '40px', whiteSpace: 'nowrap' }}>
                + Record Adjustment
              </button>
              )}
            </div>
            <div style={{ display: 'flex', gap: '8px', marginTop: '12px', flexWrap: 'wrap' }}>
              {([
                { value: 'this_month', label: 'This Month' },
                { value: 'last_month', label: 'Last Month' },
                { value: 'this_quarter', label: 'This Quarter' },
                { value: 'last_quarter', label: 'Last Quarter' },
                { value: 'this_year', label: 'This Year' },
              ] as { value: QuickRange; label: string }[]).map((opt) => (
                <button key={opt.value} onClick={() => handleQuickRange(opt.value)}
                  style={{
                    padding: '4px 14px', borderRadius: '20px',
                    border: quickRange === opt.value ? '2px solid #111827' : '1px solid #d1d5db',
                    background: quickRange === opt.value ? '#111827' : 'white',
                    color: quickRange === opt.value ? 'white' : '#374151',
                    fontSize: '12px', fontWeight: quickRange === opt.value ? 600 : 400, cursor: 'pointer',
                  }}>
                  {opt.label}
                </button>
              ))}
            </div>
          </div>

          {/* Adjustment Dialog */}
          {showAdjDialog && (
            <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.5)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
              onClick={(e) => { if (e.target === e.currentTarget) setShowAdjDialog(false) }}>
              <div style={{ background: 'white', borderRadius: '12px', padding: '28px', width: '100%', maxWidth: '460px', boxShadow: '0 25px 50px rgba(0,0,0,0.25)' }}>
                <h3 style={{ fontSize: '18px', fontWeight: 700, color: '#111827', marginBottom: '4px' }}>Record Adjustment</h3>
                <p style={{ fontSize: '13px', color: '#6b7280', marginBottom: '20px' }}>This will appear on the dealer's statement. No order will be created.</p>

                {/* Type */}
                <div style={{ marginBottom: '14px' }}>
                  <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: '#374151', marginBottom: '4px' }}>Type</label>
                  <select value={adjType} onChange={(e) => setAdjType(e.target.value)}
                    style={{ width: '100%', padding: '8px 12px', borderRadius: '8px', border: '1px solid #d1d5db', fontSize: '14px' }}>
                    <option value="opening_balance">Opening Balance - Prior Period</option>
                    <option value="debit_note">Debit Note (Dealer owes more)</option>
                    <option value="credit_note">Credit Note (Reduce amount owed)</option>
                    <option value="write_off">Write-off</option>
                    <option value="payment_received">Payment Received (Reduce amount owed)</option>
                  </select>
                </div>

                {/* Amount */}
                <div style={{ marginBottom: '14px' }}>
                  <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: '#374151', marginBottom: '4px' }}>Amount (AUD)</label>
                  <input type="number" step="0.01" min="0.01" value={adjAmount} onChange={(e) => setAdjAmount(e.target.value)}
                    placeholder="e.g. 25621.00"
                    style={{ width: '100%', padding: '8px 12px', borderRadius: '8px', border: '1px solid #d1d5db', fontSize: '14px', boxSizing: 'border-box' }} />
                  <div style={{ fontSize: '11px', color: '#9ca3af', marginTop: '4px' }}>
                    {adjType === 'opening_balance' || adjType === 'debit_note'
                      ? 'This amount will be added to the dealer\'s balance owing.'
                      : 'This amount will reduce the dealer\'s balance owing.'}
                  </div>
                </div>

                {/* Description */}
                <div style={{ marginBottom: '20px' }}>
                  <label style={{ display: 'block', fontSize: '13px', fontWeight: 500, color: '#374151', marginBottom: '4px' }}>Description (optional)</label>
                  <input type="text" value={adjDesc} onChange={(e) => setAdjDesc(e.target.value)}
                    placeholder="e.g. Outstanding balance from Excel records"
                    style={{ width: '100%', padding: '8px 12px', borderRadius: '8px', border: '1px solid #d1d5db', fontSize: '14px', boxSizing: 'border-box' }} />
                </div>

                {/* Buttons */}
                <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end' }}>
                  <button onClick={() => setShowAdjDialog(false)}
                    style={{ padding: '8px 16px', background: '#f3f4f6', color: '#374151', border: 'none', borderRadius: '8px', fontSize: '14px', cursor: 'pointer' }}>
                    Cancel
                  </button>
                  <button onClick={submitAdjustment} disabled={adjSubmitting || !adjAmount}
                    style={{ padding: '8px 20px', background: '#111827', color: 'white', border: 'none', borderRadius: '8px', fontSize: '14px', fontWeight: 600, cursor: adjSubmitting ? 'wait' : 'pointer', opacity: adjSubmitting ? 0.7 : 1 }}>
                    {adjSubmitting ? 'Recording...' : 'Record Adjustment'}
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* Statement Content */}
          {data && (
            <div>
              <div style={{ background: 'white', borderRadius: '12px', padding: '32px', border: '1px solid #e5e7eb' }}>
                {/* Header: Title + Logo */}
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '16px' }}>
                  <div style={{ fontSize: '20px', fontWeight: 800, color: '#111827' }}>STATEMENT OF ACCOUNT</div>
                  <img src={config.logoUrl} alt="ZEEKR" style={{ height: '28px' }} />
                </div>

                {/* Zeekr info + Account info */}
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '20px', paddingBottom: '16px', borderBottom: '1px solid #e5e7eb' }}>
                  <div style={{ fontSize: '11px', color: '#6b7280', lineHeight: 1.7 }}>
                    Zeekr Intelligent Technology Australia Pty.<br />
                    Suite 6.03, 11 Khartoum Road,<br />
                    Macquarie Park, NSW 2113<br />
                    ABN: 19 675 714 039
                  </div>
                  <div style={{ fontSize: '12px', textAlign: 'right', lineHeight: 1.8 }}>
                    <div><span style={{ color: '#6b7280', fontWeight: 600 }}>ACCOUNT:</span> <span style={{ fontWeight: 600 }}>{data.dealer.dealer_name || data.dealer.company_name}</span></div>
                    <div><span style={{ color: '#6b7280', fontWeight: 600 }}>STATEMENT DATE:</span> {fmtDateAU(dateTo)}</div>
                    <div><span style={{ color: '#6b7280', fontWeight: 600 }}>CURRENCY:</span> AUD</div>
                    <div><span style={{ color: '#6b7280', fontWeight: 600 }}>STANDARD TERMS:</span> 15 DAYS FROM INV. DATE</div>
                  </div>
                </div>

                {/* Bill To / Ship To */}
                <div style={{ display: 'flex', gap: '32px', marginBottom: '20px' }}>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: '11px', fontWeight: 700, color: '#111827', marginBottom: '6px', borderBottom: '1px solid #e5e7eb', paddingBottom: '4px' }}>BILL TO</div>
                    <div style={{ fontSize: '12px', color: '#374151', lineHeight: 1.7 }}>
                      {data.dealer.dealer_code && <div>D#: {data.dealer.dealer_code}</div>}
                      <div style={{ fontWeight: 600 }}>{data.dealer.company_name}</div>
                      {data.dealer.abn && <div>ABN: {data.dealer.abn}</div>}
                      {data.dealer.dealer_name && <div>{data.dealer.dealer_name}</div>}
                      {data.dealer.address && <div>{data.dealer.address}</div>}
                      {data.dealer.ap_name && <div>Contact Person: {data.dealer.ap_name}</div>}
                      <div>
                        {data.dealer.ap_phone && <span>P: {data.dealer.ap_phone}&nbsp;&nbsp;</span>}
                        {data.dealer.ap_email && <span>E: {data.dealer.ap_email}</span>}
                      </div>
                    </div>
                  </div>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: '11px', fontWeight: 700, color: '#111827', marginBottom: '6px', borderBottom: '1px solid #e5e7eb', paddingBottom: '4px' }}>SHIP TO</div>
                    <div style={{ fontSize: '12px', color: '#374151', lineHeight: 1.7 }}>
                      {data.dealer.dealer_code && <div>D#: {data.dealer.dealer_code}</div>}
                      <div style={{ fontWeight: 600 }}>{data.dealer.dealer_name || data.dealer.company_name}</div>
                      {data.dealer.address && <div>{data.dealer.address}</div>}
                      {data.dealer.pm_name && <div>Contact Person: {data.dealer.pm_name}</div>}
                      <div>
                        {data.dealer.pm_phone && <span>P: {data.dealer.pm_phone}&nbsp;&nbsp;</span>}
                        {data.dealer.pm_email && <span>E: {data.dealer.pm_email}</span>}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Statement Transactions Table */}
                <div style={{ overflowX: 'auto' }}>
                  <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                      <tr>
                        <th style={{ ...thStyle, textAlign: 'center', width: '40px' }}>NO</th>
                        <th style={thStyle}>INVOICE NO.</th>
                        <th style={thStyle}>INVOICE DATE</th>
                        <th style={thStyle}>DESCRIPTION</th>
                        <th style={{ ...thStyle, textAlign: 'right' }}>INV. AMT. AUD</th>
                        <th style={{ ...thStyle, textAlign: 'right' }}>BALANCE AUD</th>
                        <th style={{ ...thStyle, textAlign: 'right' }}>TOTAL AUD</th>
                      </tr>
                    </thead>
                    <tbody>
                      {/* Opening Balance */}
                      <tr style={{ background: '#f9fafb' }}>
                        <td style={{ ...tdStyle, textAlign: 'center' }}>1</td>
                        <td style={tdStyle}></td>
                        <td style={tdStyle}></td>
                        <td style={{ ...tdStyle, fontWeight: 600 }}>Opening Balance</td>
                        <td style={tdStyle}></td>
                        <td style={tdStyle}></td>
                        <td style={{ ...tdStyle, textAlign: 'right', fontWeight: 600, fontFamily: 'monospace' }}>{fmtMoney(data.opening_balance)}</td>
                      </tr>

                      {/* Transaction Rows */}
                      {(() => {
                        let runBal = data.opening_balance
                        return data.transactions.map((tx, i) => {
                          const stmtAmt = -tx.amount
                          runBal += stmtAmt
                          return (
                            <tr key={tx.id} onMouseOver={(e) => (e.currentTarget.style.background = '#f9fafb')} onMouseOut={(e) => (e.currentTarget.style.background = '')}>
                              <td style={{ ...tdStyle, textAlign: 'center' }}>{i + 2}</td>
                              <td style={tdStyle}>
                                {tx.order_id ? (
                                  <a href={`/my-account/view-order/${tx.order_id}/`} style={{ color: '#3b82f6', textDecoration: 'none' }}>{tx.invoice_number}</a>
                                ) : ''}
                              </td>
                              <td style={{ ...tdStyle, whiteSpace: 'nowrap' }}>{fmtDateAU(tx.date)}</td>
                              <td style={tdStyle}>{tx.description}</td>
                              <td style={{ ...tdStyle, textAlign: 'right', fontFamily: 'monospace', color: stmtAmt < 0 ? '#16a34a' : undefined }}>
                                {fmtMoney(stmtAmt)}
                              </td>
                              <td style={{ ...tdStyle, textAlign: 'right', fontFamily: 'monospace', color: stmtAmt < 0 ? '#16a34a' : undefined }}>
                                {fmtMoney(stmtAmt)}
                              </td>
                              <td style={{ ...tdStyle, textAlign: 'right', fontWeight: 500, fontFamily: 'monospace' }}>
                                {fmtMoney(runBal)}
                              </td>
                            </tr>
                          )
                        })
                      })()}

                      {data.transactions.length === 0 && (
                        <tr><td colSpan={7} style={{ ...tdStyle, textAlign: 'center', color: '#9ca3af', padding: '32px 12px' }}>No transactions found for this period</td></tr>
                      )}
                    </tbody>
                  </table>
                </div>

                {/* Footer */}
                <div style={{ display: 'flex', gap: '32px', marginTop: '20px', borderTop: '2px solid #111827', paddingTop: '16px' }}>
                  {/* Remarks */}
                  <div style={{ flex: 1, fontSize: '11px', color: '#6b7280', lineHeight: 1.6 }}>
                    <div style={{ fontWeight: 700, color: '#111827', marginBottom: '4px' }}>Remarks / Payment Instructions:</div>
                    <p>Customer shall pay to Zeekr all sums when due in accordance with the payment terms as stated on Zeekr's invoice. Please contact us within 7 days should there by any discrepancies. Please email remittance advice and account queries to: zeekr.parts.au@zeekrlife.com.</p>
                    <div style={{ fontWeight: 700, color: '#111827', marginTop: '8px', marginBottom: '4px' }}>Banking Details:</div>
                    <p>Bank Name: HSBC Bank Australia Limited<br />Account Name: ZEEKR INTELLIGENT TECH AU PL<br />BSB: 342-011<br />Account No: 168853001</p>
                  </div>

                  {/* Summary */}
                  <div style={{ width: '300px', flexShrink: 0 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: '12px', borderBottom: '1px solid #e5e7eb' }}>
                      <span style={{ color: '#6b7280' }}>Total Invoices (Period)</span>
                      <span style={{ fontFamily: 'monospace', fontWeight: 600 }}>{fmtMoney(data.summary.total_invoices)}</span>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: '12px', borderBottom: '1px solid #e5e7eb' }}>
                      <span style={{ color: '#6b7280' }}>Total Payments (Period)</span>
                      <span style={{ fontFamily: 'monospace', fontWeight: 600, color: '#16a34a' }}>-{fmtMoney(data.summary.total_payments)}</span>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: '12px', borderBottom: '1px solid #e5e7eb' }}>
                      <span style={{ fontWeight: 600, color: '#374151' }}>NET FOR PERIOD</span>
                      <span style={{ fontFamily: 'monospace', fontWeight: 600 }}>{fmtMoney(data.summary.net_period)}</span>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', fontSize: '12px', borderBottom: '1px solid #e5e7eb' }}>
                      <span style={{ fontWeight: 600, color: '#374151' }}>OVERDUE AT STATEMENT DATE</span>
                      <span style={{ fontFamily: 'monospace', fontWeight: 600, color: data.summary.overdue > 0 ? '#dc2626' : undefined }}>{fmtMoney(data.summary.overdue)}</span>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '10px 0', fontSize: '15px', borderTop: '2px solid #111827', marginTop: '4px' }}>
                      <span style={{ fontWeight: 800, color: '#111827' }}>TOTAL AUD</span>
                      <span style={{ fontWeight: 800, color: '#111827', fontFamily: 'monospace' }}>{fmtMoney(data.summary.closing_owing)}</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Buttons */}
              <div style={{ display: 'flex', gap: '12px', marginTop: '16px', justifyContent: 'flex-end' }}>
                <button onClick={downloadPDF} disabled={pdfGenerating}
                  style={{ display: 'flex', alignItems: 'center', gap: '6px', padding: '10px 20px', background: '#111827', color: 'white', border: 'none', borderRadius: '8px', fontSize: '14px', fontWeight: 600, cursor: pdfGenerating ? 'wait' : 'pointer', opacity: pdfGenerating ? 0.7 : 1 }}>
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                  {pdfGenerating ? 'Generating...' : 'Download PDF'}
                </button>
                {taxReport && (
                <button onClick={downloadExcel}
                  style={{ display: 'flex', alignItems: 'center', gap: '6px', padding: '10px 20px', background: '#16a34a', color: 'white', border: 'none', borderRadius: '8px', fontSize: '14px', fontWeight: 600, cursor: 'pointer' }}>
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                  Download Excel
                </button>
                )}
              </div>
            </div>
          )}

          {/* Empty State */}
          {!data && !loading && (
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} style={{ textAlign: 'center', padding: '60px 0', color: '#9ca3af' }}>
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" style={{ margin: '0 auto 12px', display: 'block', opacity: 0.4 }}>
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
              </svg>
              <p style={{ fontSize: '14px' }}>Select a dealer and date range to generate a statement</p>
            </motion.div>
          )}

          {loading && (
            <div style={{ textAlign: 'center', padding: '60px 0' }}>
              <div style={{ margin: '0 auto 12px', width: '32px', height: '32px', borderRadius: '50%', border: '2px solid #d1d5db', borderTopColor: '#111827', animation: 'spin 0.8s linear infinite' }}></div>
              <p style={{ color: '#9ca3af', fontSize: '14px' }}>Generating report...</p>
            </div>
          )}
        </motion.div>
      </div>
      <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
    </div>
  )
}

const root = document.getElementById('zeekr-statement-root')
if (root) {
  createRoot(root).render(
    <StrictMode>
      <ZeekrStatementPage />
    </StrictMode>
  )
}
