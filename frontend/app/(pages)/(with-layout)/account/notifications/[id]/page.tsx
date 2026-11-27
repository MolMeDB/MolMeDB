import { Cookie } from "@/lib/api/cookies";
import { notFound, redirect } from "next/navigation";
import NotificationDetail from "../sections/notificationDetail";
import NotificationsLayout from "../sections/notificationsLayout";

/**
 * Where a push opens: one notification in full. Signed out, the login comes
 * first and leads back here.
 */
export default async function NotificationPage(props: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await props.params;

  if (!/^[1-9]\d{0,17}$/.test(id)) {
    notFound();
  }

  const user = await Cookie.getUserData();

  if (!user?.id) {
    redirect(
      `/login?redirect=${encodeURIComponent(`/account/notifications/${id}`)}`,
    );
  }

  return (
    <NotificationsLayout title="Notification">
      <NotificationDetail id={Number(id)} />
    </NotificationsLayout>
  );
}
