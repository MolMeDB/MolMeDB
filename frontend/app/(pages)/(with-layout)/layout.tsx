import Site from "@/components/_core/layout/Site";
import SiteContent from "@/components/_core/layout/SiteContent";
import { SiteMenu } from "@/components/_core/layout/SiteMenu";
import PushNotifications from "@/components/notifications/PushNotifications";

export default function RootLayout(
  props: Readonly<{
    children: React.ReactNode;
  }>
) {
  return (
    <Site>
      <SiteMenu isLogoClickable />
      {props.children}
      <PushNotifications />
    </Site>
  );
}
