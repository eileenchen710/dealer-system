import { useEffect, useRef } from 'react'

interface LiquidEtherProps {
  colors?: string[]
  mouseForce?: number
  cursorSize?: number
  resolution?: number
  autoDemo?: boolean
  autoSpeed?: number
  autoIntensity?: number
  className?: string
}

export default function LiquidEther({
  colors = ['#5227FF', '#FF9FFC', '#B19EEF'],
  mouseForce = 20,
  cursorSize = 100,
  autoDemo = true,
  autoSpeed = 0.5,
  autoIntensity = 2.2,
  resolution = 0.5,
  className = '',
}: LiquidEtherProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const mouseRef = useRef({ x: 0.5, y: 0.5, active: false })
  const targetRef = useRef({ x: 0.5, y: 0.5 })
  const blobsRef = useRef<Array<{ x: number; y: number; vx: number; vy: number; radius: number; color: string }>>([])

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return

    const ctx = canvas.getContext('2d')
    if (!ctx) return

    let animationFrameId: number
    let time = 0

    const resize = () => {
      const dpr = resolution
      const parent = canvas.parentElement
      const width = parent?.clientWidth || window.innerWidth
      const height = parent?.clientHeight || window.innerHeight
      canvas.width = width * dpr
      canvas.height = height * dpr
      canvas.style.width = '100%'
      canvas.style.height = '100%'
      ctx.scale(dpr, dpr)
    }

    resize()
    window.addEventListener('resize', resize)

    const parseColor = (hex: string) => {
      const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex)
      return result ? {
        r: parseInt(result[1], 16),
        g: parseInt(result[2], 16),
        b: parseInt(result[3], 16)
      } : { r: 82, g: 39, b: 255 }
    }

    const parsedColors = colors.map(parseColor)

    const numBlobs = 8
    blobsRef.current = Array.from({ length: numBlobs }, (_, i) => ({
      x: Math.random() * window.innerWidth,
      y: Math.random() * window.innerHeight,
      vx: (Math.random() - 0.5) * 2,
      vy: (Math.random() - 0.5) * 2,
      radius: 150 + Math.random() * 200,
      color: colors[i % colors.length],
    }))

    const lerp = (a: number, b: number, t: number) => a + (b - a) * t

    const draw = () => {
      const parent = canvas.parentElement
      const width = parent?.clientWidth || window.innerWidth
      const height = parent?.clientHeight || window.innerHeight

      ctx.fillStyle = 'rgb(6, 0, 16)'
      ctx.fillRect(0, 0, width, height)

      if (autoDemo && !mouseRef.current.active) {
        targetRef.current = {
          x: 0.5 + Math.sin(time * autoSpeed) * 0.3 * autoIntensity,
          y: 0.5 + Math.cos(time * autoSpeed * 0.7) * 0.3 * autoIntensity
        }
      }

      mouseRef.current.x = lerp(mouseRef.current.x, targetRef.current.x, 0.03)
      mouseRef.current.y = lerp(mouseRef.current.y, targetRef.current.y, 0.03)

      blobsRef.current.forEach((blob, i) => {
        const dx = mouseRef.current.x * width - blob.x
        const dy = mouseRef.current.y * height - blob.y
        const dist = Math.sqrt(dx * dx + dy * dy)

        if (dist > 0) {
          blob.vx += (dx / dist) * 0.1 * (mouseForce / 20)
          blob.vy += (dy / dist) * 0.1 * (mouseForce / 20)
        }

        blob.vx += Math.sin(time * 0.5 + i) * 0.05
        blob.vy += Math.cos(time * 0.3 + i * 0.7) * 0.05

        blob.vx *= 0.98
        blob.vy *= 0.98

        blob.x += blob.vx
        blob.y += blob.vy

        if (blob.x < 0 || blob.x > width) blob.vx *= -1
        if (blob.y < 0 || blob.y > height) blob.vy *= -1

        blob.x = Math.max(0, Math.min(width, blob.x))
        blob.y = Math.max(0, Math.min(height, blob.y))

        const gradient = ctx.createRadialGradient(
          blob.x, blob.y, 0,
          blob.x, blob.y, blob.radius
        )

        const color = parsedColors[i % parsedColors.length]
        gradient.addColorStop(0, `rgba(${color.r}, ${color.g}, ${color.b}, 0.8)`)
        gradient.addColorStop(0.5, `rgba(${color.r}, ${color.g}, ${color.b}, 0.3)`)
        gradient.addColorStop(1, 'rgba(0, 0, 0, 0)')

        ctx.fillStyle = gradient
        ctx.beginPath()
        ctx.arc(blob.x, blob.y, blob.radius, 0, Math.PI * 2)
        ctx.fill()
      })

      const centerGradient = ctx.createRadialGradient(
        mouseRef.current.x * width,
        mouseRef.current.y * height,
        0,
        mouseRef.current.x * width,
        mouseRef.current.y * height,
        cursorSize * 3
      )
      centerGradient.addColorStop(0, 'rgba(82, 39, 255, 0.4)')
      centerGradient.addColorStop(0.5, 'rgba(255, 159, 252, 0.2)')
      centerGradient.addColorStop(1, 'rgba(0, 0, 0, 0)')

      ctx.fillStyle = centerGradient
      ctx.fillRect(0, 0, width, height)

      const imageData = ctx.getImageData(0, 0, width * resolution, height * resolution)
      const data = imageData.data
      for (let i = 0; i < data.length; i += 4) {
        const noise = (Math.random() - 0.5) * 8
        data[i] = Math.max(0, Math.min(255, data[i] + noise))
        data[i + 1] = Math.max(0, Math.min(255, data[i + 1] + noise))
        data[i + 2] = Math.max(0, Math.min(255, data[i + 2] + noise))
      }
      ctx.putImageData(imageData, 0, 0)

      time += 0.016
      animationFrameId = requestAnimationFrame(draw)
    }

    const handleMouseMove = (e: MouseEvent) => {
      mouseRef.current.active = true
      targetRef.current = {
        x: e.clientX / window.innerWidth,
        y: e.clientY / window.innerHeight
      }

      setTimeout(() => {
        mouseRef.current.active = false
      }, 3000)
    }

    window.addEventListener('mousemove', handleMouseMove)

    draw()

    return () => {
      window.removeEventListener('resize', resize)
      window.removeEventListener('mousemove', handleMouseMove)
      cancelAnimationFrame(animationFrameId)
    }
  }, [colors, mouseForce, cursorSize, autoDemo, autoSpeed, autoIntensity, resolution])

  return (
    <canvas
      ref={canvasRef}
      className={`absolute inset-0 ${className}`}
      style={{ background: 'rgb(6, 0, 16)' }}
    />
  )
}
