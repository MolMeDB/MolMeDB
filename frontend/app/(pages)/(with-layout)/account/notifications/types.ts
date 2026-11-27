export type UserNotificationSummary = {
  id: number;
  state: "new" | "read";
  title: string;
  body: string;
  created_at?: string | null;
  read_at?: string | null;
};

export type UserNotificationDetail = UserNotificationSummary & {
  /** HTML, as the e-mail carries it. */
  message: string;
  /** The page the notification is about. */
  url: string | null;
};
