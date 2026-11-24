import { SiteMenu } from "@/components/_core/layout/SiteMenu";
import LoginForm from "./(components)/LoginForm";
import { Metadata } from "next";
import Image from "next/image";
import SiteFooter from "@/components/_core/layout/SiteFooter";
import SimpleSiteHeader from "@/components/_core/layout/SimpleSiteHeader";
import Link from "next/link";
import { UserSession } from "@/lib/api/admin/interfaces/User";
import { Cookie } from "@/lib/api/cookies";
import LoginInformTable from "./(components)/LoginInformTable";
import SiteNotifications from "@/components/_core/layout/SiteNotifications";
import { safeRedirectPath } from "@/utils/safeRedirect";

export const metadata: Metadata = {
  title: "Login | MolMeDB",
  description: "",
};

type PageProps = {
  searchParams?: Promise<Record<string, string | string[] | undefined>>;
};

export default async function LoginPage({ searchParams }: PageProps) {
  const user: UserSession | undefined =
    (await Cookie.getUserData()) as UserSession;

  const getParams = await searchParams;

  const isVerified = getParams?.verified === "1";
  const verifiedEmail = getParams?.email as string | undefined;
  const isExpired = getParams?.expired == "1";
  const redirectTo = safeRedirectPath(getParams?.redirect?.toString());
  
  let notification = null;

  if(isExpired){
     notification = <SiteNotifications
      notifications={[
        {
          title: "Your login expired due to inactivity.",
          type: "warning",
          message: (
            <label>
              Please, log in again.
            </label>
          ),
        },
      ]}
    />
  }
  else if (isVerified && verifiedEmail) {
    notification = <SiteNotifications
          notifications={[
            {
              title: "Email verified!",
              type: "success",
              message: (
                <label>
                  Your email address <strong>{verifiedEmail}</strong> is
                  verified. Now, you can log in.
                </label>
              ),
            },
          ]}
        />
  }

  return (
    <>
      {notification}
      <SiteMenu isLogoClickable />
      <SimpleSiteHeader>
        <></>
      </SimpleSiteHeader>
      <Main user={isExpired ? undefined : user} defaultEmail={isExpired ? undefined : verifiedEmail} redirectTo={redirectTo}/>
      <SiteFooter />
    </>
  );
}

function Main(props: { user?: UserSession; defaultEmail?: string; redirectTo?: string}) {
  return (
    <main className="p-8 lg:p-20 mt-12 max-h-screen max-w-screen-lg w-full mx-auto flex-1 flex flex-col justify-center items-center">
      <div className="max-h-[800px] max-w-[1200px] shadow-2xl rounded-xl bg-white dark:bg-background-dark flex flex-col lg:flex-row">
        <div className="h-full lg:w-full p-8 lg:p-4 lg:py-16 rounded-xl flex flex-col justify-between items-center gap-4">
          <div className="w-full flex flex-start -mt-10">
            <Link href="/" className="text-sm text-primary hover:underline">
              &larr; Home
            </Link>
          </div>
          {props.user?.id ? (
            <LoginInformTable user={props.user} redirectTo={props.redirectTo} />
          ) : (
            <>
              <Image
                src="/assets/layout/logo/molmedb-dark.svg"
                alt="Pokusnice"
                priority
                width={0}
                height={0}
                sizes="100vw"
                className="w-5/6 sm:w-4/6 md:w-1/3 dark:hidden"
              />
              <Image
                src="/assets/layout/logo/molmedb-white.svg"
                alt="Pokusnice"
                priority
                width={0}
                height={0}
                sizes="100vw"
                className="w-5/6 sm:w-4/6 md:w-1/2 hidden dark:block"
              />
              <h2 className="font-semibold text-3xl">Welcome back</h2>
              <div className="flex flex-col justify-center items-center text-sm text-foreground/70">
                <p>Glad to see you again!</p>
                <p>Login to your account below</p>
              </div>
              <div className="flex flex-col items-center gap-2 w-full h-1/2 lg:h-[60%]">
                <LoginForm defaultEmail={props.defaultEmail} redirectTo={props.redirectTo} />
              </div>
            </>
          )}
        </div>
      </div>
    </main>
  );
}
