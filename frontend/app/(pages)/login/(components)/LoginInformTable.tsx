"use client";
import { UserSession } from "@/lib/api/admin/interfaces/User";
import { AvatarIcon, Button } from "@heroui/react";
import Link from "next/link";
import { IoMdLogOut } from "react-icons/io";

export default function LoginInformTable(props: {
  user?: UserSession;
  /** Where the visitor was going, already checked by safeRedirectPath. */
  redirectTo?: string;
}) {
  return (
    <div className="flex flex-col gap-4">
      <Button
        as={Link}
        href={props.redirectTo ?? "/"}
        variant="bordered"
        startContent={<AvatarIcon />}
        size="lg"
        className="cursor-pointer"
      >
        Continue as
        <label className="text-success cursor-pointer">
          {props.user?.email}
        </label>
      </Button>
      <Button
        // A plain anchor: the router would request the target of a
        // next/link in advance, and with it sign the user out.
        as="a"
        className="w-full"
        href="/api/logout"
        color="danger"
        startContent={<IoMdLogOut size={25} />}
        size="md"
      >
        Logout
      </Button>
    </div>
  );
}
