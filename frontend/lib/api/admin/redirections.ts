"use client";

import { useRouter, usePathname, useSearchParams } from "next/navigation";

export function useHandle401() {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  return () => {
    const currentUrl =
      pathname +
      (searchParams.toString() ? `?${searchParams}` : "") +
      window.location.hash;

    router.push(`/login?redirect=${encodeURIComponent(currentUrl)}&expired=1`);
  };
}