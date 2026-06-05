<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo e(csrf_token(), false); ?>">
    <title><?php echo $__env->yieldContent('title', 'Email Validator'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Syne:wght@700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #0a0a0f;
            --surface: #111118;
            --border: #1e1e2e;
            --accent: #00e5a0;
            --accent2: #ff6b6b;
            --accent3: #ffd93d;
            --text: #e8e8f0;
            --muted: #6b6b8a;
            --valid: #00e5a0;
            --invalid: #ff6b6b;
            --acceptall: #ffd93d;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Mono', monospace;
            min-height: 100vh;
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(0,229,160,0.04) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(255,107,107,0.04) 0%, transparent 60%);
        }

        /* NAV */
        nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.5rem 3rem;
            border-bottom: 1px solid var(--border);
            position: sticky; top: 0;
            background: rgba(10,10,15,0.95);
            backdrop-filter: blur(12px);
            z-index: 100;
        }

        .nav-logo {
            font-family: 'Syne', sans-serif;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--accent);
            letter-spacing: -0.5px;
            text-decoration: none;
        }

        .nav-logo span { color: var(--text); }

        .nav-links { display: flex; gap: 0.5rem; }

        .nav-link {
            padding: 0.5rem 1.2rem;
            border-radius: 6px;
            text-decoration: none;
            color: var(--muted);
            font-size: 0.85rem;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .nav-link:hover, .nav-link.active {
            color: var(--text);
            background: var(--surface);
            border-color: var(--border);
        }

        .nav-link.active { color: var(--accent); border-color: rgba(0,229,160,0.3); }

        /* MAIN */
        main { max-width: 860px; margin: 0 auto; padding: 4rem 2rem; }

        /* CARDS */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 2.5rem;
            margin-bottom: 1.5rem;
        }

        .card-label {
            font-size: 0.7rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 1rem;
        }

        h1 {
            font-family: 'Syne', sans-serif;
            font-size: 2.8rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 0.5rem;
            letter-spacing: -1px;
        }

        h1 .accent { color: var(--accent); }

        .subtitle {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }

        /* INPUTS */
        .input-row {
            display: flex;
            gap: 0.75rem;
        }

        input[type="email"], input[type="text"] {
            flex: 1;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.9rem 1.2rem;
            color: var(--text);
            font-family: 'DM Mono', monospace;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }

        input[type="email"]:focus, input[type="text"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0,229,160,0.08);
        }

        input::placeholder { color: var(--muted); }

        /* BUTTONS */
        .btn {
            padding: 0.9rem 1.8rem;
            border-radius: 10px;
            border: none;
            font-family: 'DM Mono', monospace;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--accent);
            color: #0a0a0f;
        }

        .btn-primary:hover { background: #00ccaa; transform: translateY(-1px); }
        .btn-primary:active { transform: translateY(0); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        .btn-secondary {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-secondary:hover { border-color: var(--accent); color: var(--accent); }

        /* RESULT BOX */
        .result-box {
            margin-top: 1.5rem;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid var(--border);
            display: none;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }

        .result-box.valid   { border-color: rgba(0,229,160,0.4);  background: rgba(0,229,160,0.05);  }
        .result-box.invalid { border-color: rgba(255,107,107,0.4); background: rgba(255,107,107,0.05); }
        .result-box.acceptall { border-color: rgba(255,217,61,0.4); background: rgba(255,217,61,0.05); }

        .result-status {
            font-family: 'Syne', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 0.4rem;
        }

        .valid    .result-status { color: var(--valid); }
        .invalid  .result-status { color: var(--invalid); }
        .acceptall .result-status { color: var(--acceptall); }

        .result-detail { color: var(--muted); font-size: 0.85rem; }
        .result-email  { color: var(--text); font-size: 0.9rem; margin-bottom: 0.8rem; opacity: 0.7; }

        /* STATUS BADGE */
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-valid     { background: rgba(0,229,160,0.15);   color: var(--valid);    border: 1px solid rgba(0,229,160,0.3); }
        .badge-invalid   { background: rgba(255,107,107,0.15); color: var(--invalid);  border: 1px solid rgba(255,107,107,0.3); }
        .badge-acceptall { background: rgba(255,217,61,0.15);  color: var(--acceptall); border: 1px solid rgba(255,217,61,0.3); }

        /* PROGRESS */
        .progress-wrap { margin-top: 1.5rem; display: none; }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 0.5rem;
        }

        .progress-track {
            height: 6px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--accent), #00ccff);
            border-radius: 99px;
            width: 0%;
            transition: width 0.4s ease;
        }

        /* FILE UPLOAD */
        .file-drop {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 2.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .file-drop:hover, .file-drop.dragover {
            border-color: var(--accent);
            background: rgba(0,229,160,0.03);
        }

        .file-drop input[type="file"] {
            position: absolute; inset: 0;
            opacity: 0; cursor: pointer;
            width: 100%; height: 100%;
        }

        .file-drop-icon { font-size: 2rem; margin-bottom: 0.75rem; }

        .file-drop-text { color: var(--muted); font-size: 0.9rem; }

        .file-drop-text strong { color: var(--accent); }

        .file-name-display {
            margin-top: 0.75rem;
            font-size: 0.85rem;
            color: var(--text);
            display: none;
        }

        /* TABLE */
        .results-table-wrap {
            margin-top: 1.5rem;
            overflow-x: auto;
            display: none;
        }

        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }

        th {
            text-align: left;
            padding: 0.75rem 1rem;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(30,30,46,0.5);
            color: var(--text);
        }

        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }

        /* DIVIDER */
        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 2rem 0;
        }

        /* LOADING SPINNER */
        .spinner {
            display: inline-block;
            width: 14px; height: 14px;
            border: 2px solid rgba(0,229,160,0.3);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
    <?php echo $__env->yieldContent('styles'); ?>
</head>
<body>
<nav>
    <a href="/" class="nav-logo">catch<span>.</span>validate</a>
    <div class="nav-links">
        <a href="/"     class="nav-link <?php echo $__env->yieldContent('nav_single'); ?>">Single</a>
        <a href="/bulk" class="nav-link <?php echo $__env->yieldContent('nav_bulk'); ?>">Bulk CSV</a>
    </div>
</nav>
<main>
    <?php echo $__env->yieldContent('content'); ?>
</main>
<script>
   
    const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
</script>
<?php echo $__env->yieldContent('scripts'); ?>
</body>
</html>
<?php /**PATH /Users/yasser/Desktop/Clients/SPRichards/Phase 2 Delivrability/Version 3/emailvalidator/resources/views/layout.blade.php ENDPATH**/ ?>