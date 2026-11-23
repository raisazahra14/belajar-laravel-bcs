<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumentasi API LogistikKu ({{ $apiVersion }}) — Developer Portal</title>
    <link rel="icon" type="image/png" href="{{ asset('vendor/swagger-ui/favicon-32x32.png') }}">
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
    <style>
        /* Design Tokens — Selaras dengan LogistikKu Skydash (Zero Gradients, Solid Indigo, Slate Neutrals) */
        :root {
            --brand-50: #eef2ff;
            --brand-100: #e0e7ff;
            --brand-200: #c7d2fe;
            --brand-500: #6366f1;
            --brand-600: #4f46e5;
            --brand-700: #4338ca;

            --success-50: #ecfdf5;
            --success-200: #a7f3d0;
            --success-600: #059669;

            --info-50: #f0f9ff;
            --info-200: #bae6fd;
            --info-600: #0284c7;

            --warning-50: #fffbeb;
            --warning-200: #fde68a;
            --warning-600: #d97706;

            --danger-50: #fef2f2;
            --danger-200: #fecaca;
            --danger-600: #dc2626;

            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --surface-muted: #f1f5f9;
            --surface-dark: #0f172a;
            --surface-dark-border: #1e293b;

            --border: #e2e8f0;
            --border-hover: #cbd5e1;

            --text: #0f172a;
            --text-secondary: #334155;
            --text-muted: #64748b;

            --radius-sm: 6px;
            --radius-md: 8px;
            --radius: 12px;
            --radius-lg: 14px;

            --shadow-xs: 0 1px 2px 0 rgba(15, 23, 42, 0.05);
            --shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.06), 0 1px 2px -1px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.07), 0 2px 4px -2px rgba(15, 23, 42, 0.05);

            --focus: 0 0 0 3px rgba(79, 70, 229, 0.18);
            --font-sans: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background-color: var(--surface-soft);
            color: var(--text);
            font-family: var(--font-sans);
            font-size: 0.92rem;
            line-height: 1.55;
            letter-spacing: -0.01em;
            -webkit-font-smoothing: antialiased;
        }

        a, button, input, select, textarea {
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease, color 0.15s ease;
        }

        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible {
            outline: 0;
            box-shadow: var(--focus) !important;
        }

        /* Sticky Flat White Navbar — Identik dengan Skydash */
        .portal-navbar {
            position: sticky;
            top: 0;
            z-index: 50;
            height: 64px;
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            box-shadow: var(--shadow-xs);
        }

        .portal-navbar-inner {
            max-width: 1180px;
            height: 100%;
            margin: 0 auto;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .portal-brand-group {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand-logo {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--brand-700);
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            text-decoration: none;
        }

        .brand-logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: var(--radius-md);
            background-color: var(--brand-600);
            color: #ffffff;
            flex-shrink: 0;
        }

        .brand-divider {
            width: 1px;
            height: 20px;
            background-color: var(--border);
        }

        .portal-context-label {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .version-pill {
            display: inline-flex;
            align-items: center;
            padding: 2px 9px;
            border-radius: 999px;
            background-color: var(--brand-50);
            border: 1px solid var(--brand-200);
            color: var(--brand-700);
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .portal-nav-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .portal-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 13px;
            border-radius: var(--radius-md);
            font-size: 0.83rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid var(--border);
            background-color: var(--surface);
            color: var(--text-secondary);
            white-space: nowrap;
        }

        .portal-btn:hover {
            background-color: var(--surface-soft);
            border-color: var(--border-hover);
            color: var(--text);
        }

        .portal-btn-primary {
            background-color: var(--brand-600);
            border-color: var(--brand-600);
            color: #ffffff;
        }

        .portal-btn-primary:hover {
            background-color: var(--brand-700);
            border-color: var(--brand-700);
            color: #ffffff;
        }

        /* Live API Health Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 13px;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.82rem;
            font-weight: 600;
            border: 1px solid var(--border);
            background-color: var(--surface);
            color: var(--text-secondary);
            cursor: pointer;
            white-space: nowrap;
        }

        .status-badge:hover {
            filter: brightness(0.98);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background-color: var(--text-muted);
            flex-shrink: 0;
        }

        .status-badge.is-loading {
            background-color: var(--surface-soft);
            border-color: var(--border);
            color: var(--text-muted);
        }

        .status-badge.is-online {
            background-color: var(--success-50);
            border-color: var(--success-200);
            color: var(--success-600);
        }

        .status-badge.is-online .status-dot {
            background-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        .status-badge.is-offline {
            background-color: var(--danger-50);
            border-color: var(--danger-200);
            color: var(--danger-600);
        }

        .status-badge.is-offline .status-dot {
            background-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.18);
        }

        /* Main Container & Single Unified Intro */
        .portal-container {
            max-width: 1180px;
            margin: 32px auto 64px;
            padding: 0 24px;
        }

        .portal-intro {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px 28px;
            margin-bottom: 24px;
        }

        .portal-intro-top {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .portal-intro h1 {
            margin: 0 0 6px;
            font-size: 1.55rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            color: var(--text);
        }

        .portal-intro-desc {
            margin: 0;
            font-size: 0.93rem;
            color: var(--text-muted);
        }

        .portal-meta-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            background-color: var(--surface-soft);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 0.78rem;
            font-weight: 600;
        }

        .meta-pill code {
            font-family: var(--font-mono);
            font-size: 0.76rem;
            color: var(--brand-700);
        }

        /* Collapsible Quickstart Drawer (Closed by default for clean initial viewport) */
        .quickstart-drawer {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .quickstart-drawer summary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            list-style: none;
            font-size: 0.86rem;
            font-weight: 600;
            color: var(--brand-600);
            border-radius: var(--radius-sm);
            padding: 4px 2px;
        }

        .quickstart-drawer summary::-webkit-details-marker {
            display: none;
        }

        .quickstart-drawer summary:hover {
            color: var(--brand-700);
        }

        .quickstart-chevron {
            transition: transform 0.18s ease;
        }

        .quickstart-drawer[open] .quickstart-chevron {
            transform: rotate(90deg);
        }

        .quickstart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .quickstart-step {
            background-color: var(--surface-soft);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            font-size: 0.83rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .quickstart-step strong {
            display: block;
            margin-bottom: 4px;
            font-size: 0.84rem;
            color: var(--text);
        }

        .quickstart-step code,
        .quickstart-note code {
            font-family: var(--font-mono);
            font-size: 0.78rem;
            background-color: var(--brand-50);
            color: var(--brand-700);
            padding: 1px 5px;
            border-radius: 4px;
        }

        .quickstart-note {
            margin: 12px 0 0;
            font-size: 0.81rem;
            color: var(--text-muted);
        }

        /* Swagger UI Minimalist Overrides */
        .swagger-shell {
            background: transparent;
        }

        .swagger-ui {
            font-family: var(--font-sans);
            color: var(--text);
        }

        .swagger-ui .topbar,
        .swagger-ui .information-container {
            display: none !important;
        }

        .swagger-ui .wrapper {
            max-width: none;
            padding: 0;
        }

        /* Server & Authorize Toolbar Card */
        .swagger-ui .scheme-container {
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 16px 20px;
            margin: 0 0 20px;
        }

        .swagger-ui .scheme-container .schemes {
            align-items: center;
            gap: 12px;
        }

        .swagger-ui .servers-title {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .swagger-ui .servers > label select {
            font-family: var(--font-sans);
            font-size: 0.86rem;
            font-weight: 500;
            color: var(--text);
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 8px 32px 8px 12px;
            min-width: 280px;
            box-shadow: var(--shadow-xs);
        }

        .swagger-ui .btn.authorize {
            font-family: var(--font-sans);
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--success-600);
            border-color: var(--success-600);
            background-color: var(--surface);
            border-radius: var(--radius-md);
            padding: 8px 16px;
            box-shadow: var(--shadow-xs);
        }

        .swagger-ui .btn.authorize svg {
            fill: var(--success-600);
        }

        .swagger-ui .btn.authorize:hover {
            background-color: var(--success-50);
        }

        .swagger-ui .btn.authorize.locked {
            background-color: var(--success-600);
            color: #ffffff;
        }

        .swagger-ui .btn.authorize.locked svg {
            fill: #ffffff;
        }

        /* Filter Input */
        .swagger-ui .filter-container {
            margin: 0 0 24px;
            padding: 0;
            background: transparent;
        }

        .swagger-ui .filter-container .filter.wrapper {
            padding: 0;
        }

        .swagger-ui .filter-container .operation-filter-input {
            width: 100%;
            margin: 0;
            padding: 11px 16px;
            font-family: var(--font-sans);
            font-size: 0.9rem;
            color: var(--text);
            background-color: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow-xs);
        }

        .swagger-ui .filter-container .operation-filter-input:focus {
            border-color: var(--brand-600);
            box-shadow: var(--focus);
            outline: 0;
        }

        /* Tag Group Headings */
        .swagger-ui .opblock-tag-section {
            margin-bottom: 28px;
        }

        .swagger-ui .opblock-tag {
            padding: 12px 4px 12px;
            margin: 0 0 14px;
            border-bottom: 1px solid var(--border);
            font-family: var(--font-sans);
            font-size: 1.08rem;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.015em;
        }

        .swagger-ui .opblock-tag:hover {
            background: transparent;
        }

        .swagger-ui .opblock-tag small {
            font-size: 0.85rem;
            font-weight: 400;
            color: var(--text-muted);
            padding-left: 12px;
        }

        /* Endpoint Operation Cards */
        .swagger-ui .opblock {
            background-color: var(--surface);
            border: 1px solid var(--border) !important;
            border-radius: 10px;
            box-shadow: var(--shadow-xs);
            margin: 0 0 12px;
            overflow: hidden;
        }

        .swagger-ui .opblock:hover {
            border-color: var(--border-hover) !important;
        }

        .swagger-ui .opblock .opblock-summary {
            padding: 10px 16px;
            background-color: var(--surface);
            border-bottom: 0;
        }

        .swagger-ui .opblock.is-open .opblock-summary {
            background-color: var(--surface-soft);
            border-bottom: 1px solid var(--border) !important;
        }

        .swagger-ui .opblock .opblock-summary-method {
            min-width: 72px;
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            font-family: var(--font-mono);
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-align: center;
            box-shadow: none;
        }

        .swagger-ui .opblock.opblock-get .opblock-summary-method {
            background-color: var(--info-600);
        }

        .swagger-ui .opblock.opblock-post .opblock-summary-method {
            background-color: var(--success-600);
        }

        .swagger-ui .opblock.opblock-put .opblock-summary-method,
        .swagger-ui .opblock.opblock-patch .opblock-summary-method {
            background-color: var(--warning-600);
        }

        .swagger-ui .opblock.opblock-delete .opblock-summary-method {
            background-color: var(--danger-600);
        }

        .swagger-ui .opblock .opblock-summary-path {
            font-family: var(--font-mono);
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--text);
            padding-left: 12px;
        }

        .swagger-ui .opblock .opblock-summary-description {
            font-family: var(--font-sans);
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .swagger-ui .opblock .opblock-section-header {
            background-color: var(--surface-soft);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            box-shadow: none;
            padding: 10px 20px;
        }

        .swagger-ui .opblock .opblock-section-header h4 {
            font-family: var(--font-sans);
            font-size: 0.86rem;
            font-weight: 700;
            color: var(--text);
        }

        .swagger-ui .opblock-description-wrapper,
        .swagger-ui .opblock-external-docs-wrapper,
        .swagger-ui .opblock-title_normal {
            padding: 16px 20px;
            font-size: 0.88rem;
            color: var(--text-secondary);
        }

        .swagger-ui .parameters-container,
        .swagger-ui .responses-wrapper {
            padding: 12px 20px 20px;
        }

        /* Tables & Forms */
        .swagger-ui table thead tr td,
        .swagger-ui table thead tr th {
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border);
            padding: 10px 8px;
        }

        .swagger-ui table tbody tr td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--surface-muted);
            vertical-align: top;
        }

        .swagger-ui .parameter__name {
            font-family: var(--font-mono);
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--text);
        }

        .swagger-ui .parameter__type {
            font-family: var(--font-mono);
            font-size: 0.76rem;
            color: var(--brand-700);
        }

        .swagger-ui input[type=text],
        .swagger-ui input[type=password],
        .swagger-ui input[type=email],
        .swagger-ui input[type=number],
        .swagger-ui select {
            font-family: var(--font-sans);
            font-size: 0.85rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 7px 10px;
            color: var(--text);
            background-color: var(--surface);
        }

        .swagger-ui input:focus,
        .swagger-ui select:focus,
        .swagger-ui textarea:focus {
            border-color: var(--brand-600);
            box-shadow: var(--focus);
            outline: 0;
        }

        /* Buttons inside Operations */
        .swagger-ui .btn {
            font-family: var(--font-sans);
            border-radius: var(--radius-md);
            font-weight: 600;
            box-shadow: none;
        }

        .swagger-ui .btn.try-out__btn {
            color: var(--brand-600);
            border-color: var(--brand-600);
            background-color: var(--surface);
        }

        .swagger-ui .btn.try-out__btn:hover {
            background-color: var(--brand-50);
        }

        .swagger-ui .btn.execute {
            background-color: var(--brand-600);
            border-color: var(--brand-600);
            color: #ffffff;
            padding: 9px 20px;
        }

        .swagger-ui .btn.execute:hover {
            background-color: var(--brand-700);
            border-color: var(--brand-700);
        }

        .swagger-ui .btn.btn-clear {
            border-color: var(--border);
            color: var(--text-secondary);
        }

        /* Elegant Dark Code Blocks (Request Body Editor, Responses, Examples, cURL) */
        .swagger-ui pre.microlight,
        .swagger-ui .highlight-code > .microlight,
        .swagger-ui pre.example,
        .swagger-ui .curl-command .microlight,
        .swagger-ui .responses-inner pre,
        .swagger-ui textarea.body-param__text {
            background-color: var(--surface-dark) !important;
            color: #e2e8f0 !important;
            border: 1px solid var(--surface-dark-border) !important;
            border-radius: 10px !important;
            padding: 16px 18px !important;
            font-family: var(--font-mono) !important;
            font-size: 0.82rem !important;
            line-height: 1.6 !important;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .swagger-ui .copy-to-clipboard {
            background-color: var(--surface-dark-border);
            border: 1px solid #334155;
            border-radius: var(--radius-sm);
            right: 12px;
            bottom: 12px;
        }

        .swagger-ui .copy-to-clipboard button {
            background-color: transparent;
        }

        .swagger-ui .download-contents {
            background-color: var(--surface-dark-border);
            border: 1px solid #334155;
            color: #e2e8f0;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            padding: 5px 10px;
        }

        .swagger-ui .response-col_status {
            font-family: var(--font-mono);
            font-weight: 700;
            font-size: 0.86rem;
        }

        .swagger-ui .tab li {
            font-family: var(--font-sans);
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .swagger-ui .tab li.active {
            color: var(--text);
            font-weight: 700;
        }

        /* Authorize Modal */
        .swagger-ui .dialog-ux .modal-ux {
            border-radius: var(--radius-lg);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .swagger-ui .dialog-ux .modal-ux-header {
            background-color: var(--surface-soft);
            border-bottom: 1px solid var(--border);
            padding: 16px 24px;
        }

        .swagger-ui .dialog-ux .modal-ux-header h3 {
            font-family: var(--font-sans);
            font-weight: 700;
            color: var(--text);
        }

        .swagger-ui .dialog-ux .modal-ux-content {
            padding: 20px 24px;
        }

        .swagger-ui .auth-btn-wrapper .btn.modal-btn.auth {
            background-color: var(--brand-600);
            border-color: var(--brand-600);
            color: #ffffff;
        }

        .swagger-ui .auth-btn-wrapper .btn.modal-btn.auth:hover {
            background-color: var(--brand-700);
        }

        @media (max-width: 768px) {
            .portal-navbar-inner {
                padding: 0 14px;
            }
            .brand-divider,
            .portal-context-label {
                display: none;
            }
            .portal-container {
                margin: 20px auto 40px;
                padding: 0 14px;
            }
            .portal-intro {
                padding: 18px;
            }
            .portal-btn-hide-sm {
                display: none;
            }
            .swagger-ui .servers > label select {
                min-width: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header class="portal-navbar">
        <div class="portal-navbar-inner">
            <div class="portal-brand-group">
                <a class="brand-logo" href="{{ auth()->check() ? route('barang.index') : route('docs.api') }}">
                    <span class="brand-logo-icon" aria-hidden="true">
                        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                            <path d="m3.3 7 8.7 5 8.7-5" />
                            <path d="M12 22V12" />
                        </svg>
                    </span>
                    <span>LogistikKu</span>
                </a>
                <span class="brand-divider" aria-hidden="true"></span>
                <span class="portal-context-label">API Developer Portal</span>
                <span class="version-pill">{{ $apiVersion }}</span>
            </div>
            <nav class="portal-nav-actions" aria-label="Tautan Developer Portal">
                <a class="portal-btn" href="{{ $specUrl }}" download="logistikku-openapi-v1.yaml">
                    Unduh YAML
                </a>
                <button type="button" id="api-status-badge" class="status-badge is-loading portal-btn-hide-sm" data-endpoint="{{ route('api.v1.status') }}" title="Klik untuk cek ulang status API" aria-live="polite">
                    <span class="status-dot" aria-hidden="true"></span>
                    <span class="status-text">Memeriksa API...</span>
                </button>
                <a class="portal-btn portal-btn-primary" href="{{ auth()->check() ? route('barang.index') : route('login') }}">
                    {{ auth()->check() ? 'Kembali ke Aplikasi' : 'Login Aplikasi' }}
                </a>
            </nav>
        </div>
    </header>

    <main class="portal-container">
        <section class="portal-intro" aria-label="Ringkasan API LogistikKu">
            <div class="portal-intro-top">
                <div>
                    <h1>RESTful API LogistikKu</h1>
                    <p class="portal-intro-desc">
                        Integrasi terpadu katalog barang multi-gudang, mutasi &amp; transfer stok atomik, serta pengadaan Purchase Order &amp; GRN.
                    </p>
                </div>
            </div>

            <div class="portal-meta-pills">
                <span class="meta-pill">OpenAPI <code>3.0.3</code></span>
                <span class="meta-pill">Auth <code>Sanctum Bearer</code></span>
                <span class="meta-pill">Rate Limit <code>60 req/menit</code></span>
                <span class="meta-pill">Header <code>X-API-Version: {{ $apiVersion }}</code></span>
            </div>

            <details class="quickstart-drawer">
                <summary>
                    <svg class="quickstart-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m9 18 6-6-6-6" />
                    </svg>
                    Panduan Cepat Try It Out &amp; Akun Demo Seeder
                </summary>
                <div class="quickstart-grid">
                    <div class="quickstart-step">
                        <strong>1. Login API</strong>
                        Buka <code>POST /api/v1/auth/login</code>, klik <em>Try it out</em>, lalu kirim kredensial.
                    </div>
                    <div class="quickstart-step">
                        <strong>2. Salin Bearer Token</strong>
                        Salin nilai string pada properti <code>data.token</code> dari respon <code>200 OK</code>.
                    </div>
                    <div class="quickstart-step">
                        <strong>3. Klik Authorize</strong>
                        Tempel token ke dialog <strong>Authorize (BearerAuth)</strong> pada panel di bawah.
                    </div>
                    <div class="quickstart-step">
                        <strong>4. Uji Endpoint Privat</strong>
                        Coba <code>GET /api/v1/barang</code> atau <code>POST /api/v1/stok/adjust</code> lalu klik <em>Execute</em>.
                    </div>
                </div>
                <p class="quickstart-note">
                    Akun demo seeder lokal: <code>admin@logistikku.test</code>, <code>manager@logistikku.test</code>, atau <code>staff@logistikku.test</code> (password default: <code>password</code>).
                    Panduan lengkap &amp; contoh kode tersedia di <code>docs/api_integration_guide.md</code>.
                </p>
            </details>
        </section>

        <section class="swagger-shell" aria-label="Dokumentasi Endpoint Interaktif">
            <div id="swagger-ui"></div>
        </section>
    </main>

    <script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
    <script src="{{ asset('vendor/swagger-ui/swagger-ui-standalone-preset.js') }}"></script>
    <script>
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: @json($specUrl),
                dom_id: '#swagger-ui',
                deepLinking: true,
                persistAuthorization: true,
                displayRequestDuration: true,
                filter: true,
                tryItOutEnabled: true,
                docExpansion: 'list',
                defaultModelsExpandDepth: -1,
                syntaxHighlight: {
                    activated: true,
                    theme: 'obsidian'
                },
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                layout: 'BaseLayout',
                requestInterceptor: function (request) {
                    if (typeof request.url === 'string') {
                        request.url = request.url.replace(/([^:]\/)\/+/g, '$1');
                    }
                    request.headers = request.headers || {};
                    if (!request.headers.Accept) {
                        request.headers.Accept = 'application/json';
                    }
                    return request;
                }
            });

            var statusBadge = document.getElementById('api-status-badge');
            if (statusBadge && statusBadge.dataset.endpoint) {
                var statusText = statusBadge.querySelector('.status-text');
                var checkApiStatus = function () {
                    statusBadge.classList.remove('is-online', 'is-offline');
                    statusBadge.classList.add('is-loading');
                    if (statusText) {
                        statusText.textContent = 'Memeriksa API...';
                    }

                    var startedAt = performance.now();
                    fetch(statusBadge.dataset.endpoint, {
                        headers: { Accept: 'application/json' },
                        cache: 'no-store'
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status);
                            }
                            return response.json();
                        })
                        .then(function (payload) {
                            var latencyMs = Math.round(performance.now() - startedAt);
                            var isOperational = payload && payload.success === true && payload.data && payload.data.status === 'operational';
                            var version = (payload && payload.meta && payload.meta.version) ? payload.meta.version : @json($apiVersion);

                            statusBadge.classList.remove('is-loading', 'is-offline');
                            statusBadge.classList.add(isOperational ? 'is-online' : 'is-offline');
                            if (statusText) {
                                statusText.textContent = isOperational ? 'API Operational' : 'API Degraded';
                            }
                            statusBadge.title = 'Status: ' + (payload && payload.data ? payload.data.status : 'unknown') + ' · Versi: ' + version + ' · Latensi: ' + latencyMs + ' ms (klik untuk cek ulang)';
                        })
                        .catch(function () {
                            statusBadge.classList.remove('is-loading', 'is-online');
                            statusBadge.classList.add('is-offline');
                            if (statusText) {
                                statusText.textContent = 'API Unavailable';
                            }
                            statusBadge.title = 'Endpoint /api/v1/status tidak dapat dijangkau (klik untuk cek ulang)';
                        });
                };

                checkApiStatus();
                statusBadge.addEventListener('click', checkApiStatus);
            }
        });
    </script>
</body>
</html>
