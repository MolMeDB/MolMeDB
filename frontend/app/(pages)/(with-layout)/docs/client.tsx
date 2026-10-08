"use client";

import { Spinner } from "@heroui/react";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
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
      <div className="flex min-h-[45vh] flex-col items-center justify-center rounded-xl border border-default-200 bg-white/60 text-default-600">
        <Spinner size="lg" color="primary" className="mb-4" />
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
          dangerouslySetInnerHTML={{ __html: props.parsedContent.html }}
        />
      </article>
    </>
  );
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
    html: DOMPurify.sanitize(parsedDocument.body.innerHTML),
    headings,
  };
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
    block.setAttribute("data-language", language);

    if (language === "text") {
      return;
    }

    const codeElement = block.querySelector("code");
    const source = codeElement?.textContent ?? block.textContent ?? "";

    if (!source.trim()) {
      return;
    }

    const highlighted = highlightSource(source, language);

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
  if (fromData === "sparql" || fromData === "sql" || fromData === "json") {
    return fromData;
  }

  if (PLAIN_CODE_LANGUAGES.has(fromData)) {
    return "text";
  }

  const className = block.getAttribute("class") ?? "";
  const classMatch = className.match(/\blanguage-(sparql|sql|json)\b/i);
  const fromClass = classMatch?.[1]?.toLowerCase() ?? "";

  if (fromClass === "sparql" || fromClass === "sql" || fromClass === "json") {
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
