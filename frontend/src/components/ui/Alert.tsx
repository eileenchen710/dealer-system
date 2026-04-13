import * as React from "react"

const Alert = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement> & { variant?: 'default' | 'destructive' }
>(({ className = '', variant = 'default', style, ...props }, ref) => {
  const variantStyles = variant === 'destructive'
    ? "border-red-200 bg-red-50 text-red-900"
    : "border-green-200 bg-green-50 text-green-900"

  return (
    <div
      ref={ref}
      role="alert"
      className={`relative w-full rounded-xl border text-sm flex items-start gap-3 shadow-lg ${variantStyles} ${className}`}
      style={{ padding: '16px', ...style }}
      {...props}
    />
  )
})
Alert.displayName = "Alert"

const AlertTitle = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLHeadingElement>
>(({ className = '', ...props }, ref) => (
  <h5
    ref={ref}
    className={`font-medium leading-none tracking-tight ${className}`}
    {...props}
  />
))
AlertTitle.displayName = "AlertTitle"

const AlertDescription = React.forwardRef<
  HTMLParagraphElement,
  React.HTMLAttributes<HTMLParagraphElement>
>(({ className = '', ...props }, ref) => (
  <div
    ref={ref}
    className={`text-sm opacity-90 ${className}`}
    {...props}
  />
))
AlertDescription.displayName = "AlertDescription"

export { Alert, AlertTitle, AlertDescription }
