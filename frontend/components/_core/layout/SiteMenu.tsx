"use client";

import {
  Avatar,
  Button,
  Dropdown,
  DropdownItem,
  DropdownMenu,
  DropdownSection,
  DropdownTrigger,
  Kbd,
  Navbar,
  NavbarBrand,
  NavbarContent,
  NavbarItem,
  NavbarMenu,
  NavbarMenuItem,
  NavbarMenuToggle,
} from "@heroui/react";
import SiteLogoLink from "./SiteLogoLink";
import Link from "next/link";
import { useEffect, useState } from "react";
import type { ReactNode } from "react";
import { FaMagnifyingGlass } from "react-icons/fa6";
import SearchEngine from "../providers/searchEngine";
import { MdOutlineLayers, MdPeopleOutline } from "react-icons/md";
import { BsBoxes } from "react-icons/bs";
import { FaUserAlt } from "react-icons/fa";
import { UserSession } from "@/lib/api/admin/interfaces/User";
import SiteMenuUser from "./SiteMenuUser";
import { IoMdLogOut } from "react-icons/io";
import { IoSettingsOutline } from "react-icons/io5";
import SiteNotificationsBell from "./notifications/SiteNotificationsBell";
// import { UserSession } from "@/lib/api/admin/interfaces/user";

const browseMenuItems = [
  {
    key: "membranes",
    href: "/browse/membranes",
    title: "Membranes",
    icon: <MdOutlineLayers size={25} />,
  },
  {
    key: "methods",
    href: "/browse/methods",
    title: "Methods",
    icon: <FaMagnifyingGlass className="p-0.5" size={24} />,
  },
  {
    key: "proteins",
    href: "/browse/proteins",
    title: "Proteins",
    icon: <BsBoxes size={23} />,
  },
  {
    key: "datasets",
    href: "/browse/datasets",
    title: "Datasets",
    icon: <MdPeopleOutline size={23} />,
  },
];

const mainMenuItems = [
  {
    href: "/stats",
    title: "Statistics",
  },
  {
    href: "/lab",
    title: "Lab",
  },
  {
    href: "/docs",
    title: "Documentation",
  },
];

export function SiteMenu(props: {
  user?: UserSession;
  hideLogoOnTop?: boolean;
  hideMenu?: boolean;
  isLogoClickable?: boolean;
}) {
  const [isScrolled, setIsScrolled] = useState(false);
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const [isVisibleSE, setIsVisibleSE] = useState(false);

  useEffect(() => {
    const handleScroll = () => {
      setIsScrolled(window.scrollY > 50);
    };
    window.addEventListener("scroll", handleScroll);
    return () => window.removeEventListener("scroll", handleScroll);
  }, []);

  return (
    <Navbar
      classNames={{
        base: `h-16 -mb-16 transition-all duration-500 ease-in-out text-white/80 ${
          isScrolled || isMenuOpen
            ? " text-foreground/80 dark:bg-background-menu-dark"
            : "!bg-transparent backdrop-blur-none backdrop-saturate-100"
        }`,
        brand: `${
          isScrolled || isMenuOpen ? "" : ""
        } flex justify-between lg:justify-start gap-8`,
        item: `${isScrolled || isMenuOpen ? "" : ""} font-bold`,
        wrapper: "max-w-screen xl:max-w-[1400px]",
        toggle: "lg:hidden h-10",
        toggleIcon: "h-full",
      }}
      isMenuOpen={isMenuOpen}
      onMenuOpenChange={setIsMenuOpen}
      disableAnimation
    >
      <NavbarBrand>
        {!props.hideMenu && (
          <NavbarMenuToggle
            aria-label={isMenuOpen ? "Close menu" : "Open menu"}
          />
        )}
        <h1 className="sr-only">MolMeDB</h1>
        {(!props.hideLogoOnTop || isScrolled || isMenuOpen) && (
          <SiteLogoLink
            isScrolled={isScrolled || isMenuOpen}
            isLink={props.isLogoClickable}
          />
        )}
      </NavbarBrand>
      {/* <NavbarContent className="hidden lg:flex gap-10">
        
      </NavbarContent> */}
      <NavbarContent
        as="div"
        className="hidden lg:flex items-center gap-10"
        justify="end"
      >
        <NavbarItem>
          <Dropdown>
            <DropdownTrigger className="cursor-pointer">
              <label
                className="tap-highlight-transparent font-semibold
        outline-none data-[focus-visible=true]:z-10 data-[focus-visible=true]:outline-2 
        data-[focus-visible=true]:outline-focus data-[focus-visible=true]:outline-offset-2 
        text-medium no-underline hover:underline hover:opacity-80 active:opacity-disabled 
        transition-opacity data-[active=true]:text-primary data-[active=true]:font-semibold
        w-full h-12"
              >
                Browse
              </label>
            </DropdownTrigger>
            <DropdownMenu>
              <DropdownSection showDivider>
                {browseMenuItems.slice(0, 3).map((item) => (
                  <DropdownItem
                    key={item.key}
                    as={Link}
                    href={item.href}
                    color="secondary"
                    textValue={item.title}
                    startContent={item.icon}
                    className="!no-underline"
                  >
                    <label className="md:text-md cursor-pointer">
                      {item.title}
                    </label>
                  </DropdownItem>
                ))}
              </DropdownSection>
              <DropdownSection>
                {browseMenuItems.slice(3).map((item) => (
                  <DropdownItem
                    key={item.key}
                    as={Link}
                    href={item.href}
                    textValue={item.title}
                    color="secondary"
                    startContent={item.icon}
                    className="!no-underline"
                  >
                    <label className="md:text-md cursor-pointer">
                      {item.title}
                    </label>
                  </DropdownItem>
                ))}
              </DropdownSection>
            </DropdownMenu>
          </Dropdown>
        </NavbarItem>
        {mainMenuItems.map((item) => (
          <NavbarItem key={item.href}>
            <MenuLink href={item.href} title={item.title} />
          </NavbarItem>
        ))}
        <div>
          <Button
            size="md"
            color={isScrolled ? "secondary" : "default"}
            startContent={<FaMagnifyingGlass size={18} />}
            onPress={() => setIsVisibleSE(true)}
            endContent={<Kbd keys={["command"]}>K</Kbd>}
          >
            Search
          </Button>
        </div>
        {props.user?.id ? (
          <div className="flex items-center gap-2">
            <SiteNotificationsBell />
            <SiteMenuUser user={props.user} />
          </div>
        ) : (
          <div>
            <Button
              as={Link}
              size="md"
              startContent={<FaUserAlt size={16} />}
              variant="solid"
              color="primary"
              href="/login"
              className={`
              rounded-full
            `}
            >
              Login
            </Button>
          </div>
        )}
        {/* <Input
          classNames={{
            base: "max-w-full sm:max-w-[10rem] h-10",
            mainWrapper: "h-full",
            input: "text-small",
            inputWrapper:
              "h-full font-normal text-default-500 bg-default-400/20 dark:bg-default-500/20",
          }}
          placeholder="Type to search..."
          size="sm"
          startContent={<SearchIcon size={18} />}
          type="search"
        /> */}
        {/* {props.user?.id ? (
          <Dropdown placement="bottom-end" className="">
            <DropdownTrigger className="cursor-pointer">
              <div>
                <UserDetailButton user={props.user} />
              </div>
            </DropdownTrigger>
            <DropdownMenu
              disabledKeys={["profile"]}
              aria-label="Profile Actions"
              variant="flat"
            >
              <DropdownItem key="profile" className="h-14 gap-1">
                <p className="font-semibold">Přihlášen(a) jako</p>
                <p className="font-semibold">{props.user.email}</p>
              </DropdownItem>
              <DropdownItem key="settings">Nastavení účtu</DropdownItem>
              <DropdownItem key="logout" color="danger" href="/logout">
                Odhlásit se
              </DropdownItem>
            </DropdownMenu>
          </Dropdown>
        ) : ( */}
        <Link href="/login">{/* <UserDetailButton /> */}</Link>
        {/* )} */}
      </NavbarContent>
      <NavbarMenu className="flex flex-col gap-1 px-5 pt-5 dark:bg-background-dark/95">
        <NavbarMenuItem>
          <Button
            fullWidth
            color="secondary"
            variant="flat"
            startContent={<FaMagnifyingGlass size={18} />}
            onPress={() => {
              setIsVisibleSE(true);
              setIsMenuOpen(false);
            }}
            className="justify-start h-12 text-base font-semibold"
          >
            Search
          </Button>
        </NavbarMenuItem>
        <NavbarMenuItem>
          <div className="pt-4 pb-1 text-xs font-bold uppercase tracking-wide text-foreground/50">
            Browse
          </div>
        </NavbarMenuItem>
        {browseMenuItems.map((item) => (
          <NavbarMenuItem key={item.href} onClick={() => setIsMenuOpen(false)}>
            <MenuItem href={item.href} title={item.title} icon={item.icon} />
          </NavbarMenuItem>
        ))}
        <NavbarMenuItem>
          <div className="pt-4 pb-1 text-xs font-bold uppercase tracking-wide text-foreground/50">
            MolMeDB
          </div>
        </NavbarMenuItem>
        {mainMenuItems.map((item) => (
          <NavbarMenuItem key={item.href} onClick={() => setIsMenuOpen(false)}>
            <MenuItem href={item.href} title={item.title} />
          </NavbarMenuItem>
        ))}
        <NavbarMenuItem>
          <div className="pt-4 pb-1 text-xs font-bold uppercase tracking-wide text-foreground/50">
            Account
          </div>
        </NavbarMenuItem>
        {props.user?.id ? (
          <>
            <NavbarMenuItem>
              <div className="flex items-center justify-between gap-3 rounded-lg py-2">
                <span className="text-lg font-semibold">Notifications</span>
                <SiteNotificationsBell />
              </div>
            </NavbarMenuItem>
            <NavbarMenuItem onClick={() => setIsMenuOpen(false)}>
              <MenuItem
                href="/account/settings"
                title="Account settings"
                icon={<IoSettingsOutline size={22} />}
              />
            </NavbarMenuItem>
            <NavbarMenuItem onClick={() => setIsMenuOpen(false)}>
              <MenuItem
                href="/api/logout"
                plainAnchor
                title="Log out"
                icon={<IoMdLogOut size={22} />}
                color="danger"
              />
            </NavbarMenuItem>
          </>
        ) : (
          <NavbarMenuItem onClick={() => setIsMenuOpen(false)}>
            <MenuItem
              href="/login"
              title="Login"
              icon={<FaUserAlt size={18} />}
              color="primary"
            />
          </NavbarMenuItem>
        )}
      </NavbarMenu>
      <SearchEngine
        isOpenSE={isVisibleSE}
        onClose={() => setIsVisibleSE(false)}
      />
    </Navbar>
  );
}

const MenuItem = ({
  href = "#",
  title = "",
  demoMark = false,
  icon,
  color,
  plainAnchor = false,
}: {
  href?: string;
  title?: string;
  demoMark?: boolean;
  icon?: ReactNode;
  color?: "primary" | "danger";
  /**
   * For an address that does something when requested (the logout): the
   * router would request the target of a next/link in advance.
   */
  plainAnchor?: boolean;
}) => {
  const Anchor = plainAnchor ? "a" : Link;

  return (
    <Anchor
      className={`relative inline-flex items-center gap-3 tap-highlight-transparent font-semibold
        outline-none data-[focus-visible=true]:z-10 data-[focus-visible=true]:outline-2 
        data-[focus-visible=true]:outline-focus data-[focus-visible=true]:outline-offset-2 
        no-underline hover:underline hover:opacity-80 active:opacity-disabled 
        transition-opacity data-[active=true]:text-primary data-[active=true]:font-semibold
        w-full h-12 text-lg ${
          color === "danger"
            ? "text-danger"
            : color === "primary"
              ? "text-primary"
              : ""
        }`}
      href={href}
    >
      {demoMark && (
        <div className="absolute -top-0 -left-4 text-xs rounded-xl px-1 bg-warning -rotate-12">
          Demo
        </div>
      )}
      {icon ? <span className="shrink-0">{icon}</span> : null}
      {title}
    </Anchor>
  );
};

const MenuLink = ({ href = "#", title = "" }) => {
  return (
    <Link
      className="relative inline-flex items-center tap-highlight-transparent font-bold
        outline-none data-[focus-visible=true]:z-10 data-[focus-visible=true]:outline-2 
        data-[focus-visible=true]:outline-focus data-[focus-visible=true]:outline-offset-2 
        text-medium no-underline hover:underline hover:opacity-80 active:opacity-disabled 
        transition-opacity data-[active=true]:text-primary data-[active=true]:font-semibold"
      href={href}
    >
      {title}
    </Link>
  );
};

export const UserDetailButton = (props: {
  user?: UserSession;
  toMenu?: boolean;
}) => (
  <div
    className={`flex flex-row items-center gap-4 no-wrap ${
      props.toMenu && "pt-6 pb-4"
    }`}
  >
    <Avatar
      isBordered={!props.toMenu}
      className="transition-transform"
      color="secondary"
      size={props.toMenu ? "md" : "sm"}
    />
    <div className="max-w-40 flex flex-col justify-center cursor-pointer">
      {props.user ? (
        <label
          className={`text-sm font-bold cursor-pointer whitespace-nowrap  ${
            props.toMenu && "text-black/70"
          }`}
        >
          {" "}
          {props.user?.name ??
            `${props.user?.first_name} ${props.user?.last_name}`}
        </label>
      ) : (
        <label className="text-sm font-bold cursor-pointer">Log in</label>
      )}
    </div>
  </div>
);

// const SearchIcon = ({
//   size = 24,
//   strokeWidth = 1.5,
//   width = null,
//   height = null,
//   ...props
// }) => {
//   return (
//     <svg
//       aria-hidden="true"
//       fill="none"
//       focusable="false"
//       height={height || size}
//       role="presentation"
//       viewBox="0 0 24 24"
//       width={width || size}
//       {...props}
//     >
//       <path
//         d="M11.5 21C16.7467 21 21 16.7467 21 11.5C21 6.25329 16.7467 2 11.5 2C6.25329 2 2 6.25329 2 11.5C2 16.7467 6.25329 21 11.5 21Z"
//         stroke="currentColor"
//         strokeLinecap="round"
//         strokeLinejoin="round"
//         strokeWidth={strokeWidth}
//       />
//       <path
//         d="M22 22L20 20"
//         stroke="currentColor"
//         strokeLinecap="round"
//         strokeLinejoin="round"
//         strokeWidth={strokeWidth}
//       />
//     </svg>
//   );
// };
