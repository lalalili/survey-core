    <style>
        :root {
            --survey-primary: {{ $theme['primary'] ?? '#6366f1' }};
            --survey-accent: {{ $theme['accent'] ?? '#f59e0b' }};
            --survey-background: {{ $theme['background'] ?? '#ffffff' }};
            --survey-surface: {{ $theme['surface'] ?? '#f9fafb' }};
            --survey-text: {{ $theme['text'] ?? '#111827' }};
            --survey-text-muted: {{ $theme['text_muted'] ?? '#6b7280' }};
            --survey-border: {{ $theme['border'] ?? '#e5e7eb' }};
            --survey-font: {{ $theme['font_family'] ?? 'system-ui, sans-serif' }};
            --survey-radius: {{ $theme['radius'] ?? '0.5rem' }};
        }

        body {
            background: var(--survey-background);
            color: var(--survey-text);
            font-family: var(--survey-font);
        }

        .survey-themed-primary {
            background: var(--survey-primary) !important;
            border-color: var(--survey-primary) !important;
        }

        /* 輔助色：用於次要動作（如「上一頁」、感謝頁「繼續」），與主色的主要動作區隔。 */
        .survey-themed-accent {
            background: var(--survey-accent) !important;
            border-color: var(--survey-accent) !important;
            color: #fff !important;
        }

        .survey-themed-accent-outline {
            border-color: var(--survey-accent) !important;
            color: var(--survey-accent) !important;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }

        /* 觸控目標：放大 radio/checkbox 至 ≥20px，並讓矩陣格子整格可點，
           以接近 WCAG 2.5.8（最小 24×24）。.sr-only 視覺隱藏的選項不受影響。 */
        .survey-field input[type="radio"]:not(.sr-only),
        .survey-field input[type="checkbox"]:not(.sr-only) {
            width: 20px;
            height: 20px;
            accent-color: var(--survey-primary);
        }
        .survey-matrix td,
        .survey-field table[aria-label] td {
            min-width: 44px;
            min-height: 44px;
        }

        /* Skip link：鍵盤使用者可跳過頁首直達題目，平時隱藏於畫面上方，聚焦時滑入。 */
        .survey-skip-link {
            position: absolute;
            left: 8px;
            top: -48px;
            background: var(--survey-primary);
            color: #fff;
            padding: 8px 14px;
            border-radius: 6px;
            z-index: 1000;
            transition: top 150ms ease;
        }
        .survey-skip-link:focus {
            top: 8px;
        }

        /* 鍵盤焦點可見性：NPS/評分以 sr-only radio 實作，原生 focus ring 被隱藏，
           改在可見的 pip/label 上顯示焦點外框，確保 keyboard-only 使用者可辨識位置。 */
        .survey-nps-label:has(.survey-nps-radio:focus-visible) .survey-nps-pip,
        .survey-rating-star-label:has(.survey-rating-radio:focus-visible),
        .survey-field input[type="radio"]:not(.sr-only):focus-visible,
        .survey-field input[type="checkbox"]:not(.sr-only):focus-visible {
            outline: 2px solid var(--survey-primary);
            outline-offset: 2px;
            border-radius: 4px;
        }

        .survey-file-input {
            display: none;
        }

        .survey-file-dropzone {
            width: 100%;
            min-height: 180px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
            border: 1px dashed var(--survey-border);
            border-radius: var(--survey-radius);
            background: #fff;
            color: var(--survey-text-muted);
            text-align: center;
            cursor: pointer;
            transition: border-color 0.15s, background-color 0.15s, box-shadow 0.15s;
        }

        .survey-file-dropzone:hover,
        .survey-file-dropzone.is-dragging {
            border-color: var(--survey-primary);
            background: color-mix(in srgb, var(--survey-primary) 7%, #fff);
            box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--survey-primary) 16%, transparent);
        }

        .survey-file-dropzone.is-uploaded {
            border-style: solid;
        }

        .survey-file-icon {
            color: var(--survey-primary);
            font-size: 2.5rem;
            line-height: 1;
        }

        .survey-file-title {
            color: var(--survey-primary);
            font-size: 1rem;
            font-weight: 700;
        }

        .survey-file-limit {
            color: var(--survey-text);
            font-size: 1rem;
        }

        .survey-file-format,
        .survey-file-status {
            color: var(--survey-text-muted);
            font-size: 0.875rem;
        }

        .survey-constant-sum-summary {
            align-items: center;
            background: color-mix(in srgb, var(--survey-primary) 6%, #fff);
            border: 1px solid color-mix(in srgb, var(--survey-primary) 18%, var(--survey-border));
            border-radius: var(--survey-radius);
            color: var(--survey-text-muted);
            display: flex;
            flex-wrap: wrap;
            font-size: 0.75rem;
            gap: 0.5rem;
            margin-top: 0.5rem;
            padding: 0.5rem 0.75rem;
        }

        .survey-constant-sum-summary strong {
            color: var(--survey-text);
            font-weight: 600;
            margin-left: auto;
        }

        .survey-constant-sum-summary[data-status="matched"] {
            background: oklch(96% 0.04 145);
            border-color: oklch(82% 0.09 145);
        }

        .survey-constant-sum-summary[data-status="matched"] strong {
            color: oklch(42% 0.13 145);
        }

        .survey-constant-sum-summary[data-status="over"] {
            background: oklch(96% 0.03 27);
            border-color: oklch(85% 0.06 27);
        }

        .survey-constant-sum-summary[data-status="over"] strong {
            color: oklch(58% 0.18 27);
        }

        .survey-rating-stars {
            display: flex;
            gap: clamp(0.125rem, 1.2vw, 0.25rem);
            margin-top: 0.25rem;
            flex-wrap: nowrap;
            container-type: inline-size;
        }

        .survey-rating-star-label {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            gap: 0.125rem;
            flex: 1 1 0;
            min-width: 0;
            max-width: 2.5rem;
            cursor: pointer;
            line-height: 1;
        }

        .survey-rating-star-number {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.6875rem;
            font-weight: 700;
            line-height: 1;
            color: #9ca3af;
        }

        .survey-rating-star-icon {
            display: inline-block;
            /* 每個圖示佔格子固定比例，5 級與 10 級都能一行不換行並依容器寬度縮放 */
            font-size: clamp(0.95rem, calc(62cqw / var(--rating-count, 5)), 2rem);
            color: #d1d5db;
            transition: color 120ms, transform 120ms, filter 120ms;
            user-select: none;
        }

        .survey-rating-star-label:hover .survey-rating-star-icon,
        .survey-rating-star-label.hovered .survey-rating-star-icon {
            transform: scale(1.18);
        }

        .survey-rating-star-label.filled .survey-rating-star-icon {
            color: var(--survey-accent);
        }

        .survey-rating-star-label.hovered .survey-rating-star-icon {
            color: color-mix(in srgb, var(--survey-accent) 78%, #fff);
        }

        .survey-rating-star-label.filled .survey-rating-star-number,
        .survey-rating-star-label.hovered .survey-rating-star-number {
            color: var(--survey-accent);
        }

        /* thumb 為原生彩色 emoji，color 對其無效，改用 grayscale + opacity 區分未選中狀態 */
        .survey-rating-star-label.shape-thumb .survey-rating-star-icon {
            filter: grayscale(1) opacity(0.35);
        }

        .survey-rating-star-label.shape-thumb.filled .survey-rating-star-icon,
        .survey-rating-star-label.shape-thumb.hovered .survey-rating-star-icon {
            filter: none;
        }

        .survey-rating-star-label.shape-thumb.popping .survey-rating-star-icon {
            transform: scale(1.35);
        }

        .survey-nps-wrap {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
            margin-top: 0.25rem;
        }

        .survey-nps-row {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }

        .survey-nps-label {
            flex: 1;
            min-width: 2.25rem;
            cursor: pointer;
        }

        .survey-nps-pip {
            display: block;
            text-align: center;
            padding: 0.5rem 0;
            border: 1.5px solid #d1d5db;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
            font-weight: 600;
            font-family: ui-monospace, monospace;
            color: #6b7280;
            background: #fff;
            transition: all 130ms;
            user-select: none;
        }

        .survey-nps-label:hover .survey-nps-pip {
            border-color: var(--survey-primary);
            background: #eef2ff;
            color: var(--survey-primary);
        }

        .survey-nps-radio:checked + .survey-nps-pip {
            border-color: var(--survey-primary);
            background: var(--survey-primary);
            color: #fff;
        }

        .survey-nps-pip.red { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
        .survey-nps-pip.yellow { background: #fffbeb; border-color: #fde68a; color: #b45309; }
        .survey-nps-pip.green { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
        .survey-nps-radio:checked + .survey-nps-pip.red { background: #dc2626; border-color: #dc2626; color: #fff; }
        .survey-nps-radio:checked + .survey-nps-pip.yellow { background: #d97706; border-color: #d97706; color: #fff; }
        .survey-nps-radio:checked + .survey-nps-pip.green { background: #16a34a; border-color: #16a34a; color: #fff; }

        .survey-nps-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.75rem;
            color: #4b5563;
        }

        #progress-bar {
            accent-color: var(--survey-primary);
        }

        #progress-bar::-webkit-progress-bar {
            background: var(--survey-border);
            border-radius: 999px;
        }

        #progress-bar::-webkit-progress-value {
            background: var(--survey-primary);
            border-radius: 999px;
        }

        #progress-bar::-moz-progress-bar {
            background: var(--survey-primary);
            border-radius: 999px;
        }

        .progress-step.is-active {
            background: var(--survey-primary) !important;
        }

        .survey-linear-scale {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .survey-linear-scale-value {
            align-self: flex-start;
            min-width: 1.75rem;
            padding: 0.125rem 0.5rem;
            border-radius: 999px;
            background: color-mix(in srgb, var(--survey-primary) 12%, #fff);
            color: var(--survey-primary);
            font-size: 0.75rem;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            line-height: 1.45;
            text-align: center;
        }

        .survey-linear-scale-input {
            width: 100%;
            height: 1.5rem;
            -webkit-appearance: none;
            appearance: none;
            accent-color: var(--survey-primary);
            background: transparent;
            --survey-range-fill: 0%;
        }

        .survey-linear-scale-input::-webkit-slider-runnable-track {
            height: 0.375rem;
            border-radius: 999px;
            background: linear-gradient(to right, var(--survey-primary) 0 var(--survey-range-fill), #e5e7eb var(--survey-range-fill) 100%);
        }

        .survey-linear-scale-input::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 1.125rem;
            height: 1.125rem;
            margin-top: -0.375rem;
            border-radius: 999px;
            background: #fff;
            border: 0.1875rem solid var(--survey-primary);
            box-shadow: 0 0.125rem 0.5rem rgba(15, 23, 42, 0.14);
        }

        .survey-linear-scale-input::-moz-range-track {
            height: 0.375rem;
            border-radius: 999px;
            background: #e5e7eb;
        }

        .survey-linear-scale-input::-moz-range-progress {
            height: 0.375rem;
            border-radius: 999px;
            background: var(--survey-primary);
        }

        .survey-linear-scale-input::-moz-range-thumb {
            width: 0.875rem;
            height: 0.875rem;
            border-radius: 999px;
            background: #fff;
            border: 0.1875rem solid var(--survey-primary);
            box-shadow: 0 0.125rem 0.5rem rgba(15, 23, 42, 0.14);
        }

        .survey-ranking-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .survey-ranking-item {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            border: 1px solid var(--survey-border);
            border-radius: var(--survey-radius);
            background: #fff;
            padding: 0.625rem 0.75rem;
            transition: border-color 120ms, box-shadow 120ms, transform 120ms;
        }

        .survey-ranking-item[draggable="true"] {
            cursor: grab;
        }

        .survey-ranking-item.is-dragging {
            opacity: 0.55;
            transform: scale(0.99);
        }

        .survey-ranking-position {
            min-width: 1.75rem;
            border-radius: 999px;
            background: var(--survey-surface);
            color: var(--survey-text-muted);
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.75rem;
            text-align: center;
        }

        .survey-ranking-label {
            flex: 1;
            font-size: 0.875rem;
            color: var(--survey-text);
        }

        .survey-ranking-handle,
        .survey-ranking-move {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2rem;
            height: 2rem;
            border: 1px solid var(--survey-border);
            border-radius: 0.375rem;
            background: #fff;
            color: var(--survey-text-muted);
            font-size: 0.875rem;
        }

        .survey-ranking-move:not(:disabled) {
            cursor: pointer;
        }

        .survey-ranking-move:disabled {
            opacity: 0.35;
        }

        .survey-rich-content img {
            max-width: 100%;
            height: auto;
            border-radius: 6px;
            display: block;
        }

        .survey-rich-content h2 {
            margin: 0.75rem 0 0.375rem;
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.3;
        }

        .survey-rich-content h3 {
            margin: 0.625rem 0 0.25rem;
            font-size: 1.25rem;
            font-weight: 600;
            line-height: 1.35;
        }

        .survey-rich-content .survey-video {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            margin: 8px 0;
        }

        .survey-rich-content .survey-video iframe {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }
    </style>
