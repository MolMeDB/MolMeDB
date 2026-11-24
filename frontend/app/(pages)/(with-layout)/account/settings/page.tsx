import SimpleSiteHeader from "@/components/_core/layout/SimpleSiteHeader";
import SiteContent from "@/components/_core/layout/SiteContent";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import { Cookie } from "@/lib/api/cookies";
import { UserSession } from "@/lib/api/admin/interfaces/User";
import { redirect } from "next/navigation";
import { IoSettingsOutline } from "react-icons/io5";
import AccountSettingsForm from "./sections/accountSettingsForm";
import NotificationPreferencesForm from "./sections/notificationPreferencesForm";

export default async function AccountSettingsPage() {
  const user: UserSession | undefined =
    (await Cookie.getUserData()) as UserSession;

  if (!user?.id) {
    // Back here after signing in, not to the default page of the login.
    redirect(`/login?redirect=${encodeURIComponent("/account/settings")}`);
  }

  return (
    <>
      <SimpleSiteHeader>
        <div className="h-full w-full flex flex-col justify-end">
          <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
            <IoSettingsOutline className="text-3xl xl:text-4xl" />
            <div className="flex flex-col justify-center lg:gap-1">
              <h1 className="text-2xl md:text-3xl font-bold">
                Account settings
              </h1>
            </div>
          </div>
        </div>
      </SimpleSiteHeader>
      <div className="min-h-screen pb-16">
        <SiteContent
          className="min-h-screen"
          classNameChildren="flex flex-col gap-8"
        >
          <AccountSettingsForm email={user.email ?? ""} />
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div className="lg:col-span-8 lg:col-start-5 xl:col-span-9 xl:col-start-4">
              <NotificationPreferencesForm />
            </div>
          </div>
        </SiteContent>
      </div>
      <SiteFooter />
    </>
  );
}
