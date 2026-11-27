import SimpleSiteHeader from "@/components/_core/layout/SimpleSiteHeader";
import SiteContent from "@/components/_core/layout/SiteContent";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import Link from "next/link";
import { MdOutlineComputer } from "react-icons/md";
import PredictionDatasetClient from "./client";
import { IPredictionDataset } from "@/lib/api/admin/interfaces/Predictions";
import { getViewData } from "@/lib/api/frontend";
import Error404 from "@/components/_core/layout/errors/NotFound404";
import { Cookie } from "@/lib/api/cookies";
import { UserSession } from "@/lib/api/admin/interfaces/User";

export default async function CalculationDetailPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ token?: string }>;
}) {
  const [param, sp] = await Promise.all([params, searchParams]);
  const token = sp?.token ?? undefined;

  // Prevent admin Sanctum session cookie from bleeding through for unauthenticated users.
  // Without this check, Next.js server actions forward the browser's laravel_session to
  // Laravel even when the frontend considers the user logged out.
  const user = (await Cookie.getUserData()) as UserSession | null;
  const isLoggedIn = Boolean(user?.id);

  if (!token && !isLoggedIn) {
    return (
      <>
        <SimpleSiteHeader>
          <div className="h-full w-full flex flex-col justify-end">
            <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
              <MdOutlineComputer className="text-3xl xl:text-4xl" />
              <h1 className="text-2xl md:text-3xl font-bold">
                Laboratory - Dataset [ID: {param.id}]
              </h1>
            </div>
          </div>
        </SimpleSiteHeader>
        <div className="min-h-screen">
          <Error404 />
        </div>
        <SiteFooter />
      </>
    );
  }

  const data: IPredictionDataset = (
    await getViewData(
      "/predictions/datasets/" + param.id,
      token ? { token } : {},
      `/lab/running-predictions/${encodeURIComponent(param.id)}`,
    )
  )?.data?.data;

  return (
    <>
      <SimpleSiteHeader>
        <div className="h-full w-full flex flex-col justify-end">
          <div className="flex flex-row items-center justify-start gap-6 lg:gap-8">
            <MdOutlineComputer className="text-3xl xl:text-4xl" />
            <div className="flex flex-col justify-center lg:gap-1">
              <h1 className="text-2xl md:text-3xl font-bold">
                Laboratory - Dataset [ID: {param.id}]
              </h1>
              <div className="flex flex-row gap-2 items-center">
                <Link href="/lab" className="text-sm underline">
                  Laboratory
                </Link>
                {" >> "}
                <Link
                  href="/lab/running-predictions"
                  className="text-sm underline"
                >
                  Predictions
                </Link>
                {" >> "}
                <div className="text-sm text-white/70">
                  Dataset [ID: {param.id}]
                </div>
              </div>
            </div>
          </div>
        </div>
      </SimpleSiteHeader>
      <div className="min-h-screen">
        {!data ? (
          <Error404 />
        ) : (
          <SiteContent
            className="bg-zinc-100"
            classNameChildren="flex flex-col gap-16 min-h-screen"
          >
            <PredictionDatasetClient data={data} token={token} />
          </SiteContent>
        )}
      </div>
      <SiteFooter />
    </>
  );
}
