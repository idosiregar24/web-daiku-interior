"use client"

import { useTheme } from "next-themes"
import { Toaster as Sonner, type ToasterProps } from "sonner"
import { CircleCheckIcon, InfoIcon, TriangleAlertIcon, OctagonXIcon, Loader2Icon } from "lucide-react"

/** A status tint over the card colour — same recipe as `bg-success/10` chips, light and dark. */
const tint = (color: string, amount: number) => `color-mix(in oklch, ${color} ${amount}%, var(--card))`

const Toaster = ({ ...props }: ToasterProps) => {
  const { theme = "system" } = useTheme()

  return (
    <Sonner
      theme={theme as ToasterProps["theme"]}
      className="toaster group"
      icons={{
        success: (
          <CircleCheckIcon className="size-4" />
        ),
        // "info" = a notification that asks for action — Daiku yellow, not Sonner's blue.
        info: (
          <InfoIcon className="size-4 text-daiku-yellow-dark" />
        ),
        warning: (
          <TriangleAlertIcon className="size-4" />
        ),
        error: (
          <OctagonXIcon className="size-4" />
        ),
        loading: (
          <Loader2Icon className="size-4 animate-spin" />
        ),
      }}
      style={
        {
          // Daiku palette (resources/css/tokens.css) instead of Sonner's own richColors
          // and system font.
          fontFamily: "var(--font-sans)",
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "var(--radius)",
          "--success-bg": tint("var(--success)", 10),
          "--success-border": tint("var(--success)", 35),
          "--success-text": "var(--success-ink)",
          "--info-bg": tint("var(--color-daiku-yellow)", 14),
          "--info-border": "var(--color-daiku-yellow)",
          "--info-text": "var(--foreground)",
          "--warning-bg": tint("var(--warning)", 12),
          "--warning-border": tint("var(--warning)", 45),
          "--warning-text": "var(--warning-ink)",
          "--error-bg": tint("var(--error)", 10),
          "--error-border": tint("var(--error)", 35),
          "--error-text": "var(--error-ink)",
        } as React.CSSProperties
      }
      toastOptions={{
        classNames: {
          toast: "cn-toast shadow-md",
          title: "font-semibold",
          description: "text-foreground/80!",
          // "Buka" on a notification — the app's primary (yellow) button.
          actionButton: "bg-primary! text-primary-foreground! font-medium! hover:bg-daiku-yellow-dark!",
          cancelButton: "bg-muted! text-foreground!",
          closeButton: "bg-card! text-muted-foreground! border-border! hover:text-foreground!",
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
