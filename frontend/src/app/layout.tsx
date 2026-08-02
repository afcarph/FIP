import type { Metadata, Viewport } from 'next';
import { Inter, JetBrains_Mono } from 'next/font/google';

import { Providers } from '@/app/providers';

import './globals.css';

const inter = Inter({
  subsets: ['latin'],
  variable: '--font-sans',
  display: 'swap',
});

const mono = JetBrains_Mono({
  subsets: ['latin'],
  variable: '--font-mono',
  display: 'swap',
});

export const metadata: Metadata = {
  title: {
    default: 'Fuel Intelligence Platform',
    template: '%s · FIP',
  },
  description:
    'AI-powered fuel price intelligence, prediction and expense optimisation for Philippine motorists and fleets.',
  keywords: ['fuel prices', 'Philippines', 'DOE', 'fleet management', 'gas stations'],
  authors: [{ name: 'Fuel Intelligence Platform' }],
  manifest: '/manifest.json',
  openGraph: {
    type: 'website',
    locale: 'en_PH',
    siteName: 'Fuel Intelligence Platform',
    title: 'Fuel Intelligence Platform',
    description: 'Know the price before you drive there.',
  },
  robots: { index: true, follow: true },
};

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  maximumScale: 5,          // never block pinch-zoom; it is an accessibility need
  themeColor: [
    { media: '(prefers-color-scheme: light)', color: '#f8fafc' },
    { media: '(prefers-color-scheme: dark)', color: '#0b1220' },
  ],
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning className={`${inter.variable} ${mono.variable}`}>
      <body className="min-h-screen bg-background font-sans">
        {/* Keyboard users should be able to jump the navigation. */}
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground"
        >
          Skip to content
        </a>
        <Providers>{children}</Providers>
      </body>
    </html>
  );
}
