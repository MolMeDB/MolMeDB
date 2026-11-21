"use server";
import Site from "@/components/_core/layout/Site";
import IntroductionSection from "./intro/section/introduction";
import HowInteracts from "./intro/section/howInteract";
import InteroperabilitySection from "./intro/section/interoperability";
import StatsSection from "./intro/section/stats";
import AccessibilitySection from "./intro/section/accessibility";
import LabSection from "./intro/section/lab";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import { SiteMenu } from "@/components/_core/layout/SiteMenu";
import { UserSession } from "@/lib/api/admin/interfaces/User";
import { Cookie } from "@/lib/api/cookies";
import { fetchPublicJsonLd, JsonLdScript } from "@/components/_core/JsonLd";

/**
 */
export default async function Intro() {
  const user: UserSession | undefined =
    (await Cookie.getUserData()) as UserSession;
  const datasetJsonLd = await fetchPublicJsonLd("about", 86400);

  return (
    <Site>
      <JsonLdScript data={datasetJsonLd} />
      <SiteMenu user={user} hideLogoOnTop />
      <IntroductionSection />
      <div className="h-12 w-full flex-1 bg-big-delimiter dark:bg-big-delimiter-dark" />
      <HowInteracts />
      <StatsSection />
      <AccessibilitySection />
      <InteroperabilitySection />
      <LabSection />
      <SiteFooter />
    </Site>
  );
}
