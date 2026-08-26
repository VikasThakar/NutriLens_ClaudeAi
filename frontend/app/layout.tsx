import type { Metadata, Viewport } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import type * as React from "react";

import { AuthProvider } from "@/components/auth/auth-provider";
import { ThemeProvider } from "@/components/layout/theme-provider";
import { Toaster } from "@/components/ui/sonner";
import { env } from "@/lib/env";

import "./globals.css";

const geistSans = Geist({
  variable: "--font-sans",
  subsets: ["latin"],
  display: "swap",
});

const geistMono = Geist_Mono({
  variable: "--font-mono",
  subsets: ["latin"],
  display: "swap",
});

export const metadata: Metadata = {
  metadataBase: new URL(env.appUrl),
  title: {
    default: "NutriLens — Snap your food. See your nutrition.",
    template: "%s · NutriLens",
  },
  description:
    "Photograph your meal and let NutriLens estimate calories, protein, carbohydrates, fat and portions across every item on the plate.",
  applicationName: "NutriLens",
  keywords: [
    "nutrition tracking",
    "macro tracker",
    "AI food recognition",
    "calorie counter",
  ],
  openGraph: {
    title: "NutriLens — Snap your food. See your nutrition.",
    description:
      "AI-powered macronutrient tracking. Photograph your meal, review the estimate, and track your progress.",
    type: "website",
    siteName: "NutriLens",
  },
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#fbfdfb" },
    { media: "(prefers-color-scheme: dark)", color: "#0d1512" },
  ],
  width: "device-width",
  initialScale: 1,
  maximumScale: 5,
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html
      lang="en"
      suppressHydrationWarning
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      <body className="flex min-h-full flex-col">
        <ThemeProvider>
          <AuthProvider>
            {children}
            <Toaster position="top-center" richColors closeButton />
          </AuthProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}