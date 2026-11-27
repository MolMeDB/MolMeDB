import SimpleSiteHeader from "@/components/_core/layout/SimpleSiteHeader";
import SiteContent from "@/components/_core/layout/SiteContent";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import { FiBell } from "react-icons/fi";

export default function NotificationsLayout(props: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <>
      <SimpleSiteHeader>
        <div className="h-full w-full flex flex-col justify-end">
          <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
            <FiBell className="text-3xl xl:text-4xl" />
            <h1 className="text-2xl md:text-3xl font-bold">{props.title}</h1>
          </div>
        </div>
      </SimpleSiteHeader>
      <div className="min-h-screen pb-16">
        <SiteContent className="min-h-screen" classNameChildren="max-w-3xl">
          {props.children}
        </SiteContent>
      </div>
      <SiteFooter />
    </>
  );
}
