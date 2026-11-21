import type { Metadata } from "next";
import "ketcher-react/dist/index.css";
import "./globals.css";
import Body from "@/components/_core/Body";
import GoogleAnalytics from "@/components/_core/GoogleAnalytics";
import FloatingDock from "@/components/_core/layout/FloatingDock";
import ServiceWorkerRegistration from "@/components/_core/ServiceWorkerRegistration";
import DownloaderWidget from "@/components/downloader/DownloaderWidget";
import FeedbackWidget from "@/components/feedback/FeedbackWidget";
import { UserSession } from "@/lib/api/admin/interfaces/User";
import { Cookie } from "@/lib/api/cookies";
import { Suspense } from "react";
import { SITE_URL } from "@/lib/publicUrls";

export const metadata: Metadata = {
  // Canonical URLs and other relative metadata URLs resolve against the public site.
  metadataBase: new URL(SITE_URL),
  title: "MolMeDB",
  description: "Molecules on Membranes Database",
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const user: UserSession | undefined =
    (await Cookie.getUserData()) as UserSession;

  return (
    <html lang="en">
      <Body>
        <ServiceWorkerRegistration />
        <GoogleAnalytics />
        {children}
        <Suspense fallback={null}>
          <FloatingDock>
            <DownloaderWidget />
            <FeedbackWidget user={user} />
          </FloatingDock>
        </Suspense>
      </Body>
    </html>
  );
}
