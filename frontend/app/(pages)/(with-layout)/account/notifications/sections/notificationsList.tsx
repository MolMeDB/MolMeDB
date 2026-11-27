"use client";

import { getJson } from "@/lib/api/admin";
import { useHandle401 } from "@/lib/api/admin/redirections";
import { htmlToText } from "@/utils/htmlToText";
import { Card, CardBody, Spinner } from "@heroui/react";
import Link from "next/link";
import { useEffect, useState } from "react";
import { UserNotificationSummary } from "../types";

/**
 * The latest notifications of the signed-in user, each leading to its page
 * with the whole message.
 */
export default function NotificationsList() {
  const [notifications, setNotifications] = useState<
    UserNotificationSummary[] | null
  >(null);
  const [failed, setFailed] = useState(false);
  const handle401 = useHandle401();

  useEffect(() => {
    (async () => {
      const response = await getJson("/api/notifications", "limit=100");

      if (response?.code === 200) {
        setNotifications(
          (response.data?.data as UserNotificationSummary[]) ?? [],
        );
      } else if (response?.code === 401) {
        handle401();
      } else {
        setFailed(true);
      }
    })();
    // Loads once: handle401 is a new function on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (failed) {
    return (
      <p className="text-danger">The notifications could not be loaded.</p>
    );
  }

  if (notifications === null) {
    return (
      <div className="flex justify-center py-8">
        <Spinner aria-label="Loading notifications" />
      </div>
    );
  }

  if (notifications.length === 0) {
    return <p className="text-foreground-500">No notifications.</p>;
  }

  return (
    <ul className="flex flex-col gap-3" aria-label="Notifications">
      {notifications.map((notification) => (
        <li key={notification.id}>
          <Link
            href={`/account/notifications/${notification.id}`}
            className="block rounded-large focus-visible:outline-2 focus-visible:outline-primary"
          >
            <Card
              shadow="sm"
              className={
                "w-full hover:bg-default-100 " +
                (notification.state === "new"
                  ? "bg-primary-50 dark:bg-primary-900/20"
                  : "")
              }
            >
              <CardBody className="flex flex-col gap-1">
                <span className="font-semibold">{notification.title}</span>
                <span className="text-sm text-foreground-600 line-clamp-2">
                  {htmlToText(notification.body)}
                </span>
                {notification.created_at ? (
                  <span className="text-xs text-foreground-400">
                    {new Date(notification.created_at).toLocaleString()}
                  </span>
                ) : null}
              </CardBody>
            </Card>
          </Link>
        </li>
      ))}
    </ul>
  );
}
