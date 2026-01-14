import { useMemo } from 'react'

interface GradientTextProps {
  children: React.ReactNode
  colors?: string[]
  animationSpeed?: number
  className?: string
}

export default function GradientText({
  children,
  colors = ['#111827', '#6b7280', '#9ca3af', '#374151', '#6b7280', '#111827'],
  animationSpeed = 4,
  className = '',
}: GradientTextProps) {
  const gradientStyle = useMemo(() => {
    const gradient = `linear-gradient(135deg, ${colors.join(', ')})`
    return {
      background: gradient,
      backgroundSize: '200% 200%',
      WebkitBackgroundClip: 'text',
      WebkitTextFillColor: 'transparent',
      backgroundClip: 'text',
      animation: `gradientShift ${animationSpeed}s ease-in-out infinite`,
      display: 'inline-block',
    }
  }, [colors, animationSpeed])

  return (
    <>
      <style>{`
        @keyframes gradientShift {
          0%, 100% {
            background-position: 0% 50%;
          }
          50% {
            background-position: 100% 50%;
          }
        }
      `}</style>
      <span className={className} style={gradientStyle}>
        {children}
      </span>
    </>
  )
}
