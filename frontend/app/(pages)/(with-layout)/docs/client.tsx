"use client";

import { Spinner } from "@heroui/react";
import Link from "next/link";
import { type MouseEvent, useEffect, useMemo, useState } from "react";
import { FiMenu, FiX } from "react-icons/fi";
import DOMPurify from "dompurify";

type DocumentArticleTreeNode = {
  id: number;
  title: string;
  slug: string;
  path: string;
  children: DocumentArticleTreeNode[];
};

type DocumentArticleBreadcrumb = {
  title: string;
  path: string;
};

type DocumentArticle = {
  id: number;
  title: string;
  slug: string;
  path: string;
  content: string;
  breadcrumbs: DocumentArticleBreadcrumb[];
};

type Heading = {
  id: string;
  title: string;
  level: 1 | 2;
};

type ClientProps = {
  initialSlug: string[];
};

type ParsedArticleContent = {
  html: string;
  headings: Heading[];
};

let cachedTree: DocumentArticleTreeNode[] | null = null;

export default function Client(props: ClientProps) {
  const [tree, setTree] = useState<DocumentArticleTreeNode[]>(
    () => cachedTree ?? [],
  );
  const [article, setArticle] = useState<DocumentArticle | null>(null);
  const [isTreeLoading, setIsTreeLoading] = useState(cachedTree === null);
  const [isArticleLoading, setIsArticleLoading] = useState(true);
  const [treeError, setTreeError] = useState<string | null>(null);
  const [articleError, setArticleError] = useState<string | null>(null);
  const [activeHeadingId, setActiveHeadingId] = useState<string | null>(null);
  const [isMenuOpen, setIsMenuOpen] = useState(false);

  const slugPath = useMemo(
    () => props.initialSlug.join("/"),
    [props.initialSlug],
  );
  const activePath = article?.path ?? slugPath;
  const parsedContent = useMemo<ParsedArticleContent>(() => {
    return parseContent(article?.content ?? "");
  }, [article?.content]);

  useEffect(() => {
    if (isArticleLoading || parsedContent.headings.length === 0) {
      setActiveHeadingId(null);

      return;
    }

    setActiveHeadingId(parsedContent.headings[0]?.id ?? null);

    let animationFrame: number | null = null;

    function updateActiveHeading() {
      animationFrame = null;

      const scrollOffset = 130;
      const currentHeading = parsedContent.headings.findLast((heading) => {
        const element = document.getElementById(heading.id);

        if (!element) {
          return false;
        }

        return element.getBoundingClientRect().top <= scrollOffset;
      });

      setActiveHeadingId(
        currentHeading?.id ?? parsedContent.headings[0]?.id ?? null,
      );
    }

    function scheduleUpdate() {
      if (animationFrame !== null) {
        return;
      }

      animationFrame = window.requestAnimationFrame(updateActiveHeading);
    }

    updateActiveHeading();
    window.addEventListener("scroll", scheduleUpdate, { passive: true });
    window.addEventListener("resize", scheduleUpdate);

    return () => {
      if (animationFrame !== null) {
        window.cancelAnimationFrame(animationFrame);
      }

      window.removeEventListener("scroll", scheduleUpdate);
      window.removeEventListener("resize", scheduleUpdate);
    };
  }, [isArticleLoading, parsedContent.headings]);

  useEffect(() => {
    const abortController = new AbortController();

    async function loadTree() {
      if (cachedTree !== null) {
        setTree(cachedTree);
        setIsTreeLoading(false);

        return;
      }

      try {
        setIsTreeLoading(true);
        setTreeError(null);

        const treeResponse = await fetch("/api/docs/tree", {
          signal: abortController.signal,
        });

        if (!treeResponse.ok) {
          const treeError = (await treeResponse.json().catch(() => null)) as {
            message?: string;
          } | null;
          throw new Error(
            treeError?.message ?? "Failed to load documentation menu.",
          );
        }

        const treeJson = (await treeResponse.json()) as {
          data?: DocumentArticleTreeNode[];
        };

        cachedTree = treeJson.data ?? [];
        setTree(cachedTree);
      } catch (err) {
        if (abortController.signal.aborted) {
          return;
        }

        setTreeError(
          err instanceof Error
            ? err.message
            : "Documentation menu cannot be loaded right now.",
        );
      } finally {
        if (!abortController.signal.aborted) {
          setIsTreeLoading(false);
        }
      }
    }

    async function loadArticle() {
      try {
        setIsArticleLoading(true);
        setArticleError(null);
        setArticle(null);

        const articleResponse = await fetch(
          slugPath.trim().length > 0
            ? `/api/docs/article/${slugPath}`
            : "/api/docs/article",
          {
            signal: abortController.signal,
          },
        );

        if (!articleResponse.ok) {
          const articleError = (await articleResponse
            .json()
            .catch(() => null)) as {
            message?: string;
          } | null;
          throw new Error(
            articleError?.message ?? "Failed to load documentation article.",
          );
        }

        const articleJson = (await articleResponse.json()) as {
          data?: DocumentArticle;
        };

        setArticle(articleJson.data ?? null);
      } catch (err) {
        if (abortController.signal.aborted) {
          return;
        }

        setArticle(null);
        setArticleError(
          err instanceof Error
            ? err.message
            : "Documentation cannot be loaded right now.",
        );
      } finally {
        if (!abortController.signal.aborted) {
          setIsArticleLoading(false);
        }
      }
    }

    loadTree();
    loadArticle();

    return () => {
      abortController.abort();
    };
  }, [slugPath]);

  useEffect(() => {
    setIsMenuOpen(false);
  }, [slugPath]);

  return (
    <div className="grid min-h-screen w-full grid-cols-1 gap-6 py-6 xl:grid-cols-[18rem_minmax(0,1fr)_18rem] xl:gap-8">
      <div className="hidden xl:contents">
        <aside className="min-w-0 xl:sticky xl:top-20 xl:self-start">
          <SectionMenu
            tree={tree}
            activePath={activePath}
            error={treeError}
            isLoading={isTreeLoading}
          />
        </aside>

        {!isArticleLoading && parsedContent.headings.length > 0 && (
          <aside className="min-w-0 xl:sticky xl:top-20 xl:self-start">
            <SectionContents
              activeHeadingId={activeHeadingId}
              headings={parsedContent.headings}
            />
          </aside>
        )}
      </div>

      <main className="min-w-0 xl:col-start-2 xl:row-start-1">
        <ArticleContent
          article={article}
          error={articleError}
          isLoading={isArticleLoading}
          onOpenMenu={() => setIsMenuOpen(true)}
          parsedContent={parsedContent}
        />
      </main>

      <MobileMenuDrawer
        activePath={activePath}
        error={treeError}
        isLoading={isTreeLoading}
        isOpen={isMenuOpen}
        tree={tree}
        onClose={() => setIsMenuOpen(false)}
      />
    </div>
  );
}

function MobileMenuDrawer(props: {
  activePath: string;
  error: string | null;
  isLoading: boolean;
  isOpen: boolean;
  tree: DocumentArticleTreeNode[];
  onClose: () => void;
}) {
  if (!props.isOpen) {
    return null;
  }

  return (
    <div className="fixed inset-x-0 bottom-0 top-16 z-[999] xl:hidden">
      <button
        aria-label="Close documentation menu"
        className="absolute inset-0 bg-black/45"
        type="button"
        onClick={props.onClose}
      />
      <div className="relative flex h-full w-[min(22rem,86vw)] flex-col bg-white shadow-2xl dark:bg-zinc-950">
        <div className="flex items-center justify-between gap-3 border-b border-default-200 px-4 py-3 dark:border-default-100">
          <div className="text-sm font-semibold text-default-800">
            Documentation
          </div>
          <button
            aria-label="Close documentation menu"
            className="flex h-9 w-9 items-center justify-center rounded-full text-default-600 transition-colors hover:bg-default-100 hover:text-default-900"
            type="button"
            onClick={props.onClose}
          >
            <FiX className="h-5 w-5" />
          </button>
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          <SectionMenu
            activePath={props.activePath}
            error={props.error}
            isDrawer
            isLoading={props.isLoading}
            tree={props.tree}
            onNavigate={props.onClose}
          />
        </div>
      </div>
    </div>
  );
}

function SectionMenu(props: {
  tree: DocumentArticleTreeNode[];
  activePath: string;
  error: string | null;
  isDrawer?: boolean;
  isLoading: boolean;
  onNavigate?: () => void;
}) {
  return (
    <div
      className={[
        "overflow-y-auto rounded-xl border border-default-300 bg-default-50",
        props.isDrawer
          ? "max-h-none"
          : "max-h-[45vh] xl:max-h-[calc(100vh-7rem)]",
      ].join(" ")}
    >
      {props.error && (
        <div className="border-b border-danger-200 bg-danger-50 p-4 text-sm text-danger-700">
          {props.error}
        </div>
      )}
      {props.isLoading && props.tree.length === 0 && (
        <div className="flex items-center gap-3 p-4 text-sm text-default-600">
          <Spinner size="sm" color="primary" />
          Loading menu...
        </div>
      )}
      {props.tree.map((item) => (
        <div
          key={item.id}
          className="border-b border-default-200 p-4 last:border-b-0"
        >
          <MenuItem
            title={item.title}
            path={item.path}
            isActive={props.activePath === item.path}
            isParent
            onNavigate={props.onNavigate}
          />

          {item.children.length > 0 && (
            <div className="mt-3 flex flex-col gap-2 pl-4">
              {item.children.map((child) => (
                <MenuItem
                  key={child.id}
                  title={child.title}
                  path={child.path}
                  isActive={props.activePath === child.path}
                  onNavigate={props.onNavigate}
                />
              ))}
            </div>
          )}
        </div>
      ))}
    </div>
  );
}

function ArticleContent(props: {
  article: DocumentArticle | null;
  error: string | null;
  isLoading: boolean;
  onOpenMenu: () => void;
  parsedContent: ParsedArticleContent;
}) {
  if (props.isLoading) {
    return (
      <div className="flex min-h-[45vh] flex-col items-center justify-center rounded-xl border border-default-200 bg-white/60 text-default-600 dark:border-default-100 dark:bg-zinc-950 dark:text-default-400">
        <Spinner
          size="lg"
          color="primary"
          className="mb-4"
          classNames={{
            circle1: "dark:border-b-white",
            circle2: "dark:border-b-white",
          }}
        />
        Loading article...
      </div>
    );
  }

  if (props.error) {
    return (
      <div className="rounded-xl border border-danger-200 bg-danger-50 p-4 text-danger-700">
        {props.error}
      </div>
    );
  }

  if (!props.article) {
    return (
      <div className="rounded-xl border border-default-200 bg-default-50 p-4 text-default-700">
        No published documentation article found.
      </div>
    );
  }

  return (
    <>
      <MobileDocumentationBar
        breadcrumbs={props.article.breadcrumbs}
        onOpenMenu={props.onOpenMenu}
      />
      <div className="sticky top-16 z-40 -mx-4 mb-5 hidden min-h-14 items-center border-b border-default-200 bg-default-100/95 px-4 py-3 backdrop-blur xl:flex">
        <Breadcrumbs items={props.article.breadcrumbs} />
      </div>
      <article className="mt-5 xl:mt-6">
        <h1 className="pb-6 text-3xl font-bold text-default-900">
          {props.article.title}
        </h1>
        <div
          className="html-content-block max-w-none text-default-700"
          onClick={copyRequestCommand}
          dangerouslySetInnerHTML={{ __html: props.parsedContent.html }}
        />
      </article>
    </>
  );
}

// "Copy curl" buttons of the request cards (see renderRequestCards).
function copyRequestCommand(event: MouseEvent<HTMLDivElement>): void {
  const button = (event.target as HTMLElement).closest<HTMLButtonElement>(
    "button[data-copy]",
  );

  if (!button) {
    return;
  }

  const text = button.dataset.copy ?? "";
  const showResult = (isCopied: boolean) => {
    button.textContent = isCopied ? "Copied" : "Copy failed";
    window.setTimeout(() => (button.textContent = "Copy curl"), 1500);
  };

  (navigator.clipboard?.writeText(text) ?? Promise.reject()).then(
    () => showResult(true),
    () => showResult(copyWithSelection(text)),
  );
}

// Fallback where the Clipboard API is not allowed (e.g. embedded browsers).
function copyWithSelection(text: string): boolean {
  const textarea = document.createElement("textarea");
  textarea.value = text;
  textarea.setAttribute("readonly", "");
  textarea.style.position = "fixed";
  textarea.style.opacity = "0";
  document.body.appendChild(textarea);
  textarea.select();

  try {
    return document.execCommand("copy");
  } catch {
    return false;
  } finally {
    textarea.remove();
  }
}

function MobileDocumentationBar(props: {
  breadcrumbs: DocumentArticleBreadcrumb[];
  onOpenMenu: () => void;
}) {
  return (
    <div className="sticky top-16 z-40 -mx-4 mb-5 flex min-h-14 items-center gap-3 border-b border-default-200 bg-default-100/95 px-4 py-3 backdrop-blur xl:hidden">
      <button
        aria-label="Open documentation menu"
        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-default-700 transition-colors hover:bg-default-200 hover:text-default-950"
        type="button"
        onClick={props.onOpenMenu}
      >
        <FiMenu className="h-5 w-5" />
      </button>
      <div className="min-w-0 flex-1">
        <Breadcrumbs compact items={props.breadcrumbs} />
      </div>
    </div>
  );
}

function MenuItem(props: {
  title: string;
  path: string;
  isActive: boolean;
  isParent?: boolean;
  onNavigate?: () => void;
}) {
  return (
    <Link
      href={`/docs/${props.path}`}
      className={`block transition-colors ${
        props.isParent ? "text-sm font-semibold" : "text-sm"
      } ${
        props.isActive
          ? "text-primary-600"
          : "text-default-700 hover:text-primary-600"
      }`}
      onClick={props.onNavigate}
    >
      {props.title}
    </Link>
  );
}

function Breadcrumbs(props: {
  compact?: boolean;
  items: DocumentArticleBreadcrumb[];
}) {
  return (
    <div
      className={[
        "flex items-center gap-2",
        props.compact
          ? "min-w-0 overflow-hidden whitespace-nowrap text-sm"
          : "flex-wrap text-lg",
      ].join(" ")}
    >
      {props.items.map((item, index) => {
        const isLast = index === props.items.length - 1;

        return (
          <div
            key={item.path}
            className={[
              "flex min-w-0 items-center gap-2",
              props.compact && !isLast ? "shrink-0" : "",
            ].join(" ")}
          >
            {isLast ? (
              <span
                className={[
                  "text-default-500",
                  props.compact ? "truncate" : "",
                ].join(" ")}
              >
                {item.title}
              </span>
            ) : (
              <Link
                href={`/docs/${item.path}`}
                className="shrink-0 text-primary hover:underline"
              >
                {item.title}
              </Link>
            )}

            {!isLast && <span className="text-default-400">/</span>}
          </div>
        );
      })}
    </div>
  );
}

function SectionContents(props: {
  activeHeadingId: string | null;
  headings: Heading[];
}) {
  if (props.headings.length === 0) {
    return null;
  }

  return (
    <div className="max-h-[45vh] overflow-y-auto rounded-xl border border-default-300 bg-default-50 p-4 xl:max-h-[calc(100vh-7rem)]">
      <h2 className="text-lg font-bold text-default-800 xl:text-2xl">
        Contents
      </h2>
      <div className="mt-3 flex flex-col border-l-2 border-secondary/20 xl:mt-4">
        {props.headings.map((heading) => {
          const isActive = props.activeHeadingId === heading.id;

          return (
            <button
              key={heading.id}
              type="button"
              onClick={() => scrollToHeading(heading.id)}
              className={[
                "-ml-0.5 border-l-2 px-3 py-2 text-left text-sm transition-colors",
                heading.level === 2 ? "pl-7" : "",
                isActive
                  ? "border-primary bg-primary-50 font-semibold text-primary-700"
                  : "border-transparent text-default-700 hover:bg-default-100 hover:text-primary-600",
              ].join(" ")}
            >
              {heading.title}
            </button>
          );
        })}
      </div>
    </div>
  );
}

function parseContent(content: string): ParsedArticleContent {
  if (!content || typeof window === "undefined") {
    return {
      html: content,
      headings: [],
    };
  }

  const parser = new DOMParser();
  const parsedDocument = parser.parseFromString(content, "text/html");
  highlightCodeBlocks(parsedDocument);
  renderRequestCards(parsedDocument);
  decorateEndpointHeadings(parsedDocument);
  normalizeDocumentationTables(parsedDocument);
  const headingElements = Array.from(parsedDocument.querySelectorAll("h1, h2"));
  const usedIds = new Set<string>();
  const headings: Heading[] = [];

  headingElements.forEach((headingElement, index) => {
    const headingText = headingElement.textContent?.trim() ?? "";
    if (!headingText) {
      return;
    }

    const baseId = slugify(headingText) || `section-${index + 1}`;
    let nextId = baseId;
    let idSuffix = 2;

    while (usedIds.has(nextId)) {
      nextId = `${baseId}-${idSuffix}`;
      idSuffix += 1;
    }

    usedIds.add(nextId);
    headingElement.setAttribute("id", nextId);

    headings.push({
      id: nextId,
      title: headingText,
      level: headingElement.tagName === "H1" ? 1 : 2,
    });
  });

  return {
    // "target" opens the Try it! links in a new tab.
    html: DOMPurify.sanitize(parsedDocument.body.innerHTML, {
      ADD_ATTR: ["target"],
    }),
    headings,
  };
}

// Headings of endpoint references ("### `GET /api/v1/structures/{identifier}`")
// start a section of their own: a method badge and the path with its
// {parameters} marked.
function decorateEndpointHeadings(parsedDocument: Document): void {
  parsedDocument.querySelectorAll("h3").forEach((heading) => {
    const code = heading.querySelector("code");
    const match = code?.textContent
      ?.trim()
      .match(/^(GET|POST|PUT|PATCH|DELETE)\s+(\/\S*)$/);

    if (
      !code ||
      !match ||
      heading.textContent?.trim() !== code.textContent?.trim()
    ) {
      return;
    }

    const [, method, path] = match;
    const methodBadge = parsedDocument.createElement("span");
    methodBadge.className = "docs-endpoint-heading__method";
    methodBadge.textContent = method;

    const pathElement = parsedDocument.createElement("code");
    pathElement.className = "docs-endpoint-heading__path";
    path.split(/(\{[^}]+\})/).forEach((part) => {
      if (part === "") {
        return;
      }

      if (part.startsWith("{")) {
        const parameter = parsedDocument.createElement("span");
        parameter.className = "docs-endpoint-heading__parameter";
        parameter.textContent = part;
        pathElement.appendChild(parameter);
      } else {
        pathElement.appendChild(parsedDocument.createTextNode(part));
      }
    });

    heading.classList.add("docs-endpoint-heading");
    heading.replaceChildren(methodBadge, pathElement);
  });
}

type CurlRequest = {
  command: string;
  caption: string | null;
  endpoint: string;
  query: [string, string][];
  url: string;
  isDownload: boolean;
};

// Shell examples made only of GET requests (curl, with "# comments") are shown
// as request cards: the endpoint, its query parameters, a "Try it!" link
// opening the request in a new tab (the API answers a browser with its
// explorer page) and a button copying the curl command. Other shell examples,
// such as POST requests, stay as code.
function renderRequestCards(parsedDocument: Document): void {
  const shellBlocks = Array.from(
    parsedDocument.querySelectorAll("pre.docs-code-block"),
  ).filter((block) =>
    ["bash", "shell"].includes(block.getAttribute("data-language") ?? ""),
  );

  shellBlocks.forEach((block) => {
    const requests = parseCurlRequests(block.textContent ?? "");

    if (requests === null) {
      return;
    }

    const cards = requests.map((request) =>
      createRequestCard(parsedDocument, request),
    );
    block.replaceWith(...cards);
  });
}

function createRequestCard(
  parsedDocument: Document,
  request: CurlRequest,
): HTMLElement {
  const element = (tag: string, className: string, text?: string) => {
    const created = parsedDocument.createElement(tag);
    created.className = className;
    if (text !== undefined) {
      created.textContent = text;
    }

    return created;
  };

  const card = element("div", "docs-request");

  if (request.caption) {
    card.appendChild(element("p", "docs-request__caption", request.caption));
  }

  const line = element("div", "docs-request__line");
  line.appendChild(element("span", "docs-request__method", "GET"));
  line.appendChild(element("code", "docs-request__endpoint", request.endpoint));
  card.appendChild(line);

  if (request.query.length > 0) {
    const parameters = element("dl", "docs-request__query");
    request.query.forEach(([name, value]) => {
      parameters.appendChild(element("dt", "", name));
      parameters.appendChild(element("dd", "", value));
    });
    card.appendChild(parameters);
  }

  const actions = element("div", "docs-request__actions");
  const link = element(
    "a",
    "docs-request__try",
    request.isDownload ? "Download" : "Try it!",
  ) as HTMLAnchorElement;
  link.href = request.url;
  link.target = "_blank";
  link.rel = "noopener noreferrer";
  actions.appendChild(link);

  const copy = element("button", "docs-request__copy", "Copy curl");
  copy.setAttribute("type", "button");
  copy.setAttribute("data-copy", request.command);
  actions.appendChild(copy);
  card.appendChild(actions);

  return card;
}

// The requests of a shell example, or null when it contains anything else.
function parseCurlRequests(source: string): CurlRequest[] | null {
  const lines = source
    .replace(/\\\r?\n\s*/g, " ")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== "");
  const requests: CurlRequest[] = [];
  let caption: string | null = null;

  for (const line of lines) {
    if (line.startsWith("#")) {
      caption = line.replace(/^#+\s*/, "");
      continue;
    }

    const request = line.startsWith("curl ")
      ? parseCurlCommand(line, caption)
      : null;

    if (request === null) {
      return null;
    }

    requests.push(request);
    caption = null;
  }

  return requests.length > 0 ? requests : null;
}

function parseCurlCommand(
  command: string,
  caption: string | null,
): CurlRequest | null {
  const tokens = Array.from(
    command.matchAll(/'([^']*)'|"([^"]*)"|(\S+)/g),
    (match) => match[1] ?? match[2] ?? match[3],
  );
  let url: string | null = null;
  let isGetQuery = false;
  let isDownload = false;
  const data: string[] = [];

  for (let index = 1; index < tokens.length; index += 1) {
    const token = tokens[index];

    if (token === "-X" || token === "--request") {
      if (tokens[index + 1]?.toUpperCase() !== "GET") {
        return null;
      }
      index += 1;
    } else if (token === "-G" || token === "--get") {
      isGetQuery = true;
    } else if (token === "--data-urlencode") {
      const [name, ...value] = (tokens[index + 1] ?? "").split("=");
      data.push(`${name}=${encodeURIComponent(value.join("="))}`);
      index += 1;
    } else if (token === "-d" || token.startsWith("--data")) {
      data.push(tokens[index + 1] ?? "");
      index += 1;
    } else if (/^-[a-zA-Z]*O/.test(token) || token === "--remote-name") {
      isDownload = true;
    } else if (token === "-o" || token === "--output") {
      isDownload = true;
      index += 1;
    } else if (url === null && /^https?:\/\//.test(token)) {
      url = token;
    }
  }

  // A body without -G makes the request a POST.
  if (url === null || (data.length > 0 && !isGetQuery)) {
    return null;
  }

  if (data.length > 0) {
    url += (url.includes("?") ? "&" : "?") + data.join("&");
  }

  try {
    const parsedUrl = new URL(url);

    return {
      command,
      caption,
      endpoint: parsedUrl.origin + parsedUrl.pathname,
      query: Array.from(parsedUrl.searchParams.entries()),
      url: parsedUrl.href,
      isDownload,
    };
  } catch {
    return null;
  }
}

function normalizeDocumentationTables(parsedDocument: Document): void {
  const tables = Array.from(parsedDocument.querySelectorAll("table"));

  tables.forEach((table) => {
    table.classList.add("docs-table");
    addTableCellBreakOpportunities(parsedDocument, table);

    if (table.closest(".docs-table-scroll")) {
      return;
    }

    const wrapper = parsedDocument.createElement("div");
    wrapper.className = "docs-table-scroll";

    table.parentNode?.insertBefore(wrapper, table);
    wrapper.appendChild(table);
  });
}

function addTableCellBreakOpportunities(
  parsedDocument: Document,
  table: HTMLTableElement,
): void {
  const cells = Array.from(table.querySelectorAll("th, td"));

  cells.forEach((cell) => {
    const walker = parsedDocument.createTreeWalker(cell, NodeFilter.SHOW_TEXT);
    const textNodes: Text[] = [];

    while (walker.nextNode()) {
      textNodes.push(walker.currentNode as Text);
    }

    textNodes.forEach((textNode) => {
      const parts = getTableTextBreakParts(textNode.nodeValue ?? "");

      if (parts.length <= 1) {
        return;
      }

      const fragment = parsedDocument.createDocumentFragment();

      parts.forEach((part, index) => {
        fragment.appendChild(parsedDocument.createTextNode(part));

        if (index < parts.length - 1) {
          fragment.appendChild(parsedDocument.createElement("wbr"));
        }
      });

      textNode.replaceWith(fragment);
    });
  });
}

function getTableTextBreakParts(text: string): string[] {
  const breakableText = text
    .replace(/([:/._-])/g, "$1\u200B")
    .replace(/([a-z0-9])([A-Z])/g, "$1\u200B$2")
    .replace(/([A-Z]+)([A-Z][a-z])/g, "$1\u200B$2");

  return breakableText.split("\u200B").filter((part) => part !== "");
}

// Code blocks shown as they are, without highlighting (e.g. shell commands
// in the articles generated from resources/docs).
const PLAIN_CODE_LANGUAGES = new Set(["text", "bash", "shell", "http"]);

const HIGHLIGHTED_CODE_LANGUAGES = new Set(["sparql", "sql", "json", "python"]);

function highlightCodeBlocks(parsedDocument: Document): void {
  const explicitBlocks = Array.from(
    parsedDocument.querySelectorAll("pre.docs-code-block"),
  );
  const fallbackBlocks = Array.from(
    parsedDocument.querySelectorAll("pre"),
  ).filter((block) => !block.classList.contains("docs-code-block"));
  const codeBlocks = [...explicitBlocks, ...fallbackBlocks];

  codeBlocks.forEach((block) => {
    const language = detectCodeLanguage(block);

    // Plain blocks keep their own language as the label.
    if (language === "text") {
      return;
    }

    block.setAttribute("data-language", language);

    const codeElement = block.querySelector("code");
    const source = codeElement?.textContent ?? block.textContent ?? "";

    if (!source.trim()) {
      return;
    }

    const highlighted =
      language === "python"
        ? highlightPython(source)
        : highlightSource(source, language);

    if (codeElement) {
      codeElement.innerHTML = highlighted;
    } else {
      block.innerHTML = `<code>${highlighted}</code>`;
    }
  });
}

function detectCodeLanguage(block: Element): string {
  const fromData = (block.getAttribute("data-language") ?? "")
    .trim()
    .toLowerCase();
  if (HIGHLIGHTED_CODE_LANGUAGES.has(fromData)) {
    return fromData;
  }

  if (PLAIN_CODE_LANGUAGES.has(fromData)) {
    return "text";
  }

  const className = block.getAttribute("class") ?? "";
  const classMatch = className.match(/\blanguage-([\w+-]+)\b/i);
  const fromClass = classMatch?.[1]?.toLowerCase() ?? "";

  if (HIGHLIGHTED_CODE_LANGUAGES.has(fromClass)) {
    return fromClass;
  }

  return "sparql";
}

function highlightSource(source: string, language: string): string {
  const keywordSets: Record<string, Set<string>> = {
    sparql: new Set([
      "PREFIX",
      "BASE",
      "SELECT",
      "WHERE",
      "ASK",
      "CONSTRUCT",
      "DESCRIBE",
      "FILTER",
      "OPTIONAL",
      "UNION",
      "GRAPH",
      "BIND",
      "VALUES",
      "ORDER",
      "BY",
      "LIMIT",
      "OFFSET",
      "DISTINCT",
      "REDUCED",
      "FROM",
      "NAMED",
      "SERVICE",
      "MINUS",
      "EXISTS",
      "NOT",
      "IN",
      "AS",
      "A",
    ]),
    sql: new Set([
      "SELECT",
      "FROM",
      "WHERE",
      "JOIN",
      "LEFT",
      "RIGHT",
      "INNER",
      "OUTER",
      "ON",
      "GROUP",
      "BY",
      "ORDER",
      "LIMIT",
      "OFFSET",
      "INSERT",
      "INTO",
      "VALUES",
      "UPDATE",
      "SET",
      "DELETE",
      "CREATE",
      "TABLE",
      "ALTER",
      "DROP",
      "INDEX",
      "AND",
      "OR",
      "NOT",
      "NULL",
      "AS",
      "DISTINCT",
      "UNION",
      "ALL",
      "HAVING",
      "CASE",
      "WHEN",
      "THEN",
      "ELSE",
      "END",
    ]),
    json: new Set(["true", "false", "null"]),
  };

  const tokenRegex =
    /(\/\*[\s\S]*?\*\/|--.*$|#.*$|<[^>\s]+>|\?[A-Za-z_][A-Za-z0-9_-]*|[A-Za-z_][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_-]*|[A-Za-z_][A-Za-z0-9_-]*:|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\b\d+(?:\.\d+)?\b|[A-Za-z_][A-Za-z0-9_]*|[^\s])/gm;
  const keywordSet = keywordSets[language] ?? keywordSets.sparql;

  let html = "";
  let cursor = 0;
  let match = tokenRegex.exec(source);

  while (match) {
    const token = match[0];
    const start = match.index;

    if (start > cursor) {
      html += escapeHtml(source.slice(cursor, start));
    }

    const end = start + token.length;
    const isJsonKey =
      language === "json" &&
      token.startsWith('"') &&
      /^\s*:/.test(source.slice(end));

    html += wrapToken(token, language, keywordSet, isJsonKey);
    cursor = end;
    match = tokenRegex.exec(source);
  }

  if (cursor < source.length) {
    html += escapeHtml(source.slice(cursor));
  }

  return html;
}

function wrapToken(
  token: string,
  language: string,
  keywordSet: Set<string>,
  isJsonKey = false,
): string {
  const escaped = escapeHtml(token);

  if (
    token.startsWith("/*") ||
    token.startsWith("--") ||
    token.startsWith("#")
  ) {
    return `<span class="docs-code__comment">${escaped}</span>`;
  }

  if (token.startsWith("?")) {
    return `<span class="docs-code__variable">${escaped}</span>`;
  }

  if (token.startsWith("<") && token.endsWith(">")) {
    return `<span class="docs-code__iri">${escaped}</span>`;
  }

  if (/^[A-Za-z_][A-Za-z0-9_-]*:$/.test(token)) {
    return `<span class="docs-code__prefix">${escaped}</span>`;
  }

  if (/^[A-Za-z_][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_-]*$/.test(token)) {
    return `<span class="docs-code__prefixed-name">${escaped}</span>`;
  }

  if (
    (token.startsWith('"') && token.endsWith('"')) ||
    (token.startsWith("'") && token.endsWith("'"))
  ) {
    return `<span class="${isJsonKey ? "docs-code__key" : "docs-code__string"}">${escaped}</span>`;
  }

  if (/^\d+(\.\d+)?$/.test(token)) {
    return `<span class="docs-code__number">${escaped}</span>`;
  }

  if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(token)) {
    if (language === "json" && keywordSet.has(token)) {
      return `<span class="docs-code__keyword">${escaped}</span>`;
    }

    if (language !== "json" && keywordSet.has(token.toUpperCase())) {
      return `<span class="docs-code__keyword">${escaped}</span>`;
    }
  }

  if (/^[()[\]{}.,;:=<>+\-*/%!?|&^~]$/.test(token)) {
    return `<span class="docs-code__operator">${escaped}</span>`;
  }

  return escaped;
}

const PYTHON_KEYWORDS = new Set([
  "and",
  "as",
  "assert",
  "async",
  "await",
  "break",
  "class",
  "continue",
  "def",
  "del",
  "elif",
  "else",
  "except",
  "finally",
  "for",
  "from",
  "global",
  "if",
  "import",
  "in",
  "is",
  "lambda",
  "nonlocal",
  "not",
  "or",
  "pass",
  "raise",
  "return",
  "try",
  "while",
  "with",
  "yield",
]);

const PYTHON_CONSTANTS = new Set(["None", "True", "False"]);

const PYTHON_BUILTINS = new Set([
  "dict",
  "enumerate",
  "float",
  "int",
  "isinstance",
  "len",
  "list",
  "max",
  "min",
  "open",
  "print",
  "range",
  "round",
  "set",
  "sorted",
  "str",
  "sum",
  "tuple",
  "zip",
]);

// Highlighting of Python examples in the way of an IDE: keywords, strings
// (with the {expressions} of f-strings), numbers, comments, called functions
// and methods, builtins, keyword arguments, assigned names and constants.
function highlightPython(source: string): string {
  const tokenRegex =
    /(#.*$)|((?:[rRbBuU]?[fF]|[fF][rR]|[rRbBuU]{1,2})?(?:"""[\s\S]*?"""|'''[\s\S]*?'''|"(?:\\.|[^"\\\n])*"|'(?:\\.|[^'\\\n])*'))|(\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b)|([A-Za-z_][A-Za-z0-9_]*)|(\S)/gm;
  const span = (className: string, text: string) =>
    `<span class="docs-code__${className}">${escapeHtml(text)}</span>`;

  let html = "";
  let cursor = 0;
  let bracketDepth = 0;
  let previousToken = "";
  let match = tokenRegex.exec(source);

  while (match) {
    const [token, comment, string, number, name] = match;
    const start = match.index;
    const end = start + token.length;
    const following = source.slice(end);

    html += escapeHtml(source.slice(cursor, start));

    if (comment) {
      html += span("comment", token);
    } else if (string) {
      html += highlightPythonString(string);
    } else if (number) {
      html += span("number", token);
    } else if (name) {
      const isCalled = /^\s*\(/.test(following);
      const isAssigned = /^\s*=(?!=)/.test(following);

      if (PYTHON_KEYWORDS.has(name)) {
        html += span("keyword", token);
      } else if (PYTHON_CONSTANTS.has(name)) {
        html += span("number", token);
      } else if (previousToken === "def" || previousToken === "class") {
        html += span("function", token);
      } else if (
        isCalled &&
        previousToken !== "." &&
        PYTHON_BUILTINS.has(name)
      ) {
        html += span("builtin", token);
      } else if (isCalled) {
        html += span("function", token);
      } else if (isAssigned && bracketDepth > 0) {
        html += span("parameter", token);
      } else if (/^[A-Z][A-Z0-9_]*$/.test(name) || isAssigned) {
        html += span("variable", token);
      } else {
        html += escapeHtml(token);
      }
    } else {
      if ("([{".includes(token)) {
        bracketDepth += 1;
      } else if (")]}".includes(token)) {
        bracketDepth = Math.max(0, bracketDepth - 1);
      }

      html += span("operator", token);
    }

    previousToken = token;
    cursor = end;
    match = tokenRegex.exec(source);
  }

  return html + escapeHtml(source.slice(cursor));
}

function highlightPythonString(token: string): string {
  const prefix = token.match(/^[A-Za-z]*/)?.[0] ?? "";

  if (!/f/i.test(prefix)) {
    return `<span class="docs-code__string">${escapeHtml(token)}</span>`;
  }

  // {expressions} of an f-string ("{{" and "}}" are literal braces).
  const parts = token.split(/(\{\{|\}\}|\{[^{}]*\})/);
  const inner = parts
    .map((part) =>
      /^\{[^{]/.test(part) && part.endsWith("}") && part !== "}}"
        ? `<span class="docs-code__variable">${escapeHtml(part)}</span>`
        : escapeHtml(part),
    )
    .join("");

  return `<span class="docs-code__string">${inner}</span>`;
}

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, "")
    .replace(/\s+/g, "-");
}

function scrollToHeading(id: string) {
  const target = document.getElementById(id);
  if (!target) {
    return;
  }

  const top = target.getBoundingClientRect().top + window.scrollY - 110;
  window.scrollTo({ top, behavior: "smooth" });
}
