<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $survey->title }}</title>
    <style>
        :root {
            --survey-primary: {{ $theme['primary'] ?? '#6366f1' }};
            --survey-background: {{ $theme['background'] ?? '#ffffff' }};
            --survey-surface: {{ $theme['surface'] ?? '#f9fafb' }};
            --survey-text: {{ $theme['text'] ?? '#111827' }};
            --survey-text-muted: {{ $theme['text_muted'] ?? '#6b7280' }};
            --survey-border: {{ $theme['border'] ?? '#e5e7eb' }};
            --survey-font: {{ $theme['font_family'] ?? 'system-ui, sans-serif' }};
            --survey-radius: {{ $theme['radius'] ?? '0.5rem' }};
        }

        body {
            align-items: center;
            background: var(--survey-background);
            color: var(--survey-text);
            display: flex;
            font-family: var(--survey-font);
            justify-content: center;
            margin: 0;
            min-height: 100vh;
            padding: 24px;
        }

        main {
            background: var(--survey-surface);
            border: 1px solid var(--survey-border);
            border-radius: var(--survey-radius);
            max-width: 560px;
            padding: 32px;
            text-align: center;
            width: 100%;
        }

        h1 {
            font-size: 24px;
            margin: 0 0 12px;
        }

        p {
            color: var(--survey-text-muted);
            line-height: 1.7;
            margin: 0;
            white-space: pre-line;
        }
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
</main>
</body>
</html>
