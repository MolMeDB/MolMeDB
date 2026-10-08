import SimpleSiteHeader from "@/components/_core/layout/SimpleSiteHeader";
import SiteContent from "@/components/_core/layout/SiteContent";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import SafeRenderer from "@/components/errors/safeRender";
import { FiDownload } from "react-icons/fi";
import DownloaderClient from "./client";

export default async function DownloaderPage() {
  return (
    <>
      <SimpleSiteHeader>
        <div className="h-full w-full flex flex-col justify-end">
          <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
            <FiDownload className="text-3xl xl:text-4xl" />
            <div className="flex flex-col justify-center gap-2 lg:gap-1">
              <h1 className="text-2xl md:text-3xl font-bold">Downloader</h1>
            </div>
          </div>
        </div>
      </SimpleSiteHeader>
      <SiteContent>
        {/* DownloaderClient calls useSearchParams(), which Next.js only
            allows behind a Suspense boundary - without one the page cannot
            be prerendered and `next build` fails. */}
        <SafeRenderer>
          <div className="min-h-screen flex flex-col gap-8 pb-16">
            <DownloaderClient />
          </div>
        </SafeRenderer>
      </SiteContent>
      <SiteFooter />
    </>
  );
}
