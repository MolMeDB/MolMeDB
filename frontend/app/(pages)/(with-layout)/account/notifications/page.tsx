import { Cookie } from "@/lib/api/cookies";
import { redirect } from "next/navigation";
import NotificationsLayout from "./sections/notificationsLayout";
import NotificationsList from "./sections/notificationsList";

export default async function NotificationsPage() {
  const user = await Cookie.getUserData();

  if (!user?.id) {
    redirect(`/login?redirect=${encodeURIComponent("/account/notifications")}`);
  }

  return (
    <NotificationsLayout title="Notifications">
      <NotificationsList />
    </NotificationsLayout>
  );
}
