<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $uriTemplate }} &middot; MolMeDB API explorer</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #ffffff;
            --fg: #1a1a1a;
            --muted: #6b7280;
            --border: #e5e7eb;
            --panel: #f9fafb;
            --accent: #2563eb;
            --code-bg: #0f172a;
            --code-fg: #e2e8f0;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b0f19;
                --fg: #e5e7eb;
                --muted: #9ca3af;
                --border: #26314a;
                --panel: #111827;
                --accent: #60a5fa;
                --code-bg: #05070d;
                --code-fg: #dbeafe;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 2rem 1.25rem 4rem;
            background: var(--bg);
            color: var(--fg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.5;
        }
        .wrap { max-width: 760px; margin: 0 auto; }
        .method {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            font-size: 0.75rem;
            letter-spacing: 0.04em;
            padding: 0.2rem 0.5rem;
            border-radius: 0.3rem;
            vertical-align: middle;
        }
        h1 {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 1.25rem;
            word-break: break-all;
            margin: 0.6rem 0 0.2rem;
        }
        .description { color: var(--muted); margin: 0 0 1.5rem; }
        section {
            border: 1px solid var(--border);
            border-radius: 0.6rem;
            padding: 1.1rem 1.25rem;
            margin-bottom: 1.25rem;
            background: var(--panel);
        }
        section h2 {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin: 0 0 0.9rem;
        }
        .field { margin-bottom: 0.8rem; }
        .field:last-child { margin-bottom: 0; }
        .field label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        .field .tag {
            font-weight: 500;
            font-size: 0.7rem;
            padding: 0.05rem 0.4rem;
            border-radius: 0.75rem;
            margin-left: 0.4rem;
            vertical-align: middle;
        }
        .tag.required { background: #fee2e2; color: #991b1b; }
        .tag.optional { background: #e0e7ff; color: #3730a3; }
        @media (prefers-color-scheme: dark) {
            .tag.required { background: #4c1d1d; color: #fca5a5; }
            .tag.optional { background: #312e81; color: #c7d2fe; }
        }
        .field p { margin: 0 0 0.35rem; color: var(--muted); font-size: 0.82rem; }
        .field input {
            width: 100%;
            padding: 0.5rem 0.6rem;
            border: 1px solid var(--border);
            border-radius: 0.4rem;
            background: var(--bg);
            color: var(--fg);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.9rem;
        }
        button {
            appearance: none;
            border: none;
            background: var(--accent);
            color: #fff;
            font-weight: 600;
            font-size: 0.9rem;
            padding: 0.6rem 1.1rem;
            border-radius: 0.4rem;
            cursor: pointer;
        }
        button:disabled { opacity: 0.6; cursor: default; }
        .url-preview {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.82rem;
            color: var(--muted);
            margin: 0.9rem 0 0;
            word-break: break-all;
        }
        pre {
            background: var(--code-bg);
            color: var(--code-fg);
            padding: 1rem;
            border-radius: 0.5rem;
            overflow-x: auto;
            font-size: 0.82rem;
            margin: 0;
        }
        .status-line { font-size: 0.85rem; margin-bottom: 0.6rem; }
        .status-ok { color: #16a34a; }
        .status-err { color: #dc2626; }
        .truncated-note {
            font-size: 0.8rem;
            color: var(--muted);
            margin-top: 0.6rem;
        }
        .hidden { display: none; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<div class="wrap">
    <span class="method">GET</span>
    <h1>{{ $uriTemplate }}</h1>
    <p class="description">{{ $description }}</p>

    <section>
        <h2>Parameters</h2>

        @foreach ($pathParams as $param)
            <div class="field">
                <label for="path-{{ $param['name'] }}">
                    {{ $param['label'] }} <span class="tag required">required</span>
                </label>
                <input type="text" id="path-{{ $param['name'] }}" data-path-param="{{ $param['name'] }}" value="{{ $param['value'] }}">
            </div>
        @endforeach

        @foreach ($queryParams as $param)
            <div class="field">
                <label for="query-{{ $param['name'] }}">
                    {{ $param['name'] }}
                    <span class="tag {{ $param['required'] ? 'required' : 'optional' }}">{{ $param['required'] ? 'required' : 'optional' }}</span>
                </label>
                @if ($param['description'])
                    <p>{{ $param['description'] }}</p>
                @endif
                <input type="text" id="query-{{ $param['name'] }}" data-query-param="{{ $param['name'] }}" value="{{ $param['value'] }}" placeholder="{{ $param['placeholder'] }}">
            </div>
        @endforeach

        @if (empty($pathParams) && empty($queryParams))
            <p class="description" style="margin:0;">This endpoint takes no parameters.</p>
        @endif

        <div style="margin-top: 1.1rem;">
            <button id="try-it-btn" type="button">{{ $isDownload ? 'Download' : 'Try it' }}</button>
        </div>
        <div class="url-preview" id="url-preview"></div>
    </section>

    @unless ($isDownload)
        <section id="result-section" class="hidden">
            <h2>Response</h2>
            <div class="status-line" id="status-line"></div>
            <pre id="result-body"></pre>
            <div class="truncated-note hidden" id="truncated-note"></div>
        </section>
    @endunless

    <section>
        <h2>Example request</h2>
        <pre>GET {{ $baseUrl }}{{ $exampleRequest }}</pre>
    </section>

    <p class="description">
        Full API reference: <a href="{{ $baseUrl }}/docs">OpenAPI docs</a>
        &middot; <a href="{{ $baseUrl }}/openapi.json">openapi.json</a>
        &middot; <a href="{{ $baseUrl }}/about">/about</a> (license &amp; citation)
    </p>

</div>

<script>
(function () {
    const baseUrl = @json($baseUrl);
    const uriTemplate = @json($uriTemplate);
    const isDownload = @json($isDownload);
    const maxLines = @json($maxResponseLines);

    function buildUrl() {
        let path = uriTemplate;
        document.querySelectorAll('[data-path-param]').forEach((input) => {
            path = path.replace('{' + input.dataset.pathParam + '}', encodeURIComponent(input.value || ''));
        });

        const params = new URLSearchParams();
        document.querySelectorAll('[data-query-param]').forEach((input) => {
            if (input.value !== '') {
                params.set(input.dataset.queryParam, input.value);
            }
        });

        const query = params.toString();
        return baseUrl + path + (query ? '?' + query : '');
    }

    function updatePreview() {
        document.getElementById('url-preview').textContent = buildUrl();
    }

    document.querySelectorAll('[data-path-param], [data-query-param]').forEach((input) => {
        input.addEventListener('input', updatePreview);
    });
    updatePreview();

    document.getElementById('try-it-btn').addEventListener('click', function () {
        const url = buildUrl();

        if (isDownload) {
            window.location.href = url;
            return;
        }

        const btn = this;
        const section = document.getElementById('result-section');
        const statusLine = document.getElementById('status-line');
        const body = document.getElementById('result-body');
        const note = document.getElementById('truncated-note');

        btn.disabled = true;
        section.classList.remove('hidden');
        statusLine.textContent = 'Loading...';
        statusLine.className = 'status-line';
        body.textContent = '';
        note.classList.add('hidden');

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'omit' })
            .then((response) => {
                const remaining = response.headers.get('X-RateLimit-Remaining');
                const limit = response.headers.get('X-RateLimit-Limit');
                const rateInfo = (limit !== null) ? ` · rate limit: ${remaining}/${limit} remaining` : '';

                statusLine.textContent = response.status + ' ' + response.statusText + rateInfo;
                statusLine.classList.add(response.ok ? 'status-ok' : 'status-err');

                return response.text();
            })
            .then((text) => {
                let formatted = text;

                try {
                    formatted = JSON.stringify(JSON.parse(text), null, 2);
                } catch (e) {
                    // Not JSON — show raw text as-is.
                }

                const lines = formatted.split('\n');

                if (lines.length > maxLines) {
                    body.textContent = lines.slice(0, maxLines).join('\n');
                    note.textContent = `Response truncated to the first ${maxLines} of ${lines.length} lines. Narrow your request (e.g. per_page) to see the rest.`;
                    note.classList.remove('hidden');
                } else {
                    body.textContent = formatted;
                }
            })
            .catch((error) => {
                statusLine.textContent = 'Request failed';
                statusLine.classList.add('status-err');
                body.textContent = String(error);
            })
            .finally(() => {
                btn.disabled = false;
            });
    });
})();
</script>
</body>
</html>
