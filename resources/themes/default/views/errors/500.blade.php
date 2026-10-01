{{-- 500: self-contained (no database, no layout) so it renders even when the app is broken. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Something went wrong</title>
    <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#0f172a}
        main{max-width:520px;padding:40px 24px;text-align:center}
        .code{font-size:14px;font-weight:700;letter-spacing:.12em;color:#64748b}
        h1{font-size:28px;line-height:1.2;margin:.3em 0 .5em}
        p{color:#475569}
        a{display:inline-block;margin-top:16px;padding:12px 22px;border-radius:10px;background:#0f172a;color:#fff;text-decoration:none;font-weight:600}
    </style>
</head>
<body>
<main>
    <p class="code">ERROR 500</p>
    <h1>Sorry, something went wrong on our side</h1>
    <p>Please try again in a moment. If the problem continues, get in touch and we’ll help you complete your order.</p>
    <a href="/">Back to the home page</a>
</main>
</body>
</html>
